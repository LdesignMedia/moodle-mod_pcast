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
 * RSS tests for mod_pcast.
 *
 * @package   mod_pcast
 * @copyright 2026 Ldesign Media
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pcast;
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/pcast/lib.php');
require_once($CFG->dirroot . '/mod/pcast/rsslib.php');
require_once($CFG->libdir . '/rsslib.php');

/**
 * RSS tests for mod_pcast.
 *
 * @package   mod_pcast
 * @copyright 2026 Ldesign Media
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rsslib_test extends \advanced_testcase {
    /**
     * Build a podcast with RSS enabled and one categorised HTML episode.
     *
     * @return array [$pcast, $cm, $context, $episode, $author]
     */
    protected function create_rss_podcast(): array {
        global $DB;

        set_config('enablerssfeeds', 1);
        set_config('enablerssfeeds', 1, 'mod_pcast');
        set_config('allowhtmlinsummary', 1, 'mod_pcast');

        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', [
            'course' => $course->id,
            'rssepisodes' => 5,
            'enablerssfeed' => 1,
            'userscancategorize' => 1,
            'subtitle' => 'A subtitle for the feed',
        ]);
        $cm = get_coursemodule_from_instance('pcast', $pcast->id);
        $context = \context_module::instance($cm->id);

        // The feed only includes episodes whose author is an enrolled member of the course.
        $author = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($author->id, $course->id, 'editingteacher');
        $this->setUser($author);

        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');
        $episode = $generator->create_content($pcast, [
            'name' => 'RSS episode',
            'summary' => "<p>First paragraph</p>\n<p>Second paragraph</p>",
            'summaryformat' => FORMAT_HTML,
        ]);
        // Arts, seeded by the plugin install.
        $DB->set_field('pcast_episodes', 'topcategory', 1, ['id' => $episode->id]);
        $DB->set_field('pcast_episodes', 'approved', 1, ['id' => $episode->id]);

        return [$pcast, $cm, $context, $episode, $author];
    }

    /**
     * Test that the SQL feeding the feed carries the summary's format and trust flag.
     *
     * Regression test: neither was selected, which is why the feed builder had to hard code them.
     */
    public function test_rss_sql_selects_summary_format(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        global $DB;

        [$pcast] = $this->create_rss_podcast();
        $full = (object) (array) $pcast;

        foreach ([0, 1] as $displayauthor) {
            $full->displayauthor = $displayauthor;
            $records = $DB->get_records_sql(pcast_rss_get_sql($full));
            $record = reset($records);

            $this->assertNotFalse($record, 'The feed query should return the episode.');
            $this->assertObjectHasProperty('episodesummaryformat', $record);
            $this->assertObjectHasProperty('summarytrust', $record);
            $this->assertEquals(FORMAT_HTML, $record->episodesummaryformat);
        }
    }

    /**
     * Test that an HTML summary is rendered as HTML, and that the episode category is emitted.
     *
     * Regression test: format_text() was called with the string 'HTML', which is not FORMAT_HTML.
     * On Moodle 5.x the default branch of that switch throws a coding_exception, so the feed did
     * not merely render badly, it failed outright. Separately the category guard tested a property
     * that was never set, so no <category> was ever written.
     */
    public function test_rss_feed_renders_html_and_emits_category(): void {
        global $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$pcast, , $context] = $this->create_rss_podcast();

        $token = rss_get_token($USER->id);
        $path = pcast_rss_get_feed($context, [$context->id, $token, 'mod_pcast', $pcast->id, 0]);

        $this->assertNotNull($path, 'The feed should have been generated.');
        $xml = file_get_contents($path);

        // FORMAT_MOODLE would have inserted a line break between the two paragraphs.
        $this->assertStringNotContainsString('br /', $xml);
        $this->assertStringContainsString('First paragraph', $xml);
        $this->assertStringContainsString('<category>', $xml);
    }

    /**
     * Test that front page episodes are included regardless of group membership.
     *
     * Note: SITEID is defined from the database (define('SITEID', $SITE->id)) and is therefore a
     * string, so the original identity comparison did match. This pins that behaviour now that
     * both sides are cast, so neither side silently changing type can break front page feeds.
     */
    public function test_rss_items_include_front_page_episodes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$pcast, , $context, $episode] = $this->create_rss_podcast();

        // An author who is not enrolled, so the membership branch cannot match.
        $outsider = $this->getDataGenerator()->create_user();

        $item = (object) [
            'id' => $episode->id,
            'pcastid' => $pcast->id,
            'userid' => $outsider->id,
            'course' => (string) SITEID,
            'title' => 'Front page episode',
            'link' => 'https://example.com/episode',
            'pubdate' => time(),
            'description' => 'A front page description',
        ];

        foreach ([(string) SITEID, (int) SITEID] as $courseid) {
            $item->course = $courseid;
            $result = pcast_rss_add_items($context, [$item]);
            $this->assertStringContainsString(
                'Front page episode',
                $result,
                'Front page episodes must be included whatever type the course id has.'
            );
        }
    }

    /**
     * Test that the subscription file carries the podcast subtitle.
     *
     * Regression test: the guard read the subtitle from the category lookup, which only ever
     * carries top and nested categories, so the subtitle was never written.
     */
    public function test_subscription_file_includes_subtitle(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        [$pcast] = $this->create_rss_podcast();

        $result = pcast_build_pcast_file($pcast, 'https://example.com/feed');

        $this->assertStringContainsString('A subtitle for the feed', $result);
    }

    /**
     * Test that a feed carrying a category with an ampersand is still well formed XML.
     *
     * Regression test: category names were concatenated straight into an XML attribute. Five of
     * the categories seeded at install contain an ampersand, for example 'Kids & Family', so any
     * conforming reader rejected the whole document.
     */
    public function test_rss_feed_with_ampersand_category_is_valid_xml(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$pcast, , $context] = $this->create_rss_podcast();

        // Point the podcast itself at a seeded category containing an ampersand.
        $arts = $DB->get_record('pcast_itunes_categories', ['name' => 'Kids & Family']);
        $this->assertNotFalse($arts, 'Expected the seeded category to exist.');
        $DB->set_field('pcast', 'topcategory', $arts->id, ['id' => $pcast->id]);
        $DB->set_field('pcast', 'enablerssitunes', 1, ['id' => $pcast->id]);

        $token = rss_get_token($USER->id);
        $path = pcast_rss_get_feed($context, [$context->id, $token, 'mod_pcast', $pcast->id, 0]);
        $xml = file_get_contents($path);

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($document, 'The feed must parse as XML.');
        $this->assertSame([], $errors, 'The feed must not contain XML errors.');
    }

    /**
     * Test that the explicit flag is published the way the form labels it.
     *
     * Note: this pins the feed contract rather than the fix itself. The defect was in the form
     * labels, which a unit test cannot observe: the select labelled 0 as 'Yes' while the feed
     * publishes 0 as 'no', so a podcast marked explicit was published as not explicit. The labels
     * were corrected to match this mapping, so this test guards against the mapping being changed
     * instead, which would flip the meaning of every stored row.
     */
    public function test_rss_publishes_explicit_flag_matching_the_form(): void {
        global $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();

        [$pcast, , $context] = $this->create_rss_podcast();
        $DB->set_field('pcast', 'enablerssitunes', 1, ['id' => $pcast->id]);

        foreach ([0 => 'no', 1 => 'yes', 2 => 'clean'] as $stored => $expected) {
            $DB->set_field('pcast', 'explicit', $stored, ['id' => $pcast->id]);
            pcast_rss_delete_file($DB->get_record('pcast', ['id' => $pcast->id], '*', MUST_EXIST));

            $token = rss_get_token($USER->id);
            $path = pcast_rss_get_feed($context, [$context->id, $token, 'mod_pcast', $pcast->id, 0]);
            $xml = file_get_contents($path);

            $this->assertStringContainsString(
                '<itunes:explicit>' . $expected . '</itunes:explicit>',
                $xml,
                "Stored value {$stored} should publish as {$expected}."
            );
        }
    }
}
