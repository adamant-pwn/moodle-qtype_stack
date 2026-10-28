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


namespace qtype_stack\task;

/**
 * Computes the full "Analyze responses" analysis in the background and caches it.
 *
 * @package   qtype_stack
 * @copyright 2026 ETH Zürich
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class question_report_task extends \core\task\adhoc_task {
    /**
     * Run the report and store its output data under the cache key it was queued for.
     */
    public function execute() {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/type/stack/stack/questionreport.class.php');
        $data = $this->get_custom_data();
        $question = \question_bank::load_question($data->questionid);
        $report = new \stack_question_report($question, (int) $data->quizcontextid, (int) $data->coursecontextid);
        \cache::make('qtype_stack', 'questionreport')->set($data->cachekey, $report->outputdata);
    }
}
