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
 * Course completion unit test helper
 *
 * @package    core
 * @category   completion
 * @copyright  2012 Aaron Barnes <aaronb@catalyst.net.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Course completion test helper class
 *
 * @package core_completion
 * @category completion
 * @copyright 2020 Catalyst IT Ltd
 * @author Sagar Ghimire <sagarghimire@catalyst-au.net>
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_completion_generator extends \component_generator_base {

    /**
     * @var array $_testcourses Test Courses
     */
    private $_testcourses = [];

    /**
     * @var array $_testusers Test Users
     */
    private $_testusers = [];

    /**
     * @var array $_testdates Test Dates
     */
    private $_testdates = [];

    /**
     * Create testdata for the completion_completion_testcase
     */
    public function _create_complex_testdata() {
        global $DB;

        $now = time();
        $lastweek = $now - WEEKSECS;
        $tomorrow = $now + DAYSECS;
        // Course with completion enabled and has already started.
        $course1 = $this->datagenerator->create_course([
            'enablecompletion' => 1,
            'startdate' => $lastweek
        ]);
        // Course with completion enabled but hasn't started yet.
        $course2 = $this->datagenerator->create_course(['enablecompletion' => 1, 'startdate' => $tomorrow]);
        // Completion not enabled.
        $course3 = $this->datagenerator->create_course();
        $sql = "UPDATE {enrol}
                   SET status = ?
                 WHERE courseid IN (?, ?, ?)";

        // Make all enrolment plugins enabled.
        $DB->execute($sql,
            [
                ENROL_INSTANCE_ENABLED,
                $course1->id, $course2->id, $course3->id
            ]
        );

        // Setup some users.
        $user1 = $this->datagenerator->create_user(['username' => 's1']);
        $user2 = $this->datagenerator->create_user(['username' => 's2']);
        $user3 = $this->datagenerator->create_user(['username' => 's3']);
        $user4 = $this->datagenerator->create_user(['username' => 's4']);
        $t1 = $this->datagenerator->create_user(['username' => 't1']);
        $t2 = $this->datagenerator->create_user(['username' => 't2']);

        // Enrol the users.
        $past   = $now - DAYSECS;

        $enrolments = [
            // All users should be mark started in course1.
            [$course1->id, $user1->id, 'student', $past, 0, 'manual'],
            [$course1->id, $user2->id, 'student', $past - 2, 0, 'manual'],
            [$course1->id, $user3->id, 'student', 0, 0, 'manual'],
            [$course1->id, $user4->id, 'student', 0, 0, 'manual'],
            // User1 should have a timeenrolled in course1 of $past-5 (due to multiple enrolments).
            [$course1->id, $user1->id, 'student', $past - 5, 0, 'self'],
            // User3 should have a timeenrolled in course1 of $past-2 (due to multiple enrolments).
            [$course1->id, $user3->id, 'student', $past - 2, 0, 'self'],
            [$course1->id, $user3->id, 'student', $past - 100, $past, 'manual'], // In the past.
            [$course1->id, $user3->id, 'student', $tomorrow, $tomorrow + 100, 'manual'], // In the future.
            // User 2 should not be mark as started in course2 at all (nothing current).
            [$course2->id, $user2->id, 'student', $tomorrow, 0, 'manual'],
            [$course2->id, $user2->id, 'student', 0, $past, 'manual'],
            // Add some enrolment to course2 with different times to check for bugs.
            [$course2->id, $user1->id, 'student', $past - 10, 0, 'manual'],
            [$course2->id, $user3->id, 'student', $past - 15, 0, 'manual'],
            // Add enrolment in course2 for user4 (who will be already started).
            [$course2->id, $user4->id, 'student', $past - 13, 0, 'manual'],
            // Add enrolment in course3 even though completion is not enabled.
            [$course3->id, $user1->id, 'student', 0, 0, 'manual'],
            // Add multiple enrolments for teachers.
            [$course1->id, $t1->id, 'teacher', $past, 0, 'manual'],
            [$course1->id, $t2->id, 'editingteacher', $past, 0, 'manual'],
            [$course2->id, $t1->id, 'teacher', $past, 0, 'manual'],
            [$course2->id, $t2->id, 'editingteacher', $past, 0, 'manual'],
        ];

        foreach ($enrolments as $enrol) {
            if (!$this->datagenerator->enrol_user($enrol[1], $enrol[0], $enrol[2], $enrol[5], $enrol[3], $enrol[4])) {
                throw new coding_exception('error creating enrolments in test_completion_cron_mark_started()');
            }
        }

        // Delete all old records in case they were missed.
        $DB->delete_records('course_completions', ['course' => $course1->id]);
        $DB->delete_records('course_completions', ['course' => $course2->id]);
        $DB->delete_records('course_completions', ['course' => $course3->id]);

        // Create course_completions record for user4 in course2.
        $params = [
            'course'        => $course2->id,
            'userid'        => $user4->id,
            'timeenrolled'  => $past - 50,
            'reaggregate'   => 0
        ];
        $DB->insert_record('course_completions', $params);

        $this->_testcourses[1] = $course1;
        $this->_testcourses[2] = $course2;
        $this->_testcourses[3] = $course3;

        $this->_testusers[1] = $user1;
        $this->_testusers[2] = $user2;
        $this->_testusers[3] = $user3;
        $this->_testusers[4] = $user4;
        $this->_testusers[5] = $t1;
        $this->_testusers[6] = $t2;

        $this->_testdates['now'] = $now;
        $this->_testdates['past'] = $past;
        $this->_testdates['future'] = $tomorrow;
    }

    /**
     * Return test courses
     */
    public function get_test_courses() {
        return $this->_testcourses;
    }

    /**
     * Return test users
     */
    public function get_test_users() {
        return $this->_testusers;
    }

    /**
     * Return test users
     */
    public function get_test_dates() {
        return $this->_testdates;
    }
}
