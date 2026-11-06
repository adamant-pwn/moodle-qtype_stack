<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is free software under the GNU GPL v3 or later.
namespace qtype_stack;

defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/../stack/questionlibrary.class.php');

/**
 * Encrypted site credentials and the CLI/web boundary.
 * @package qtype_stack
 * @copyright 2026 Oleksandr Kulkov
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \stack_question_library
 */
final class github_admin_token_test extends \advanced_testcase {
    public function test_setting_storage_rotation_and_removal(): void {
        global $DB, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        $settings = new \admin_settingpage('stacktokenfixture', 'STACK');
        require(__DIR__ . '/../settings.php');
        $setting = $settings->settings->qtype_stackgithubtoken;
        $cache = \cache::make('qtype_stack', 'librarycache');
        foreach (['first-pat-secret', 'second-pat-secret', ''] as $token) {
            $previous = $setting->get_setting();
            $cache->set('private-list', 'cached');
            $this->assertSame('', $setting->write_setting($token));
            $this->assertTrue($setting->post_write_settings($previous));
            $this->assertFalse($cache->get('private-list'));
            $stored = get_config('qtype_stack', 'githubtoken');
            if ($token !== '') {
                $this->assertNotSame($token, $stored);
                $this->assertSame($token, \core\encryption::decrypt($stored));
                $this->assertStringNotContainsString($token, $setting->output_html($stored, ''));
                foreach ($DB->get_records('config_log', ['plugin' => 'qtype_stack', 'name' => 'githubtoken']) as $log) {
                    $this->assertStringNotContainsString($token, $log->value . $log->oldvalue);
                }
            } else {
                $this->assertSame('', $stored);
            }
            $headers = $this->web_headers($stored);
            $this->assertSame($token === '' ? [] : ['Authorization: Bearer ' . $token],
                array_values(array_filter($headers, static fn($h) => str_starts_with($h, 'Authorization:'))));
        }
    }

    public function test_cli_uses_only_environment(): void {
        $this->resetAfterTest();
        set_config('githubtoken', \core\encryption::encrypt('site-pat-secret'), 'qtype_stack');
        $previous = getenv('STACK_GITHUB_TOKEN');
        try {
            $method = new \ReflectionMethod(\stack_question_library::class, 'github_headers');
            putenv('STACK_GITHUB_TOKEN');
            $this->assertCount(2, $method->invoke(null));
            putenv('STACK_GITHUB_TOKEN=ci-token');
            $this->assertContains('Authorization: Bearer ci-token', $method->invoke(null));
        } finally {
            putenv($previous === false ? 'STACK_GITHUB_TOKEN' : 'STACK_GITHUB_TOKEN=' . $previous);
        }
    }

    public function test_encrypted_header_injection_is_not_sent(): void {
        $this->resetAfterTest();
        $this->assertCount(2, $this->web_headers(\core\encryption::encrypt("bad\r\nheader")));
    }

    /** Real encryption/decryption; the child only supplies configuration without bootstrapping a web session. */
    private function web_headers(string $encrypted): array {
        global $CFG;
        $script = 'define("MOODLE_INTERNAL", true); define("CLI_SCRIPT", false); define("PHPUNIT_TEST", true);'
            . '$CFG = (object)' . var_export(['dataroot' => $CFG->dataroot,
                'secretdataroot' => $CFG->secretdataroot ?? $CFG->dataroot . '/secret'], true) . ';'
            . 'function get_config($component, $name) { return ' . var_export($encrypted, true) . '; }'
            . 'require ' . var_export($CFG->libdir . '/classes/encryption.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../stack/questionlibrary.class.php', true) . ';'
            . 'putenv("STACK_GITHUB_TOKEN=ignored-server-secret");'
            . '$method = new ReflectionMethod("stack_question_library", "github_headers");'
            . 'echo json_encode($method->invoke(null));';
        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
