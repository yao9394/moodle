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
 * Contains the class containing unit tests for the daily completion cron task.
 *
 * @package   core
 * @copyright 2020 Jun Pataleta
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\task;

/**
 * Class containing unit tests for the daily completion cron task.
 *
 * @package core
 * @copyright 2020 Jun Pataleta
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_completion_cron_task_testcase extends \advanced_testcase {

    /**
     * Test completion daily cron task.
     */
    public function test_completion_daily_cron() {
        global $DB;

        $this->resetAfterTest();

        set_config('enablecompletion', 1);
        set_config('enrol_plugins_enabled', 'self,manual');

        $generator = $this->getDataGenerator()->get_plugin_generator('core_completion');
        $generator->_create_complex_testdata();
        $c1 = $generator->get_test_courses()[1];
        $c2 = $generator->get_test_courses()[2];
        $c3 = $generator->get_test_courses()[3];

        $user1 = $generator->get_test_users()[1];
        $user2 = $generator->get_test_users()[2];
        $user3 = $generator->get_test_users()[3];
        $user4 = $generator->get_test_users()[4];
        $t1 = $generator->get_test_users()[5];
        $t2 = $generator->get_test_users()[6];

        $now = $generator->get_test_dates()['now'];
        $past = $generator->get_test_dates()['past'];

        $this->assertEquals(1, $DB->count_records('course_completions'));

        // Run the daily completion task.
        ob_start();
        $task = new completion_daily_task();
        $task->execute();
        ob_end_clean();

        // Confirm there are no completion records for teachers nor for courses without completion enabled.
        list($tsql, $tparams) = $DB->get_in_or_equal([$t1->id, $t2->id], SQL_PARAMS_NAMED);
        list($csql, $cparams) = $DB->get_in_or_equal([$c1->id, $c2->id], SQL_PARAMS_NAMED);
        $select = "userid $tsql AND course $csql";
        $params = array_merge($tparams, $cparams);
        $this->assertEmpty($DB->get_records_select('course_completions', $select, $params));

        // Load all records for these courses in course_completions.
        // Return results indexed by userid.
        // (which will not hide duplicates due to their being a unique index on that and the course columns).
        $cc1 = $DB->get_records('course_completions', ['course' => $c1->id], '', 'userid,timeenrolled');
        $cc2 = $DB->get_records('course_completions', ['course' => $c2->id], '', 'userid,timeenrolled');

        // Get s1's completion record from c1.
        $s1c1 = $DB->get_record('course_completions', ['userid' => $user1->id, 'course' => $c1->id]);
        $this->assertEquals(userdate($past - 5), userdate($s1c1->timeenrolled));

        // All users should be mark started in course1.
        $this->assertEquals($past - 2, $cc1[$user2->id]->timeenrolled);
        $this->assertGreaterThanOrEqual($now, $cc1[$user4->id]->timeenrolled);
        $this->assertLessThan($now + 60, $cc1[$user4->id]->timeenrolled);

        // User1 should have a timeenrolled in course1 of $past-5 (due to multiple enrolments).
        $this->assertEquals($past - 5, $cc1[$user1->id]->timeenrolled);

        // User3 should have a timeenrolled in course1 of $past-2 (due to multiple enrolments).
        $this->assertEquals($past - 2, $cc1[$user3->id]->timeenrolled);

        // User 2 should not be mark as started in course2 at all (nothing current).
        $this->assertEquals(false, isset($cc2[$user2->id]));

        // Add some enrolment to course2 with different times to check for bugs.
        $this->assertEquals($past - 10, $cc2[$user1->id]->timeenrolled);
        $this->assertEquals($past - 15, $cc2[$user3->id]->timeenrolled);

    }
}
