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
 * Tests for authenticated library previews and imports.
 * @package qtype_stack
 * @copyright 2026 Oleksandr Kulkov
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace qtype_stack;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');


/**
 * @runTestsInSeparateProcesses
 * @covers \qtype_stack\library_render
 * @covers \qtype_stack\library_import
 */
final class github_library_workflow_test extends \externallib_advanced_testcase {
    /** @var object Test course */
    private $course;
    /** @var object Target category */
    private $category;
    /** @var array Connection */
    private $connection;

    protected function setUp(): void {
        parent::setUp();
        require_once(__DIR__ . '/fixtures/github_library_fixtures.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        \cache_helper::update_definitions();
        set_config('libraries', json_encode(['test' => ['name' => 'Test',
            'url' => 'https://github.com/example/questions']]), 'qtype_stack');
        github_library_session::connect('test', 'user-token');
        $this->connection = github_library_session::for_library('test');
        $this->course = $this->getDataGenerator()->create_course();
        $this->category = $this->getDataGenerator()->get_plugin_generator('core_question')->create_question_category(
            ['contextid' => \context_course::instance($this->course->id)->id]);
        $files = [];
        foreach (['a.xml', 'b.xml'] as $index => $path) {
            $files[$path] = (object)['url' => $this->connection['apiroot'] . 'git/blobs/' . str_repeat((string)$index, 40)];
        }
        \cache::make('qtype_stack', 'githublibrary')->set($this->connection['cacheid'] . '_flat_file_list', $files);
        github_draft_render::$deny = false;
        github_draft_render::$seen = null;
        github_draft_import::$seen = [];
    }

    /** Authenticated previews remain escaped source and never call the CAS renderer. */
    public function test_source_preview(): void {
        $result = github_draft_render::render_execute($this->category->id, 'a.xml', $this->connection['cacheid'], 'forged-token');
        $result = \external_api::clean_returnvalue(github_draft_render::render_execute_returns(), $result);
        $this->assertSame('user-token', github_draft_render::$seen);
        $this->assertSame([], $result['iframes']);
        $this->assertStringContainsString('&lt;script&gt;', $result['questionrender']);
        $this->assertStringNotContainsString('<script>', $result['questionrender']);
        $this->assertStringNotContainsString('plot.php', $result['questionrender']);
        $this->assertFalse(\cache::make('qtype_stack', 'librarycache')->get($this->connection['cacheid'] . '/a.xml'));
    }

    /** A previous preview cannot mask a later access failure. */
    public function test_revoked_access(): void {
        github_draft_render::render_execute($this->category->id, 'a.xml', $this->connection['cacheid'], '');
        github_draft_render::$deny = true;
        $this->expectException(\stack_exception::class);
        github_draft_render::render_execute($this->category->id, 'a.xml', $this->connection['cacheid'], '');
    }

    /** Cross-user handles are rejected before fetching any private content. */
    public function test_foreign_handle(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user();
        $role = $DB->get_field('role', 'id', ['shortname' => 'manager']);
        role_assign($role, $user->id, \context_system::instance()->id);
        $this->setUser($user);
        try {
            github_draft_render::render_execute($this->category->id, 'a.xml', $this->connection['cacheid'], '');
            $this->fail('Foreign handle accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('githubsessionexpired', $e->errorcode);
            $this->assertNull(github_draft_render::$seen);
        }
    }

    /** Import uses the server credential and stores ordinary question-bank content. */
    public function test_single_import(): void {
        global $DB;
        $result = github_draft_import::import_execute($this->course->id, $this->category->id,
            'a.xml', false, $this->connection['cacheid'], 'forged-token');
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->success);
        $this->assertSame(['user-token'], github_draft_import::$seen);
        github_library_session::forget('test');
        $this->assertTrue($DB->record_exists('question', ['id' => $result[0]->questionid]));
    }

    /** Quiz metadata, category files and question files all use the same credential. */
    public function test_quiz_import(): void {
        $files = [];
        foreach (['quiz/test_quiz.json', 'quiz/top/gitsync_category.xml', 'quiz/top/a.xml'] as $index => $path) {
            $files[$path] = (object)['url' => $this->connection['apiroot'] . 'git/blobs/' . str_repeat((string)($index + 7), 40)];
        }
        \cache::make('qtype_stack', 'githublibrary')->set($this->connection['cacheid'] . '_flat_file_list', $files);
        $result = github_draft_import::import_execute($this->course->id, $this->category->id,
            'quiz/test_quiz.json', false, $this->connection['cacheid'], '');
        $this->assertCount(2, $result);
        $this->assertSame(['user-token', 'user-token', 'user-token'], github_draft_import::$seen);
        foreach ($result as $item) {
            $this->assertTrue($item->success);
        }
    }

    /** Folder import uses the same identity for every member. */
    public function test_folder_import(): void {
        $result = github_draft_import::import_execute($this->course->id, $this->category->id,
            'a.xml', true, $this->connection['cacheid'], '');
        $this->assertCount(2, $result);
        $this->assertSame(['user-token', 'user-token'], github_draft_import::$seen);
        foreach ($result as $question) {
            $this->assertTrue($question->success);
        }
    }
}
