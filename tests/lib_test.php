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
 * Pcast lib tests.
 *
 * @package    mod_pcast
 * @copyright  2018 Stephen Bourget
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_pcast;
defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/pcast/lib.php');
require_once($CFG->dirroot . '/mod/pcast/locallib.php');

/**
 * Pcast lib tests.
 *
 * @package    mod_pcast
 * @copyright  2018 Stephen Bourget
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /**
     * Test calendar event creation.
     *
     */
    public function test_pcast_core_calendar_provide_event_action(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Create the activity.
        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        // Create a calendar event.
        $event = $this->create_action_event(
            $course->id,
            $pcast->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
        );
        // Create an action factory.
        $factory = new \core_calendar\action_factory();
        // Decorate action event.
        $actionevent = mod_pcast_core_calendar_provide_event_action($event, $factory);
        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('view'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    /**
     * Test calendar event read as a non-user.
     *
     */
    public function test_pcast_core_calendar_provide_event_action_as_non_user(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Create the activity.
        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        // Create a calendar event.
        $event = $this->create_action_event(
            $course->id,
            $pcast->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
        );
        // Now log out.
        $CFG->forcelogin = true; // We don't want to be logged in as guest, as guest users might still have some capabilities.
        $this->setUser();
        // Create an action factory.
        $factory = new \core_calendar\action_factory();
        // Decorate action event for the student.
        $actionevent = mod_pcast_core_calendar_provide_event_action($event, $factory);
        // Confirm the event is not shown at all.
        $this->assertNull($actionevent);
    }

    /**
     * Test calendar event read as a user.
     *
     */
    public function test_pcast_core_calendar_provide_event_action_for_user(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create the activity.
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        // Create a calendar event.
        $event = $this->create_action_event(
            $course->id,
            $pcast->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
        );
        // Now log out.
        $CFG->forcelogin = true; // We don't want to be logged in as guest, as guest users might still have some capabilities.
        $this->setUser();
        // Create an action factory.
        $factory = new \core_calendar\action_factory();
        // Decorate action event for the student.
        $actionevent = mod_pcast_core_calendar_provide_event_action($event, $factory, $student->id);
        // Confirm the event was decorated.
        $this->assertInstanceOf('\core_calendar\local\event\value_objects\action', $actionevent);
        $this->assertEquals(get_string('view'), $actionevent->get_name());
        $this->assertInstanceOf('moodle_url', $actionevent->get_url());
        $this->assertEquals(1, $actionevent->get_item_count());
        $this->assertTrue($actionevent->is_actionable());
    }

    /**
     * Test calendar event read for an activity in a hidden section.
     *
     */
    public function test_pcast_core_calendar_provide_event_action_in_hidden_section(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        // Create a course.
        $course = $this->getDataGenerator()->create_course();
        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create the activity.
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        // Create a calendar event.
        $event = $this->create_action_event(
            $course->id,
            $pcast->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
        );
        // Set sections 0 as hidden.
        set_section_visible($course->id, 0, 0);
        // Create an action factory.
        $factory = new \core_calendar\action_factory();
        // Decorate action event for the student.
        $actionevent = mod_pcast_core_calendar_provide_event_action($event, $factory, $student->id);
        // Confirm the event is not shown at all.
        $this->assertNull($actionevent);
    }

    /**
     * Test calendar event read for an activity already completed.
     *
     */
    public function test_pcast_core_calendar_provide_event_action_already_completed(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        // Create the activity.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $pcast = $this->getDataGenerator()->create_module(
            'pcast',
            ['course' => $course->id],
            ['completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS]
        );
        // Get some additional data.
        $cm = get_coursemodule_from_instance('pcast', $pcast->id);
        // Create a calendar event.
        $event = $this->create_action_event(
            $course->id,
            $pcast->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
        );
        // Mark the activity as completed.
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm);
        // Create an action factory.
        $factory = new \core_calendar\action_factory();
        // Decorate action event.
        $actionevent = mod_pcast_core_calendar_provide_event_action($event, $factory);
        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    /**
     * Test calendar event read for an activity already completed by the user.
     *
     *
     */
    public function test_pcast_core_calendar_provide_event_action_already_completed_for_user(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->enablecompletion = 1;
        // Create a course.
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        // Create a student.
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        // Create the activity.
        $pcast = $this->getDataGenerator()->create_module(
            'pcast',
            ['course' => $course->id],
            ['completion' => 2, 'completionview' => 1, 'completionexpected' => time() + DAYSECS]
        );
        // Get some additional data.
        $cm = get_coursemodule_from_instance('pcast', $pcast->id);
        // Create a calendar event.
        $event = $this->create_action_event(
            $course->id,
            $pcast->id,
            \core_completion\api::COMPLETION_EVENT_TYPE_DATE_COMPLETION_EXPECTED
        );
        // Mark the activity as completed for the user.
        $completion = new \completion_info($course);
        $completion->set_module_viewed($cm, $student->id);
        // Create an action factory.
        $factory = new \core_calendar\action_factory();
        // Decorate action event.
        $actionevent = mod_pcast_core_calendar_provide_event_action($event, $factory, $student->id);
        // Ensure result was null.
        $this->assertNull($actionevent);
    }

    /**
     * Creates an action event.
     *
     * @param int $courseid The course id.
     * @param int $instanceid The instance id.
     * @param string $eventtype The event type.
     * @return bool|calendar_event
     */
    private function create_action_event($courseid, $instanceid, $eventtype) {
        $event = new \stdClass();
        $event->name = 'Calendar event';
        $event->modulename  = 'pcast';
        $event->courseid = $courseid;
        $event->instance = $instanceid;
        $event->type = CALENDAR_EVENT_TYPE_ACTION;
        $event->eventtype = $eventtype;
        $event->timestart = time();
        return \calendar_event::create($event);
    }

    /**
     * Test that deleting an activity also deletes its episode comments.
     *
     * Regression test: the query parameters were in the wrong order, so contextid was bound to the
     * instance id and pcastid to the context id, and no comments were ever removed.
     */
    public function test_pcast_delete_instance_removes_comments(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');
        $episode = $generator->create_content($pcast);
        $context = \context_module::instance($pcast->cmid);

        $DB->insert_record('comments', (object) [
            'contextid' => $context->id,
            'component' => 'mod_pcast',
            'commentarea' => 'pcast_episode',
            'itemid' => $episode->id,
            'content' => 'A comment',
            'format' => FORMAT_MOODLE,
            'userid' => get_admin()->id,
            'timecreated' => time(),
        ]);
        $this->assertEquals(1, $DB->count_records(
            'comments',
            ['commentarea' => 'pcast_episode', 'itemid' => $episode->id]
        ));

        // A second activity in the same course, whose comment must be left alone.
        $other = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        $otherepisode = $generator->create_content($other);
        $DB->insert_record('comments', (object) [
            'contextid' => \context_module::instance($other->cmid)->id,
            'component' => 'mod_pcast',
            'commentarea' => 'pcast_episode',
            'itemid' => $otherepisode->id,
            'content' => 'Another activity',
            'format' => FORMAT_MOODLE,
            'userid' => get_admin()->id,
            'timecreated' => time(),
        ]);

        pcast_delete_instance($pcast->id);

        $this->assertEquals(0, $DB->count_records(
            'comments',
            ['commentarea' => 'pcast_episode', 'itemid' => $episode->id]
        ));
        $this->assertEquals(1, $DB->count_records(
            'comments',
            ['commentarea' => 'pcast_episode', 'itemid' => $otherepisode->id]
        ));
    }

    /**
     * Test that an episode summary longer than 255 characters can be stored.
     *
     * Regression test: install.xml declared summary as char(255) even though upgrade step
     * 2016060300 had changed it to text, so fresh installs could not store a full summary.
     */
    public function test_episode_summary_accepts_long_text(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');

        $longsummary = str_repeat('a', 1000);
        $episode = $generator->create_content($pcast, ['summary' => $longsummary]);

        $this->assertEquals($longsummary, $DB->get_field('pcast_episodes', 'summary', ['id' => $episode->id]));
    }

    /**
     * Test resetting episodes belonging to users who are no longer enrolled.
     *
     * Regression test: the cleanup loop treated episode ids as pcast instance ids, and ran after the
     * rows had already been deleted, so it matched nothing and the files were orphaned. It also
     * cleared the whole file area rather than just the episodes being removed.
     */
    public function test_pcast_reset_userdata_not_enrolled(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        $context = \context_module::instance($pcast->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');

        $enrolled = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($enrolled->id, $course->id, 'student');
        $outsider = $this->getDataGenerator()->create_user();

        $keep = $generator->create_content($pcast, ['userid' => $enrolled->id, 'course' => $course->id]);
        $remove = $generator->create_content($pcast, ['userid' => $outsider->id, 'course' => $course->id]);

        $fs = get_file_storage();
        foreach ([$keep->id, $remove->id] as $itemid) {
            foreach (['episode', 'summary'] as $area) {
                $fs->create_file_from_string([
                    'contextid' => $context->id,
                    'component' => 'mod_pcast',
                    'filearea' => $area,
                    'itemid' => $itemid,
                    'filepath' => '/',
                    'filename' => $area . '.bin',
                ], 'media');
            }
            $DB->insert_record('pcast_views', (object) [
                'episodeid' => $itemid,
                'userid' => $enrolled->id,
                'views' => 1,
                'lastview' => time(),
            ]);
        }

        // Note: timeshift is deliberately absent, which used to raise an undefined property warning.
        pcast_reset_userdata((object) ['courseid' => $course->id, 'reset_pcast_notenrolled' => 1]);

        // The unenrolled user's episode and its file are gone.
        $this->assertFalse($DB->record_exists('pcast_episodes', ['id' => $remove->id]));
        $this->assertCount(0, $fs->get_area_files($context->id, 'mod_pcast', 'episode', $remove->id, '', false));

        $this->assertCount(0, $fs->get_area_files($context->id, 'mod_pcast', 'summary', $remove->id, '', false));
        $this->assertEquals(0, $DB->count_records('pcast_views', ['episodeid' => $remove->id]));

        // The enrolled user's episode, files and views are untouched.
        $this->assertTrue($DB->record_exists('pcast_episodes', ['id' => $keep->id]));
        $this->assertCount(1, $fs->get_area_files($context->id, 'mod_pcast', 'episode', $keep->id, '', false));
        $this->assertCount(1, $fs->get_area_files($context->id, 'mod_pcast', 'summary', $keep->id, '', false));
        $this->assertEquals(1, $DB->count_records('pcast_views', ['episodeid' => $keep->id]));
    }

    /**
     * Test that a full episode reset clears the episodes and both file areas.
     */
    public function test_pcast_reset_userdata_all(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', ['course' => $course->id]);
        $context = \context_module::instance($pcast->cmid);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');
        $episode = $generator->create_content($pcast, ['course' => $course->id]);

        $fs = get_file_storage();
        foreach (['episode', 'summary'] as $area) {
            $fs->create_file_from_string([
                'contextid' => $context->id,
                'component' => 'mod_pcast',
                'filearea' => $area,
                'itemid' => $episode->id,
                'filepath' => '/',
                'filename' => $area . '.bin',
            ], 'media');
        }

        pcast_reset_userdata((object) ['courseid' => $course->id, 'reset_pcast_all' => 1]);

        $this->assertEquals(0, $DB->count_records('pcast_episodes', ['pcastid' => $pcast->id]));
        $this->assertCount(0, $fs->get_area_files($context->id, 'mod_pcast', 'episode', $episode->id, '', false));
        $this->assertCount(0, $fs->get_area_files($context->id, 'mod_pcast', 'summary', $episode->id, '', false));
    }

    /**
     * Test that every language string referenced by the plugin actually exists.
     *
     * Regression test: nopcasts, databaseerror, errdeltimeexpired and notapproved were referenced
     * in code but never defined, so users saw raw [[nopcasts]] markers on an empty course index.
     */
    public function test_referenced_language_strings_exist(): void {
        $this->resetAfterTest();

        $manager = get_string_manager();
        foreach (['nopcasts', 'databaseerror', 'errdeltimeexpired', 'notapproved'] as $identifier) {
            $this->assertTrue(
                $manager->string_exists($identifier, 'mod_pcast'),
                "The string '{$identifier}' is used by mod_pcast but is not defined."
            );
        }
    }

    /**
     * Test that the secondary navigation class is where core looks for it.
     *
     * Regression test: the class declared namespace mod_pcast\navigation\views but lived under
     * classes/local/views, so core's class_exists() check never found it and the custom secondary
     * navigation, including the pending-approval tab, silently did nothing.
     */
    public function test_secondary_navigation_class_is_autoloadable(): void {
        $this->assertTrue(
            class_exists('mod_pcast\\navigation\\views\\secondary'),
            'Core resolves the secondary navigation class by namespace; it must be autoloadable.'
        );
    }

    /**
     * Test that category view with the default hook lists categorised episodes too.
     *
     * Regression test: view.php defaults the hook to the string 'ALL', which was compared against
     * the integer PCAST_SHOW_ALL_CATEGORIES. That was true on PHP 7 and false on PHP 8, so the
     * listing fell through to the category lookup, which decodes 'ALL' to top category 0 and
     * therefore showed only uncategorised episodes.
     */
    public function test_category_view_with_all_hook_lists_categorised_episodes(): void {
        global $DB;

        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $pcast = $this->getDataGenerator()->create_module('pcast', [
            'course' => $course->id,
            'userscancategorize' => 1,
        ]);
        $cm = get_coursemodule_from_instance('pcast', $pcast->id);
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_pcast');

        $uncategorised = $generator->create_content($pcast, ['name' => 'Uncategorised episode']);
        $categorised = $generator->create_content($pcast, ['name' => 'Categorised episode']);
        $DB->set_field('pcast_episodes', 'topcategory', 1, ['id' => $categorised->id]);

        $full = $DB->get_record('pcast', ['id' => $pcast->id], '*', MUST_EXIST);

        ob_start();
        pcast_display_category_episodes($full, $cm, 0, 'ALL');
        $output = ob_get_clean();

        $this->assertStringContainsString('Uncategorised episode', $output);
        $this->assertStringContainsString('Categorised episode', $output);
    }
}
