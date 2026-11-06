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
 * Tests for session-bound GitHub library connections.
 * @package qtype_stack
 * @copyright 2026 Oleksandr Kulkov
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace qtype_stack;

defined('MOODLE_INTERNAL') || die();

/** @covers \qtype_stack\github_library_session */
final class github_library_session_test extends \advanced_testcase {
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        \cache_helper::update_definitions();
        set_config('libraries', json_encode(['test' => ['name' => 'Test',
            'url' => 'https://github.com/example/questions/tree/main']]), 'qtype_stack');
    }

    /** Replacement and removal invalidate previously issued browser handles. */
    public function test_replacement_and_removal(): void {
        github_library_session::connect('test', 'first-secret');
        $first = github_library_session::for_library('test');
        github_library_session::connect('test', 'second-secret');
        $second = github_library_session::for_library('test');
        $this->assertNotSame($first['cacheid'], $second['cacheid']);
        $this->assertSame('second-secret', github_library_session::resolve($second['cacheid'])['token']);
        $this->assert_rejected($first['cacheid']);
        github_library_session::forget('test');
        $this->assert_rejected($second['cacheid']);
        $this->assertSame('', github_library_session::for_library('test')['token']);
    }

    /** Expiry clears token and listing without relying on session garbage collection. */
    public function test_expiry(): void {
        global $SESSION;
        github_library_session::connect('test', 'secret');
        $entry = github_library_session::for_library('test');
        $cache = \cache::make('qtype_stack', 'githublibrary');
        $cache->set($entry['cacheid'] . '_flat_file_list', ['private']);
        $SESSION->qtype_stack_github['test']['expires'] = time() - 1;
        $this->assert_rejected($entry['cacheid']);
        $this->assertFalse($cache->get($entry['cacheid'] . '_flat_file_list'));
        $this->assertSame('', github_library_session::for_library('test')['token']);
    }

    /** Switching user cannot recover the previous user's credential, even with the old handle. */
    public function test_user_isolation(): void {
        github_library_session::connect('test', 'secret');
        $entry = github_library_session::for_library('test');
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assert_rejected($entry['cacheid']);
        $this->assertSame('', github_library_session::for_library('test')['token']);
    }

    /** Configuration changes cannot redirect an existing credential to a different repository. */
    public function test_configuration_change(): void {
        github_library_session::connect('test', 'secret');
        $entry = github_library_session::for_library('test');
        set_config('libraries', json_encode(['test' => ['name' => 'Changed',
            'url' => 'https://github.com/other/repository']]), 'qtype_stack');
        $this->assert_rejected($entry['cacheid']);
        $this->assertSame('', github_library_session::for_library('test')['token']);
    }

    /** Logout or a new session cannot reuse a previous handle. */
    public function test_missing_session(): void {
        global $SESSION;
        github_library_session::connect('test', 'secret');
        $entry = github_library_session::for_library('test');
        unset($SESSION->qtype_stack_github);
        $this->assert_rejected($entry['cacheid']);
    }

    /** File lookup rejects URLs outside the configured repository. */
    public function test_file_scope(): void {
        $entry = github_library_session::for_library('test');
        $url = $entry['apiroot'] . 'git/blobs/' . str_repeat('a', 40);
        $this->assertSame($url, github_library_session::file_url($entry, ['q.xml' => (object)['url' => $url]], 'q.xml'));
        $this->expectException(\moodle_exception::class);
        github_library_session::file_url($entry, ['q.xml' => (object)['url' => str_replace('/example/', '/other/', $url)]], 'q.xml');
    }

    /** Tokens containing header separators are rejected before storage. */
    public function test_invalid_token(): void {
        $this->expectException(\moodle_exception::class);
        github_library_session::connect('test', "bad\r\nheader");
    }

    /**
     * Assert that a stale or foreign handle is rejected.
     * @param string $handle Browser handle
     */
    private function assert_rejected(string $handle): void {
        try {
            github_library_session::resolve($handle);
            $this->fail('Stale connection accepted.');
        } catch (\moodle_exception $e) {
            $this->assertSame('githubsessionexpired', $e->errorcode);
        }
    }
}
