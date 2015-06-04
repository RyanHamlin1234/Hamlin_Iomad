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

class report_completion2 {

    /**
     * Get completion summary info for a course
     *
     * Parameters - $departmentid = int;
     *              $courseid = int;
     *
     * Return array();
     **/
    public static function get_course_summary_info($departmentid, $courseid=0, $showsuspended) {
        global $DB;

        // Create a temporary table to hold the userids.
        $temptablename = 'tmp_csum_users_'.time();
        $dbman = $DB->get_manager();

        // Define table user to be created.
        $table = new xmldb_table($temptablename);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        $dbman->create_temp_table($table);

        // Populate it.
        $alldepartments = company::get_all_subdepartments($departmentid);
        if (count($alldepartments) > 0 ) {
            // Deal with suspended or not.
            if (empty($showsuspended)) {
                $suspendedsql = " AND suspended = 0 ";
            } else {
                $suspendedsql = "";
            }
            $tempcreatesql = "INSERT INTO {".$temptablename."} (userid) SELECT userid from {company_users}
                              WHERE departmentid IN (".implode(',', array_keys($alldepartments)).") $suspendedsql";
        } else {
            $tempcreatesql = "";
        }
        $DB->execute($tempcreatesql);

        // All or one course?
        $courses = array();
        if (!empty($courseid)) {
            $courses[$courseid] = new stdclass();
            $courses[$courseid]->id = $courseid;
        } else {
            $courses = company::get_recursive_department_courses($departmentid);
        }

        // Process them!
        $returnarr = array();
        foreach ($courses as $course) {
            $courseobj = new stdclass();
            $courseobj->id = $course->courseid;
            $courseobj->numenrolled = $DB->count_records_sql("SELECT COUNT(cc.id) FROM {course_completions} cc
                                                   JOIN {".$temptablename."} tt ON (cc.userid = tt.userid)
                                                   WHERE
                                                   cc.course = :course", array('course' => $course->courseid));
            $courseobj->numnotstarted = $DB->count_records_sql("SELECT COUNT(cc.id) FROM {course_completions} cc
                                                   JOIN {".$temptablename."} tt ON (cc.userid = tt.userid)
                                                   WHERE
                                                   cc.course = :course AND
                                                   cc.timestarted = 0", array('course' => $course->courseid));
            $courseobj->numstarted = $courseobj->numenrolled;
            $courseobj->numcompleted = $DB->count_records_sql("SELECT COUNT(cc.id) FROM {course_completions} cc
                                                   JOIN {".$temptablename."} tt ON (cc.userid = tt.userid)
                                                   WHERE
                                                   cc.course = :course AND
                                                   cc.timecompleted IS NOT NULL", array('course' => $course->courseid));

            if (!$courseobj->coursename = $DB->get_field('course', 'fullname', array('id' => $course->courseid))) {
                continue;
            }
            $returnarr[$course->courseid] = $courseobj;
        }
        return $returnarr;
    }

    /** 
     * Get users into temporary table
     */
    private static function populate_temporary_users($temptablename, $searchinfo) {
        global $DB;


        // Create a temporary table to hold the userids.
        $dbman = $DB->get_manager();

        // Define table user to be created.
        $table = new xmldb_table($temptablename);
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', XMLDB_UNSIGNED, XMLDB_NOTNULL, null, null);
        $table->add_key('primary', XMLDB_KEY_PRIMARY, array('id'));

        $dbman->create_temp_table($table);

        // Populate it.
        $alldepartments = company::get_all_subdepartments($searchinfo->departmentid);
        if (count($alldepartments) > 0 ) {
            $tempcreatesql = "INSERT INTO {".$temptablename."} (userid) SELECT userid from {company_users}
                              WHERE departmentid IN (".implode(',', array_keys($alldepartments)).")";
        } else {
            $tempcreatesql = "";
        }
        $DB->execute($tempcreatesql);

        return array($dbman, $table);
    }

    /**
     * Get user completion info for a course
     *
     * Parameters - $departmentid = int;
     *              $courseid = int;
     *              $page = int;
     *              $perpade = int;
     *
     * Return array();
     **/
    public static function get_user_course_completion_data($searchinfo, $courseid, $page=0, $perpage=0, $completiontype=0) {
        global $DB;

        $completiondata = new stdclass();

        $course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

        $temptablename = 'tmp_ccomp_users_'.time();
        list($dbman, $table) = self::populate_temporary_users($temptablename, $searchinfo);

        // Deal with completion types.
        if (!empty($completiontype)) {
            if ($completiontype == 1) {
                $completionsql = " AND cc.timeenrolled > 0 AND cc.timestarted = 0 ";
            } else if ($completiontype == 2 ) {
                $completionsql = " AND cc.timestarted > 0 AND cc.timecompleted IS NULL ";
            } else if ($completiontype == 3 ) {
                $completionsql = " AND cc.timecompleted IS NOT NULL  ";
            }
        } else {
            $completionsql = "";
        }
                
        // Get the user details.
        $shortname = addslashes($course->shortname);
        $countsql = "SELECT u.id ";
        $selectsql = "SELECT u.id,
                u.id as uid,
                u.firstname AS firstname,
                u.lastname AS lastname,
                u.email AS email,
                '{$shortname}' AS coursename,
                '$courseid' AS courseid,
                cc.timeenrolled AS timeenrolled,
                cc.timestarted AS timestarted,
                cc.timecompleted AS timecompleted,
                d.name as department,
                gg.finalgrade as result ";
        $fromsql = " FROM {user} u, {course_completions} cc, {department} d, {company_users} du, {".$temptablename."} tt
                     LEFT JOIN {grade_grades} gg ON ( gg.itemid = (
                       SELECT id FROM {grade_items} WHERE courseid = $courseid AND itemtype='course'))

                WHERE $searchinfo->sqlsearch
                AND tt.userid = u.id
                AND cc.course = $courseid
                AND u.id = cc.userid
                AND du.userid = u.id
                AND d.id = du.departmentid
                AND gg.userid = u.id
                $completionsql
                $searchinfo->sqlsort ";

        $searchinfo->searchparams['courseid'] = $courseid;
        $users = $DB->get_records_sql($selectsql.$fromsql, $searchinfo->searchparams, $page * $perpage, $perpage);
        $countusers = $DB->get_records_sql($countsql.$fromsql, $searchinfo->searchparams);
        $numusers = count($countusers);

        $returnobj = new stdclass();
        $returnobj->users = $users;
        $returnobj->totalcount = $numusers;

        $dbman->drop_table($table);

        return $returnobj;
    }

    /**
     * Get all users completion info regardless of course
     *
     * Parameters - $departmentid = int;
     *              $page = int;
     *              $perpade = int;
     *
     * Return array();
     **/
    public static function get_all_user_course_completion_data($searchinfo, $page=0, $perpage=0, $completiontype=0) {
        global $DB;

        $completiondata = new stdclass();

        // Create a temporary table to hold the userids.
        $temptablename = 'tmp_ccomp_users_'.time();
        list($dbman, $table) = self::populate_temporary_users($temptablename, $searchinfo);

        // Deal with completion types.
        if (!empty($completiontype)) {
            if ($completiontype == 1) {
                $completionsql = " AND cc.timeenrolled > 0 AND cc.timestarted = 0 ";
            } else if ($completiontype == 2 ) {
                $completionsql = " AND cc.timestarted > 0 AND cc.timecompleted IS NULL ";
            } else if ($completiontype == 3 ) {
                $completionsql = " AND cc.timecompleted IS NOT NULL  ";
            }
        } else {
            $completionsql = "";
        }
                
        // Get the user details.
        $countsql = "SELECT CONCAT(co.id, u.id) AS id ";
        $selectsql = "
                SELECT
                CONCAT(co.id, u.id) AS id, 
                u.id AS uid,
                u.firstname AS firstname,
                u.lastname AS lastname,
                u.email AS email,
                co.shortname AS coursename,
                co.id AS courseid,
                cc.timeenrolled AS timeenrolled,
                cc.timestarted AS timestarted,
                cc.timecompleted AS timecompleted,
                d.name as department,
                '0' as result ";
        $fromsql = " FROM {user} u, {course_completions} cc, {department} d, {company_users} du, {".$temptablename."} tt, {course} co

                WHERE $searchinfo->sqlsearch
                AND tt.userid = u.id
                AND co.id = cc.course
                AND u.id = cc.userid
                AND du.userid = u.id
                AND d.id = du.departmentid
                $completionsql
                $searchinfo->sqlsort ";

        $users = $DB->get_records_sql($selectsql.$fromsql, $searchinfo->searchparams, $page * $perpage, $perpage);
        $countusers = $DB->get_records_sql($countsql.$fromsql, $searchinfo->searchparams);
        $numusers = count($countusers);
        foreach ($users as $id => $user) {
            $gradeitem = $DB->get_record('grade_items', array('itemtype' => 'course', 'courseid' => $user->courseid));
            $grade = $DB->get_record('grade_grades', array('itemid' => $gradeitem->id, 'userid' => $user->uid));
            if ($grade) {
                $user->result = $grade->finalgrade;
            }
        }

        $returnobj = new stdclass();
        $returnobj->users = $users;
        $returnobj->totalcount = $numusers;

        $dbman->drop_table($table);

        return $returnobj;
    }
}
