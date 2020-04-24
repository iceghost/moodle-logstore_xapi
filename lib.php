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

defined('MOODLE_INTERNAL') || die();

define('XAPI_REPORT_ID_ERROR', 0);
define('XAPI_REPORT_ID_HISTORIC', 1);
/**
 * Get all visible cohorts in the system.
 *
 * @return array Returns an array of all visible cohorts.
 */
function logstore_xapi_get_cohorts() {
    global $DB;
    $array = array("visible" => 1);
    $cohorts = $DB->get_records("cohort", $array);
    return $cohorts;
}

/**
 * Get the selected cohorts from the settings.
 *
 * @return array Returns an array of selected cohort ids if the cohort is still visible.
 * The cohort might have been made invisible or removed since the selection was made.
 */
function logstore_xapi_get_selected_cohorts() {
    $arrvisible = logstore_xapi_get_cohorts();
    $selected = get_config('logstore_xapi', 'cohorts');

    $arrselected = explode(",", $selected);
    $arr = array();
    foreach ($arrselected as $arrselection) {
        if (array_key_exists($arrselection, $arrvisible)) {
            $arr[] = $arrselection;
        }
    }
    return $arr;
}

/**
 * Return all members for a cohort
 *
 * @param array $cohortids array of cohort ids
 * @return array with cohort id keys containing arrays of user email addresses
 */
function logstore_xapi_get_cohort_members($cohortids) {
    global $DB;

    $members = array();

    foreach ($cohortids as $cohortid) {
        // Validate params.
        $cohort = $DB->get_record('cohort', array('id' => $cohortid), '*', MUST_EXIST);
        if (!empty($cohort)) {
            $cohortmembers = $DB->get_records_sql("SELECT u.id, u.email
                FROM {user} u, {cohort_members} cm
                WHERE u.id = cm.userid AND cm.cohortid = ?
                ORDER BY lastname ASC, firstname ASC", array($cohort->id));
            $emailaddresses = array();
            foreach ($cohortmembers as $member) {
                $emailaddresses[] = $member->email;
            }
            $members[] = array('cohortid' => $cohortid, 'emails' => $emailaddresses);
        }
    }
    return $members;
}

/**
 * Get the selected cohorts from the settings.
 *
 * @return array Returns an array of distinct email addresses from cohorts and additional email addresses.
 */
function logstore_xapi_distinct_email_addresses() {
    $arr = array();

    // ensure no duplicates in csv
    $emailaddresses = get_config('logstore_xapi', 'send_additional_email_addresses');
    $arrselected = explode(",", $emailaddresses);
    foreach ($arrselected as $arrselection) {
        if (!in_array($arrselection, $arr)) {
            $arr[] = $arrselection;
        }
    }

    // get selected cohorts
    $cohorts = logstore_xapi_get_selected_cohorts();
    $cohortswithmembers = logstore_xapi_get_cohort_members($cohorts);

    // add to the list again ensuring no duplicates
    foreach ($cohortswithmembers as $cohort) {
        foreach ($cohort["emails"] as $email) {
            if (!in_array($email, $arr)) {
                $arr[] = $email;
            }
        }
    }

    // sort it for logging purposes later
    sort($arr);
    return $arr;
}

/**
 * Gets the unique column values
 *
 * @param $column
 * @return array
 * @throws dml_exception
 */
function logstore_xapi_get_distinct_options_from_failed_table($column) {
    global $DB;

    $options = [0 => get_string('any')];
    $results = $DB->get_fieldset_select('logstore_xapi_failed_log', "DISTINCT $column", '');
    if ($results) {
        // The array keys by default are numbered, here we will assign the values to the keys
        $results = array_combine($results, $results);
        $options = array_merge($options, $results);
    }
    return $options;
}

/**
 * Decode the json array stored in the response column. Will return false if json is invalid
 *
 * @param $response
 * @return array|bool
 */
function logstore_xapi_decode_response($response) {
    $decode = json_decode($response, true);
    // Check JSON is valid
    if (json_last_error() === JSON_ERROR_NONE) {
        return $decode;
    }
    return false;
}

/**
 * Generate the string for the info column in the report
 *
 * @param $row
 * @return string
 * @throws coding_exception
 */
function logstore_xapi_get_info_string($row) {
    if (!empty($row->errortype)) {
        $response = '-';
        if (isset($row->response)) {
            $decode = logstore_xapi_decode_response($row->response);
            if ($decode) {
                $response = $decode;
            }
        }
        switch ($row->errortype) {
            case 101:
                return get_string('networkerror', 'logstore_xapi', $response);
            case 400:
                // Recipe issue
                return get_string('recipeerror', 'logstore_xapi', $response);
            case 401:
                // Unauthorised, could be an issue with xAPI credentials
                return get_string('autherror', 'logstore_xapi', $response);
            case 500:
                // xAPI server error
                return get_string('lrserror', 'logstore_xapi', $response);
            default:
                // Generic error catch all
                return get_string('unknownerror', 'logstore_xapi', ['errortype' => $row->errortype, 'response' => $response]);
                break;
        }
    }
    return ''; // Return blank if no errortype captured
}