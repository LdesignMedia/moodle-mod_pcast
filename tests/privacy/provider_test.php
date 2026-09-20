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
 * Privacy provider tests.
 *
 * @package     mod_pcast
 * @copyright   2018 Stephen Bourget
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace mod_pcast\privacy;
defined('MOODLE_INTERNAL') || die();

use core_privacy\local\metadata\collection;
use core_privacy\local\request\deletion_criteria;
use mod_pcast\privacy\provider;

global $CFG;
require_once($CFG->dirroot . '/comment/lib.php');

/**
 * Privacy provider tests class.
 *
 * @package    mod_pcast
 * @copyright 2018 Simey Lameze <simey@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var stdClass The student object. */
    protected $student;

    /** @var stdClass The teacher object. */
    protected $teacher;

    /** @var stdClass The pcast object. */
    protected $pcast;

    /** @var stdClass The course object. */
    protected $course;

    /** @var stdClass The plugin generator object. */
    protected $plugingenerator;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        global $DB;
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $this->course = $course;

        $this->plugingenerator = $generator->get_plugin_generator('mod_pcast');

        // The pcast activity the user will answer.
        $pcast = $this->plugingenerator->create_instance(['course' => $course->id]);
        $this->pcast = $pcast;

        $cm = get_coursemodule_from_instance('pcast', $pcast->id);
        $context = \context_module::instance($cm->id);

        // Create a student which will add an episode to a pcast.
        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');
        $this->student = $student;

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->teacher = $teacher;

        $this->setUser($student->id);
        $pe1 = $this->plugingenerator->create_content($pcast, ['concept' => 'first', 'approved' => 1]);

        // Student create a comment on a pcast episode.
        $this->setUser($student);
        $comment = $this->get_comment_object($context, $pe1->id);
        $comment->add('Hello, it\'s me!');

        // Attach tags.
        \core_tag_tag::set_item_tags('mod_pcast', 'pcast_episodes', $pe1->id, $context, ['Beer', 'Golf']);
    }

    /**
     * Test for provider::get_metadata().
     */
    public function test_get_metadata(): void {
        $collection = new collection('mod_pcast');
        $newcollection = provider::get_metadata($collection);
        $itemcollection = $newcollection->get_collection();
        $this->assertCount(7, $itemcollection);

        $table = reset($itemcollection);
        $this->assertEquals('pcast_episodes', $table->get_name());

        $privacyfields = $table->get_privacy_fields();
        $this->assertArrayHasKey('pcastid', $privacyfields);
        $this->assertArrayHasKey('name', $privacyfields);
        $this->assertArrayHasKey('summary', $privacyfields);
        $this->assertArrayHasKey('mediafile', $privacyfields);
        $this->assertArrayHasKey('userid', $privacyfields);
        $this->assertArrayHasKey('timemodified', $privacyfields);

        $this->assertEquals('privacy:metadata:pcast_episodes', $table->get_summary());
    }

    /**
     * Test for provider::get_contexts_for_userid().
     */
    public function test_get_contexts_for_userid(): void {
        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);

        $contextlist = provider::get_contexts_for_userid($this->student->id);
        $this->assertCount(1, $contextlist);
        $contextforuser = $contextlist->current();
        $cmcontext = \context_module::instance($cm->id);
        $this->assertEquals($cmcontext->id, $contextforuser->id);
    }

    /**
     * Test for provider::export_user_data().
     */
    public function test_export_for_context(): void {
        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $cmcontext = \context_module::instance($cm->id);

        // Export all of the data for the context.
        $writer = \core_privacy\local\request\writer::with_context($cmcontext);
        $contextlist = new \core_privacy\local\request\approved_contextlist($this->student, 'mod_pcast', [$cmcontext->id]);

        \mod_pcast\privacy\provider::export_user_data($contextlist);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([]);

        $this->assertEquals('Podcast 1', $data->name);
        $this->assertEquals('Episode 1', $data->episodes[0]['name']);
    }

    /**
     * Test for provider::delete_data_for_all_users_in_context().
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        $generator = $this->getDataGenerator();
        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        // Create another student who will add an episode the pcast activity.
        $student2 = $generator->create_user();
        $generator->enrol_user($student2->id, $this->course->id, 'student');

        $this->setUser($student2);
        $pe3 = $this->plugingenerator->create_content($this->pcast, ['name' => 'Episode 1', 'approved' => 1]);
        $comment = $this->get_comment_object($context, $pe3->id);
        $comment->add('User 2 comment');

        \core_tag_tag::set_item_tags('mod_pcast', 'pcast_episodes', $pe3->id, $context, ['Pizza', 'Noodles']);

        // As a teacher, rate student 2 episode.
        $this->setUser($this->teacher);
        $rating = $this->get_rating_object($context, $pe3->id);
        $rating->update_rating(2);

        // Before deletion, we should have 2 episodes.
        $count = $DB->count_records('pcast_episodes', ['pcastid' => $this->pcast->id]);
        $this->assertEquals(2, $count);

        // Delete data based on context.
        provider::delete_data_for_all_users_in_context($context);

        // After deletion, the pcast episodes for that pcast activity should have been deleted.
        $count = $DB->count_records('pcast_episodes', ['pcastid' => $this->pcast->id]);
        $this->assertEquals(0, $count);

        $tagcount = $DB->count_records('tag_instance', ['component' => 'mod_pcast', 'itemtype' => 'pcast_episodes',
            'itemid' => $pe3->id, ]);
        $this->assertEquals(0, $tagcount);

        $commentcount = $DB->count_records('comments', ['component' => 'mod_pcast', 'commentarea' => 'pcast_episode',
            'itemid' => $pe3->id, 'userid' => $student2->id, ]);
        $this->assertEquals(0, $commentcount);

        $ratingcount = $DB->count_records('rating', ['component' => 'mod_pcast', 'ratingarea' => 'episode', 'itemid' => $pe3->id]);
        $this->assertEquals(0, $ratingcount);
    }

    /**
     * Test for provider::delete_data_for_user().
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $generator = $this->getDataGenerator();

        $student2 = $generator->create_user();
        $generator->enrol_user($student2->id, $this->course->id, 'student');

        $cm1 = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $pcast2 = $this->plugingenerator->create_instance(['course' => $this->course->id]);
        $cm2 = get_coursemodule_from_instance('pcast', $pcast2->id);

        $ge1 = $this->plugingenerator->create_content($this->pcast, ['concept' => 'first user pcast episode', 'approved' => 1]);
        $this->plugingenerator->create_content($pcast2, ['concept' => 'first user second pcast episode', 'approved' => 1]);

        $context1 = \context_module::instance($cm1->id);
        $context2 = \context_module::instance($cm2->id);
        \core_tag_tag::set_item_tags('mod_pcast', 'pcast_episodes', $ge1->id, $context1, ['Parmi', 'Sushi']);

        $this->setUser($student2);
        $pe3 = $this->plugingenerator->create_content($this->pcast, ['concept' => 'second user pcast episode',
                'approved' => 1, ]);

        $comment = $this->get_comment_object($context1, $pe3->id);
        $comment->add('User 2 comment');

        \core_tag_tag::set_item_tags('mod_pcast', 'pcast_episodes', $pe3->id, $context1, ['Pizza', 'Noodles']);

        // As a teacher, rate student 2 episode.
        $this->setUser($this->teacher);
        $rating = $this->get_rating_object($context1, $pe3->id);
        $rating->update_rating(2);

        // Before deletion, we should have 3 episodes, one rating and 2 tag instances.
        $count = $DB->count_records('pcast_episodes', ['pcastid' => $this->pcast->id]);
        $this->assertEquals(3, $count);
        $tagcount = $DB->count_records('tag_instance', ['component' => 'mod_pcast', 'itemtype' => 'pcast_episodes',
            'itemid' => $pe3->id, ]);
        $this->assertEquals(2, $tagcount);
        $ratingcount = $DB->count_records('rating', ['component' => 'mod_pcast', 'ratingarea' => 'episode',
            'itemid' => $pe3->id, ]);
        $this->assertEquals(1, $ratingcount);
        // Create another student who will add an episode to the first pcast.
        $contextlist = new \core_privacy\local\request\approved_contextlist($student2, 'pcast', [$context1->id, $context2->id]);
        provider::delete_data_for_user($contextlist);

        // After deletion, the pcast episode and tags for the second student should have been deleted.
        $count = $DB->count_records('pcast_episodes', ['pcastid' => $this->pcast->id, 'userid' => $student2->id]);
        $this->assertEquals(0, $count);

        $tagcount = $DB->count_records('tag_instance', ['component' => 'mod_pcast', 'itemtype' => 'pcast_episodes',
                'itemid' => $pe3->id, ]);
        $this->assertEquals(0, $tagcount);

        $commentcount = $DB->count_records('comments', ['component' => 'mod_pcast', 'commentarea' => 'pcast_episode',
                'itemid' => $pe3->id, 'userid' => $student2->id, ]);
        $this->assertEquals(0, $commentcount);

        $ratingcount = $DB->count_records('rating', ['component' => 'mod_pcast', 'ratingarea' => 'episode',
                'itemid' => $pe3->id, ]);
        $this->assertEquals(0, $ratingcount);

        // Student's 1 episodes, comments and tags should not be removed.
        $count = $DB->count_records('pcast_episodes', ['pcastid' => $this->pcast->id,
                'userid' => $this->student->id, ]);
        $this->assertEquals(2, $count);

        $tagcount = $DB->count_records('tag_instance', ['component' => 'mod_pcast', 'itemtype' => 'pcast_episodes',
            'itemid' => $ge1->id, ]);
        $this->assertEquals(2, $tagcount);

        $commentcount = $DB->count_records('comments', ['component' => 'mod_pcast', 'commentarea' => 'pcast_episode',
             'userid' => $this->student->id, ]);
        $this->assertEquals(1, $commentcount);
    }

    /**
     * Test that exporting one context does not leak episodes from another.
     *
     * Regression test: AND bound tighter than OR in the export query, so the comment and rating
     * EXISTS branches ignored the context restriction entirely. A user who had merely commented
     * on an episode elsewhere got that other activity exported too.
     */
    public function test_export_for_context_does_not_leak_other_contexts(): void {
        $cm1 = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context1 = \context_module::instance($cm1->id);

        // A second pcast, in a second course, that the student only comments on.
        $course2 = $this->getDataGenerator()->create_course();
        $pcast2 = $this->plugingenerator->create_instance(['course' => $course2->id]);
        $cm2 = get_coursemodule_from_instance('pcast', $pcast2->id);
        $context2 = \context_module::instance($cm2->id);

        $this->setUser($this->teacher);
        $episode2 = $this->plugingenerator->create_content($pcast2, ['name' => 'Other course episode',
            'approved' => 1, ]);

        $this->setUser($this->student);
        $comment = $this->get_comment_object($context2, $episode2->id);
        $comment->add('Commented in another course');

        // Export only the first context.
        $contextlist = new \core_privacy\local\request\approved_contextlist(
            $this->student,
            'mod_pcast',
            [$context1->id]
        );
        provider::export_user_data($contextlist);

        // The second context must not have been written to.
        $writer2 = \core_privacy\local\request\writer::with_context($context2);
        $this->assertFalse($writer2->has_any_data());
    }

    /**
     * Test deleting data for a set of users in a context.
     *
     * Regression test: this path used to delete from pcast_episodes_categories, a table that does
     * not exist, so it threw before comments, ratings, files or episodes were removed.
     */
    public function test_delete_data_for_users(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);

        $this->assertEquals(1, $DB->count_records(
            'pcast_episodes',
            ['pcastid' => $this->pcast->id, 'userid' => $this->student->id]
        ));

        $userlist = new \core_privacy\local\request\approved_userlist($context, 'mod_pcast', [$this->student->id]);
        provider::delete_data_for_users($userlist);

        $this->assertEquals(0, $DB->count_records(
            'pcast_episodes',
            ['pcastid' => $this->pcast->id, 'userid' => $this->student->id]
        ));
    }

    /**
     * Test that purging a context also removes the episode view counters.
     *
     * Regression test: the episodes were deleted before the loop that used them to find the view
     * rows, so the rows in pcast_views survived the purge.
     */
    public function test_delete_data_for_all_users_in_context_removes_views(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        $episode = $DB->get_record('pcast_episodes', ['pcastid' => $this->pcast->id], '*', MUST_EXIST);

        $DB->insert_record('pcast_views', (object) [
            'episodeid' => $episode->id,
            'userid' => $this->student->id,
            'views' => 3,
            'lastview' => time(),
        ]);
        $this->assertEquals(1, $DB->count_records('pcast_views', ['episodeid' => $episode->id]));

        provider::delete_data_for_all_users_in_context($context);

        $this->assertEquals(0, $DB->count_records('pcast_views', ['episodeid' => $episode->id]));
    }

    /**
     * Test that purging a context removes files from both of the per-episode file areas.
     *
     * Regression test: the deletion targeted a 'mediafile' area, which does not exist. mediafile is
     * a column of pcast_episodes; the real areas are logo, episode and summary. Files embedded in
     * an episode summary therefore survived erasure.
     */
    public function test_delete_data_for_all_users_in_context_removes_summary_files(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        $episode = $DB->get_record('pcast_episodes', ['pcastid' => $this->pcast->id], '*', MUST_EXIST);

        $fs = get_file_storage();
        foreach (['episode', 'summary'] as $area) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_pcast',
                'filearea' => $area,
                'itemid' => $episode->id,
                'filepath' => '/',
                'filename' => $area . '.txt',
            ], 'file content');
        }
        $this->assertCount(1, $fs->get_area_files($context->id, 'mod_pcast', 'summary', $episode->id, '', false));

        provider::delete_data_for_all_users_in_context($context);

        $this->assertCount(0, $fs->get_area_files($context->id, 'mod_pcast', 'episode', $episode->id, '', false));
        $this->assertCount(0, $fs->get_area_files($context->id, 'mod_pcast', 'summary', $episode->id, '', false));
    }

    /**
     * Seed a second episode by the teacher and view counters for two different viewers.
     *
     * A row in pcast_views belongs to the user who listened, not to the episode author, so this
     * gives every combination: each user has a counter on their own episode and on the other one.
     *
     * @return array [$viewer, $studentepisode, $teacherepisode]
     */
    protected function seed_views(): array {
        global $DB;

        $viewer = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($viewer->id, $this->course->id, 'student');

        $studentepisode = $DB->get_record(
            'pcast_episodes',
            ['pcastid' => $this->pcast->id, 'userid' => $this->student->id],
            '*',
            MUST_EXIST
        );

        $this->setUser($this->teacher);
        $teacherepisode = $this->plugingenerator->create_content($this->pcast, ['concept' => 'second', 'approved' => 1]);
        $this->setUser($this->student);

        $counters = [
            [$this->student->id, $studentepisode->id],
            [$this->student->id, $teacherepisode->id],
            [$viewer->id, $studentepisode->id],
            [$viewer->id, $teacherepisode->id],
        ];
        foreach ($counters as [$userid, $episodeid]) {
            $DB->insert_record('pcast_views', (object) [
                'episodeid' => $episodeid,
                'userid' => $userid,
                'views' => 2,
                'lastview' => time(),
            ]);
        }

        return [$viewer, $studentepisode, $teacherepisode];
    }

    /**
     * Test that erasing one user's data erases that user's view counters and nobody else's.
     *
     * Regression test: the counters were deleted by episode id for the episodes the user authored,
     * which erased every other viewer's counter on those episodes while leaving the user's own
     * counters on other people's episodes in place.
     */
    public function test_delete_data_for_user_scopes_views_to_that_user(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        [$viewer, , $teacherepisode] = $this->seed_views();

        $contextlist = new \core_privacy\local\request\approved_contextlist(
            $this->student,
            'mod_pcast',
            [$context->id]
        );
        provider::delete_data_for_user($contextlist);

        $this->assertEquals(
            0,
            $DB->count_records('pcast_views', ['userid' => $this->student->id]),
            'Every counter belonging to the erased user should be gone.'
        );
        $this->assertEquals(
            1,
            $DB->count_records('pcast_views', ['userid' => $viewer->id, 'episodeid' => $teacherepisode->id]),
            'Another viewer\'s counter on a surviving episode must not be collateral damage.'
        );
    }

    /**
     * Test that erasing several users' data scopes the view counters the same way.
     *
     * Regression test: see test_delete_data_for_user_scopes_views_to_that_user.
     */
    public function test_delete_data_for_users_scopes_views_to_those_users(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        [$viewer, , $teacherepisode] = $this->seed_views();

        $userlist = new \core_privacy\local\request\approved_userlist($context, 'mod_pcast', [$this->student->id]);
        provider::delete_data_for_users($userlist);

        $this->assertEquals(0, $DB->count_records('pcast_views', ['userid' => $this->student->id]));
        $this->assertEquals(
            1,
            $DB->count_records('pcast_views', ['userid' => $viewer->id, 'episodeid' => $teacherepisode->id])
        );
    }

    /**
     * Test that a user who only listened still has their counters erased.
     *
     * Regression test: the method returned early when the selected users had authored no episodes,
     * so for a listener nothing at all was deleted.
     */
    public function test_delete_data_for_users_erases_a_listener_who_authored_nothing(): void {
        global $DB;

        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        [$viewer] = $this->seed_views();

        $userlist = new \core_privacy\local\request\approved_userlist($context, 'mod_pcast', [$viewer->id]);
        provider::delete_data_for_users($userlist);

        $this->assertEquals(0, $DB->count_records('pcast_views', ['userid' => $viewer->id]));
        $this->assertEquals(
            2,
            $DB->count_records('pcast_views', ['userid' => $this->student->id]),
            'Only the selected user\'s counters should be removed.'
        );
    }

    /**
     * Test that a user who only listened is reported as holding data in the activity.
     *
     * Regression test: both discovery methods looked only at episode authorship, so a listener was
     * invisible to a data request even though their counters were kept and their userid declared
     * in the metadata.
     */
    public function test_a_listener_is_discoverable(): void {
        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        [$viewer] = $this->seed_views();

        $contextlist = provider::get_contexts_for_userid($viewer->id);
        $this->assertCount(1, $contextlist);
        $this->assertEquals($context->id, $contextlist->current()->id);

        $userlist = new \core_privacy\local\request\userlist($context, 'mod_pcast');
        provider::get_users_in_context($userlist);
        $this->assertContains((int) $viewer->id, $userlist->get_userids());
    }

    /**
     * Test that a user's own view counters are exported.
     *
     * Regression test: the counters were declared in the metadata but never exported, and a user
     * who had only listened received an empty export.
     */
    public function test_export_includes_the_users_own_views(): void {
        $cm = get_coursemodule_from_instance('pcast', $this->pcast->id);
        $context = \context_module::instance($cm->id);
        [$viewer] = $this->seed_views();

        $writer = \core_privacy\local\request\writer::with_context($context);
        $contextlist = new \core_privacy\local\request\approved_contextlist($viewer, 'mod_pcast', [$context->id]);
        provider::export_user_data($contextlist);

        $this->assertTrue($writer->has_any_data(), 'A listener should receive an export.');
        $data = $writer->get_data([]);

        $this->assertCount(2, $data->episodes);
        foreach ($data->episodes as $episode) {
            $this->assertArrayHasKey('views', $episode);
            $this->assertEquals(2, $episode['views']);
        }
    }

    /**
     * Get the comment area for pcast module.
     *
     * @param context $context The context.
     * @param int $itemid The item ID.
     * @return comment
     */
    protected function get_comment_object(\context $context, $itemid): \comment {
        $args = new \stdClass();

        $args->context = $context;
        $args->course = get_course(SITEID);
        $args->area = 'pcast_episode';
        $args->itemid = $itemid;
        $args->component = 'mod_pcast';
        $comment = new \comment($args);
        $comment->set_post_permission(true);

        return $comment;
    }

    /**
     * Get the rating area for pcast module.
     *
     * @param context $context The context.
     * @param int $itemid The item ID.
     * @return rating object
     */
    protected function get_rating_object(\context $context, $itemid) {
        global $USER;

        $ratingoptions = new \stdClass();
        $ratingoptions->context = $context;
        $ratingoptions->ratingarea = 'episode';
        $ratingoptions->component = 'mod_pcast';
        $ratingoptions->itemid  = $itemid;
        $ratingoptions->scaleid = 2;
        $ratingoptions->userid  = $USER->id;
        return new \rating($ratingoptions);
    }
}
