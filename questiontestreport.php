<?php
// This file is part of Stack - http://stack.maths.ed.ac.uk/
//
// Stack is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Stack is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with STACK.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This script lets the user see what attempts have been made at this question.
 *
 * The script loops over summarise_response data from the database, and does not
 * re-generate reports.  The script is designed to let a question author improve feedback
 * and assessment by looking at what students type, easily and without going through a quiz report.
 *
 * @package    qtype_stack
 * @copyright  2020 the University of Edinburgh
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/questionlib.php');
require_once(__DIR__ . '/vle_specific.php');
require_once(__DIR__ . '/stack/questionreport.class.php');
require_login();

// Get the parameters from the URL.
$questionid = required_param('questionid', PARAM_INT);
[$qversion, $questionid] = get_latest_question_version($questionid);
$quizcontext = optional_param('context', null, PARAM_INT);
// By default list each student's tries from the stored step data; 'full' runs the CAS-based analysis.
$mode = optional_param('mode', 'tries', PARAM_ALPHA);
// Load the necessary data.
$questiondata = question_bank::load_question_data($questionid);
if (!$questiondata) {
    throw new stack_exception('questiondoesnotexist');
}
$question = question_bank::load_question($questionid);

// Process any other URL parameters, and do require_login.
[$context, $seed, $urlparams] = qtype_stack_setup_question_test_page($question);

// Check permissions.
question_require_capability_on($questiondata, 'view');
$canedit = question_has_capability_on($questiondata, 'edit');

// Initialise $PAGE.
$PAGE->set_context($context);
$PAGE->set_url('/question/type/stack/questiontestreport.php', $urlparams);
$title = stack_string('basicquestionreport');
$PAGE->set_title($title);
$PAGE->set_heading($title);

// This layout has minimal header/footer.
$PAGE->set_pagelayout('popup');

$testquestionlink = new moodle_url('/question/type/stack/questiontestrun.php', $urlparams);
$qurl = qbank_previewquestion\helper::question_preview_url($questionid, null, null, null, null, $context);
$editparams = $urlparams;
unset($editparams['questionid']);
unset($editparams['seed']);
$editparams['id'] = $question->id;
$questioneditlatesturl = new moodle_url('/question/type/stack/questioneditlatest.php', $editparams);

require_login();

// Get quizzes in which the course is used.
// Add data for creating quiz selection dropdown.
$quizzes = stack_question_report::get_relevant_quizzes($questionid, (int) $question->contextid);

// The list of tries can be downloaded; this must happen before any output.
$download = optional_param('download', '', PARAM_ALPHA);
if ($quizcontext !== null && $download !== '' && isset($quizzes[$quizcontext])) {
    // Named student answers: the same capability as the quiz's own Responses report.
    $quizctx = context::instance_by_id($quizcontext);
    require_capability('mod/quiz:viewreports', $quizctx);
    $tries = stack_question_report::format_tries(
        stack_question_report::load_tries($questionid, $quizcontext, $quizzes[$quizcontext]->coursecontextid,
            stack_question_report::visible_userids($quizctx)), $quizctx);
    $columns = ['student', 'try', 'variant', 'time', 'answer', 'state', 'mark'];
    // Spreadsheets run cells starting with =, +, -, @, tab or CR as formulas; neutralise them.
    $safe = fn($cell) => preg_match('/^[=+\-@\t\r]/', (string) $cell) ? "'" . $cell : $cell;
    \core\dataformat::download_data('stack-tries-' . $questionid, $download, array_combine($columns, $columns),
        new ArrayIterator($tries->rows), fn($row) => array_map($safe, array_values((array) $row)));
    die;
}

// Start output.
echo $OUTPUT->header();
$quizoutput = [];
foreach ($quizzes as $contextid => $quiz) {
    $quiz->url = new moodle_url(
        '/question/type/stack/questiontestreport.php',
        $urlparams + ['context' => $quiz->quizcontextid]
    );
    $quiz->url = $quiz->url->out();
    $quiz->active = ($contextid === $quizcontext) ? true : false;
    $quizoutput[] = $quiz;
}

if ($quizcontext === null) {
    // No quiz selected so we can only show question data.
    $outputdata = new StdClass();
    $outputdata->question = stack_question_report::format_question_data($question);
} else {
    $coursecontextid = $quizzes[$quizcontext]->coursecontextid;
    $reporturl = new moodle_url('/question/type/stack/questiontestreport.php', $urlparams + ['context' => $quizcontext]);
    if ($mode === 'full') {
        // The full analysis evaluates responses with the CAS. Serve it from the cache, compute it here for
        // smaller quizzes, or compute it in the background so that the request cannot time out.
        $cache = cache::make('qtype_stack', 'questionreport');
        $cachekey = stack_question_report::cache_key($questionid, $quizcontext);
        $outputdata = $cache->get($cachekey);
        if ($outputdata === false && stack_question_report::attempts_from_key($cachekey) <= 200) {
            $report = new stack_question_report($question, $quizcontext, $coursecontextid);
            $outputdata = $report->outputdata;
            $cache->set($cachekey, $outputdata);
        }
        if ($outputdata === false) {
            $task = new \qtype_stack\task\question_report_task();
            $task->set_custom_data(['questionid' => $questionid, 'quizcontextid' => $quizcontext,
                'coursecontextid' => $coursecontextid, 'cachekey' => $cachekey]);
            \core\task\manager::queue_adhoc_task($task, true);
            $outputdata = new StdClass();
            $outputdata->question = stack_question_report::format_question_data($question);
            $outputdata->computing = true;
        }
    } else {
        $outputdata = new StdClass();
        $outputdata->question = stack_question_report::format_question_data($question);
        // Named student answers: the same capability as the quiz's own Responses report.
        $quizctx = context::instance_by_id($quizcontext);
        require_capability('mod/quiz:viewreports', $quizctx);
        $outputdata->tries = stack_question_report::format_tries(
            stack_question_report::load_tries($questionid, $quizcontext, $coursecontextid,
                stack_question_report::visible_userids($quizctx)), $quizctx);
    }
    $outputdata->triesurl = $reporturl->out(false);
    $outputdata->fullurl = (new moodle_url($reporturl, ['mode' => 'full']))->out(false);
    $outputdata->csvurl = (new moodle_url($reporturl, ['download' => 'csv']))->out(false);
}

$outputdata->question->version = $qversion;

// Add additional page creation data.
$outputdata->quizzes = $quizoutput;
$outputdata->general = new Stdclass();
$outputdata->general->testquestionlink = $testquestionlink->out();
$outputdata->general->previewquestionlink = $qurl->out();
$outputdata->general->editquestionlink = $questioneditlatesturl->out();

// Rennder report.
echo $OUTPUT->render_from_template('qtype_stack/questionreport', $outputdata);
echo $OUTPUT->footer();
