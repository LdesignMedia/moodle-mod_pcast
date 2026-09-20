<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Backup and restore tests for mod_pcast.
 *
 * @package   mod_pcast
 * @copyright 2026 Ldesign Media
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pcast;
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/phpunit/classes/restore_date_testcase.php');

/**
 * Backup and restore tests for mod_pcast.
 *
 * Extends restore_date_testcase purely for its backup_and_restore() helper.
 *
 * @package   mod_pcast
 * @copyright 2026 Ldesign Media
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_restore_test extends \restore_date_testcase {
    /**
     * Build a course containing a podcast with one episode, files in both per-episode areas and a
     * view counter.
     *
     * @return \stdClass The course containing the podcast.
     */
    protected function create_fixture(): \stdClass {
        global $DB;

        [$course, $pcast] = $this->create_course_and_module('pcast', [
            'enablerssitunes' => 1,
            'episodesperpage' => 25,
            'rssepisodes' => 5,
        ]);
        $context = \context_module::instance($pcast->cmid);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');
        $episode = $generator->create_content($pcast, ['name' => 'Episode to restore']);

        $fs = get_file_storage();
        foreach (['episode', 'summary'] as $area) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_pcast',
                'filearea' => $area,
                'itemid' => $episode->id,
                'filepath' => '/',
                'filename' => $area . '.bin',
            ], 'contents of ' . $area);
        }

        $viewer = $this->getDataGenerator()->create_user();
        $DB->insert_record('pcast_views', (object) [
            'episodeid' => $episode->id,
            'userid' => $viewer->id,
            'views' => 7,
            'lastview' => time(),
        ]);

        return $course;
    }

    /**
     * Fetch the restored activity, its episode and its context.
     *
     * @param int $newcourseid The restored course id.
     * @return array [$pcast, $episode, $context]
     */
    protected function get_restored(int $newcourseid): array {
        global $DB;

        $pcast = $DB->get_record('pcast', ['course' => $newcourseid], '*', MUST_EXIST);
        $episode = $DB->get_record('pcast_episodes', ['pcastid' => $pcast->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('pcast', $pcast->id);

        return [$pcast, $episode, \context_module::instance($cm->id)];
    }

    /**
     * Test that the iTunes flag and the episodes-per-page setting survive a restore.
     *
     * Regression test: the backup element list named 'enableitunes', which is not a column, and
     * omitted 'episodesperpage' entirely, so both silently reverted to their defaults.
     */
    public function test_backup_restore_preserves_settings(): void {
        $course = $this->create_fixture();
        [$pcast] = $this->get_restored($this->backup_and_restore($course));

        $this->assertEquals(1, $pcast->enablerssitunes);
        $this->assertEquals(25, $pcast->episodesperpage);
    }

    /**
     * Test that files embedded in an episode summary survive a restore.
     *
     * Regression test: the summary file area was neither annotated on backup nor related on
     * restore, so only the media file came across.
     */
    public function test_backup_restore_preserves_summary_files(): void {
        $course = $this->create_fixture();
        [, $episode, $context] = $this->get_restored($this->backup_and_restore($course));

        $fs = get_file_storage();
        $this->assertCount(1, $fs->get_area_files($context->id, 'mod_pcast', 'episode', $episode->id, '', false));
        $this->assertCount(1, $fs->get_area_files($context->id, 'mod_pcast', 'summary', $episode->id, '', false));
    }

    /**
     * Test that links inside an episode summary are decoded on restore.
     *
     * Regression test: Moodle encodes /mod/pcast/view.php links found anywhere in the backup, but
     * define_decode_contents() only declared the activity intro. A link inside an episode summary
     * was therefore restored as a literal $@PCASTVIEWBYID*n@$ token.
     */
    public function test_backup_restore_decodes_summary_links(): void {
        global $DB, $CFG;

        $course = $this->create_fixture();
        $pcast = $DB->get_record('pcast', ['course' => $course->id], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('pcast', $pcast->id);

        // Put a link to this activity inside the episode summary.
        $episode = $DB->get_record('pcast_episodes', ['pcastid' => $pcast->id], '*', MUST_EXIST);
        $DB->set_field(
            'pcast_episodes',
            'summary',
            '<a href="' . $CFG->wwwroot . '/mod/pcast/view.php?id=' . $cm->id . '">See the podcast</a>',
            ['id' => $episode->id]
        );

        [, $newepisode] = $this->get_restored($this->backup_and_restore($course));

        $this->assertStringNotContainsString('$@PCASTVIEWBYID', $newepisode->summary);
        $this->assertStringContainsString('/mod/pcast/view.php?id=', $newepisode->summary);
    }

    /**
     * Test that restored view counters point at their own episode.
     *
     * Regression test: restore mapped pcast_views rows through the view row's own id rather than
     * its parent episode, so the counters pointed at the wrong episode or at nothing.
     */
    public function test_backup_restore_maps_views_to_parent_episode(): void {
        global $DB;

        $course = $this->create_fixture();
        [, $episode] = $this->get_restored($this->backup_and_restore($course));

        $this->assertEquals(1, $DB->count_records('pcast_views', ['episodeid' => $episode->id]));
        $this->assertEquals(7, $DB->get_field('pcast_views', 'views', ['episodeid' => $episode->id]));
    }
}
