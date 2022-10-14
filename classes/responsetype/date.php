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

namespace mod_questionnaire\responsetype;

use mod_questionnaire\db\bulk_sql_config;

/**
 * Class for date response types.
 *
 * @author Mike Churchward
 * @copyright 2016 onward Mike Churchward (mike.churchward@poetopensource.org)
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package mod_questionnaire
 */
class date extends responsetype {
    /**
     * Provide the necessary response data table name. Should probably always be used with late static binding 'static::' form
     * rather than 'self::' form to allow for class extending.
     *
     * @return string response table name.
     */
    public static function response_table() {
        return 'questionnaire_response_date';
    }

    /**
     * Provide an array of answer objects from web form data for the question.
     *
     * @param \stdClass $responsedata All of the responsedata as an object.
     * @param \mod_questionnaire\question\question $question
     * @return array \mod_questionnaire\responsetype\answer\answer An array of answer objects.
     */
    public static function answers_from_webform($responsedata, $question) {
        $answers = [];
        if (isset($responsedata->{'q'.$question->id}) && !empty($responsedata->{'q'.$question->id})) {
            $record = new \stdClass();
            $record->responseid = $responsedata->rid;
            $record->questionid = $question->id;
            $record->value = $responsedata->{'q' . $question->id};
            $answers[] = answer\answer::create_from_data($record);
        }
        return $answers;
    }

    /**
     * Provide an array of answer objects from mobile data for the question.
     *
     * @param \stdClass $responsedata All of the responsedata as an object.
     * @param \mod_questionnaire\question\question $question
     * @return array \mod_questionnaire\responsetype\answer\answer An array of answer objects.
     */
    public static function answers_from_appdata($responsedata, $question) {
        if (isset($responsedata->{'q'.$question->id}) && !empty($responsedata->{'q'.$question->id})) {
            // The app can send the date including time (e.g. 2021-06-28T09:03:46.613+02:00), get only the date.
            $responsedata->{'q'.$question->id} = substr($responsedata->{'q'.$question->id}[0], 0, 10);
        }
        return static::answers_from_webform($responsedata, $question);
    }

    /**
     * Insert a provided response to the question.
     *
     * @param object $responsedata All of the responsedata as an object.
     * @return int|bool - on error the subtype should call set_error and return false.
     */
    public function insert_response($responsedata) {
        global $DB;

        if (!$responsedata instanceof \mod_questionnaire\responsetype\response\response) {
            $response = \mod_questionnaire\responsetype\response\response::response_from_webform($responsedata, [$this->question]);
        } else {
            $response = $responsedata;
        }

        if (!empty($response) && isset($response->answers[$this->question->id][0])) {
            $thisdate = $response->answers[$this->question->id][0]->value;
            if (!$this->question->check_date_format($thisdate)) {
                return false;
            }
            // Now use ISO date formatting.
            $record = new \stdClass();
            $record->response_id = $response->id;
            $record->question_id = $this->question->id;
            $record->response = $thisdate;
            return $DB->insert_record(self::response_table(), $record);
        } else {
            return false;
        }
    }

    /**
     * Provide the result information for the specified result records.
     *
     * @param int|array $rids - A single response id, or array.
     * @param boolean $anonymous - Whether or not responses are anonymous.
     * @return array - Array of data records.
     */
    public function get_results($rids=false, $anonymous=false) {
        global $DB;

        $rsql = '';
        $params = array($this->question->id);
        if (!empty($rids)) {
            list($rsql, $rparams) = $DB->get_in_or_equal($rids);
            $params = array_merge($params, $rparams);
            $rsql = ' AND response_id ' . $rsql;
        }

        $sql = 'SELECT id, response ' .
               'FROM {'.static::response_table().'} ' .
               'WHERE question_id= ? ' . $rsql;

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Provide a template for results screen if defined.
     * @param bool $pdf
     * @return mixed The template string or false/
     */
    public function results_template($pdf = false) {
        if ($pdf) {
            return 'mod_questionnaire/resultspdf_date';
        } else {
            return 'mod_questionnaire/results_date';
        }
    }

    /**
     * Provide the result information for the specified result records.
     *
     * @param int|array $rids - A single response id, or array.
     * @param string $sort - Optional display sort.
     * @param boolean $anonymous - Whether or not responses are anonymous.
     * @return string - Display output.
     */
    public function display_results($rids=false, $sort='', $anonymous=false) {
        $numresps = count($rids);
        if ($rows = $this->get_results($rids, $anonymous)) {
            $numrespondents = count($rows);
            $counts = [];
            foreach ($rows as $row) {
                // Count identical answers (case insensitive).
                if (!empty($row->response)) {
                    $dateparts = preg_split('/-/', $row->response);
                    $text = make_timestamp($dateparts[0], $dateparts[1], $dateparts[2]); // Unix timestamp.
                    $textidx = clean_text($text);
                    $counts[$textidx] = !empty($counts[$textidx]) ? ($counts[$textidx] + 1) : 1;
                }
            }
            $pagetags = $this->get_results_tags($counts, $numresps, $numrespondents);
        } else {
            $pagetags = new \stdClass();
        }
        return $pagetags;
    }

    /**
     * Gets the results tags for templates for questions with defined choices (single, multiple, boolean).
     *
     * @param arrays $weights
     * @param int $participants Number of questionnaire participants.
     * @param int $respondents Number of question respondents.
     * @param int $showtotals
     * @param string $sort
     * @return \stdClass
     */
    public function get_results_tags($weights, $participants, $respondents, $showtotals = 1, $sort = '') {
        $dateformat = get_string('strfdate', 'questionnaire');

        $pagetags = new \stdClass();
        if ($respondents == 0) {
            return $pagetags;
        }

        if (!empty($weights) && is_array($weights)) {
            $pagetags->responses = [];
            $numresps = 0;
            ksort ($weights); // Sort dates into chronological order.
            $evencolor = false;
            foreach ($weights as $content => $num) {
                $response = new \stdClass();
                $response->text = userdate($content, $dateformat, '', false);    // Change timestamp into readable dates.
                $numresps += $num;
                $response->total = $num;
                // The 'evencolor' attribute is used by the PDF template.
                $response->evencolor = $evencolor;
                $pagetags->responses[] = (object)['response' => $response];
                $evencolor = !$evencolor;
            }

            if ($showtotals == 1) {
                $pagetags->total = new \stdClass();
                $pagetags->total->total = "$numresps/$participants";
            }
        }

        return $pagetags;
    }

    /**
     * Return an array of answers by question/choice for the given response. Must be implemented by the subclass.
     *
     * @param int $rid The response id.
     * @return array
     */
    public static function response_select($rid) {
        global $DB;

        $values = [];
        $sql = 'SELECT q.id, q.content, a.response as aresponse '.
            'FROM {'.static::response_table().'} a, {questionnaire_question} q '.
            'WHERE a.response_id=? AND a.question_id=q.id ';
        $records = $DB->get_records_sql($sql, [$rid]);
        $dateformat = get_string('strfdate', 'questionnaire');
        foreach ($records as $qid => $row) {
            unset ($row->id);
            $row = (array)$row;
            $newrow = array();
            foreach ($row as $key => $val) {
                if (!is_numeric($key)) {
                    $newrow[] = $val;
                    // Convert date from yyyy-mm-dd database format to actual questionnaire dateformat.
                    // does not work with dates prior to 1900 under Windows.
                    if (preg_match('/\d\d\d\d-\d\d-\d\d/', $val)) {
                        $dateparts = preg_split('/-/', $val);
                        $val = make_timestamp($dateparts[0], $dateparts[1], $dateparts[2]); // Unix timestamp.
                        $val = userdate ( $val, $dateformat);
                        $newrow[] = $val;
                    }
                }
            }
            $values["$qid"] = $newrow;
            $val = array_pop($values["$qid"]);
            array_push($values["$qid"], '', '', $val);
        }

        return $values;
    }

    /**
     * Return an array of answer objects by question for the given response id.
     * THIS SHOULD REPLACE response_select.
     *
     * @param int $rid The response id.
     * @return array array answer
     * @throws \dml_exception
     */
    public static function response_answers_by_question($rid) {
        global $DB;

        $answers = [];
        $sql = 'SELECT id, response_id as responseid, question_id as questionid, 0 as choiceid, response as value ' .
            'FROM {' . static::response_table() .'} ' .
            'WHERE response_id = ? ';
        $records = $DB->get_records_sql($sql, [$rid]);
        foreach ($records as $record) {
            // Leave the date format in data storage format.
            $answers[$record->questionid][] = answer\answer::create_from_data($record);
        }

        return $answers;
    }

    /**
     * Configure bulk sql
     * @return bulk_sql_config
     */
    protected function bulk_sql_config() {
        return new bulk_sql_config(static::response_table(), 'qrd', false, true, false);
    }
    
    /**
     * Return sql and params for getting responses in bulk.
     * @param int|array $questionnaireids One id, or an array of ids.
     * @param bool|int $responseid
     * @param bool|int $userid
     * @param bool|int $groupid
     * @param int $showincompletes
     * @return array
     * author Guy Thomas
     */
    public function get_bulk_sql($questionnaireids, $responseid = false, $userid = false, $groupid = false, $showincompletes = 0) {
        global $DB;
        
        $sql = $this->bulk_sql();
        if (($groupid !== false) && ($groupid > 0)) {
            $groupsql = ' INNER JOIN {groups_members} gm ON gm.groupid = ? AND gm.userid = qr.userid ';
            $gparams = [$groupid];
        } else {
            $groupsql = '';
            $gparams = [];
        }
        
        if (is_array($questionnaireids)) {
            list($qsql, $params) = $DB->get_in_or_equal($questionnaireids);
        } else {
            $qsql = ' = ? ';
            $params = [$questionnaireids];
        }
        if ($showincompletes == 1) {
            $showcompleteonly = '';
        } else {
            $showcompleteonly = 'AND qr.complete = ? ';
            $params[] = 'y';
        }
        
        $sql .= "
            AND qr.questionnaireid $qsql $showcompleteonly
      LEFT JOIN {questionnaire_response_other} qro ON qro.response_id = qr.id
      LEFT JOIN {user} u ON u.id = qr.userid
      $groupsql
        ";
      $params = array_merge($params, $gparams);
      
      if ($responseid) {
          $sql .= " WHERE qr.id = ?";
          $params[] = $responseid;
      } else if ($userid) {
          $sql .= " WHERE qr.userid = ?";
          $params[] = $userid;
      }
      
      $addjoinsql = '';
      
      //あなたの回答からの導線の場合、$useridが設定される
      $enableuniquserresponse = intval(get_config('questionnaire', 'enableuniquserresponse'));
      if($enableuniquserresponse === 1 && empty($userid)){
          $addjoinsql1 = <<< "EOT"
    JOIN (
        SELECT
            rsd.question_id
            ,r.userid
            ,MAX(r.submitted) as submitted
        FROM {questionnaire_response} r 
        JOIN {questionnaire_response_date} rsd ON r.id = rsd.response_id
EOT;
          $addjoinsql2 = <<< "EOT"
            GROUP BY rsd.question_id, r.userid
    ) a ON a.question_id = qrd.question_id and a.submitted = qr.submitted and a.userid = u.id
EOT;
          
          $sql .= $addjoinsql1;
          if ($showincompletes == 1) {
              $sql .= "    WHERE r.complete = 'y'";
          }
          $sql .= $addjoinsql2;
      }
      
      return [$sql, $params];
    }
}
