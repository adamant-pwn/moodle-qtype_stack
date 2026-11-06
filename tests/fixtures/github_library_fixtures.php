<?php
namespace qtype_stack;
defined('MOODLE_INTERNAL') || die();
/** No-network render fixture. */
class github_draft_render extends library_render {
    /** @var bool Simulate revoked access */
    public static $deny = false;
    /** @var string|null Credential observed at transport */
    public static $seen = null;

    public static function call_external_request($requestedfile, $external, $apikey) {
        self::$seen = $apikey;
        if (self::$deny) {
            throw new \stack_exception('File unavailable (HTTP 404).');
        }
        return '<quiz><script>private()</script><img src="@@PLUGINFILE@@/private.svg"/></quiz>';
    }

    public static function call_question_render($question) {
        throw new \LogicException('Private source reached the shared renderer.');
    }
}

/** No-network import fixture using real Moodle XML import. */
class github_draft_import extends library_import {
    /** @var array Credentials observed at transport */
    public static $seen = [];

    protected static function fetch_external_file(string $url, string $external, string $token): string {
        self::$seen[] = $token;
        if (str_ends_with($url, str_repeat('7', 40))) {
            return json_encode(['quiz' => ['name' => 'Private quiz', 'intro' => '', 'introformat' => 1,
                'questionsperpage' => 1, 'grade' => 10, 'navmethod' => 'free'], 'sections' => [], 'feedback' => [],
                'questions' => [['slot' => 1, 'page' => 1, 'requireprevious' => 0, 'maxmark' => 0,
                    'quizfilepath' => '/top/a.xml']]]);
        }
        if (str_ends_with($url, str_repeat('8', 40))) {
            return '<quiz><question type="category"><category><text>Private category</text></category></question></quiz>';
        }
        return '<quiz><question type="description"><name><text>Private example</text></name>'
            . '<questiontext format="html"><text>Private content</text></questiontext></question></quiz>';
    }
}
