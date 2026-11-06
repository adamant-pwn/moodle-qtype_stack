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
 * Session credentials and file lists for configured GitHub libraries.
 *
 * @package qtype_stack
 * @copyright 2026 Oleksandr Kulkov
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace qtype_stack;

defined('MOODLE_INTERNAL') || die();

/** Keep GitHub credentials and their derived state within the current login session. */
final class github_library_session {
    /** Maximum lifetime of a manually entered token, in seconds. */
    private const LIFETIME = 3600;

    /**
     * Return the current connection, replacing expired or reconfigured entries.
     * @param string $id Configured library id
     * @return array Connection, for server-side use only
     */
    public static function for_library(string $id): array {
        global $SESSION, $USER;
        if (!isloggedin() || isguestuser() || \core\session\manager::is_loggedinas()) {
            throw new \moodle_exception('githubsessionexpired', 'qtype_stack');
        }
        $libraries = json_decode(get_config('qtype_stack', 'libraries'), true) ?: [];
        $url = $libraries[$id]['url'] ?? '';
        if (!preg_match('~^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)(?:/tree/[^?#]+)?/?$~D', $url, $match)) {
            throw new \moodle_exception('githubsessionexpired', 'qtype_stack');
        }
        $fingerprint = hash('sha256', $url);
        $entries = $SESSION->qtype_stack_github ?? [];
        $entry = $entries[$id] ?? null;
        if ($entry && ($entry['userid'] !== (int)$USER->id || $entry['config'] !== $fingerprint
                || $entry['expires'] <= time())) {
            self::forget($id);
            $entry = null;
        }
        if (!$entry) {
            $entry = ['id' => $id, 'userid' => (int)$USER->id, 'config' => $fingerprint,
                'url' => $url, 'apiroot' => 'https://api.github.com/repos/' . $match[1] . '/' . $match[2] . '/',
                'cacheid' => 'githublibrary_' . bin2hex(random_bytes(16)),
                'token' => '', 'expires' => time() + self::LIFETIME];
            $SESSION->qtype_stack_github[$id] = $entry;
        }
        return $entry;
    }

    /**
     * Replace the current credential. The caller must validate POST, sesskey and capabilities.
     * @param string $id Configured library id
     * @param string $token Personal access token
     */
    public static function connect(string $id, string $token): void {
        global $SESSION;
        if ($token === '' || strlen($token) > 255 || preg_match('/[^\x21-\x7e]/', $token)) {
            throw new \moodle_exception('githubtokeninvalid', 'qtype_stack');
        }
        self::for_library($id);
        self::forget($id);
        $entry = self::for_library($id);
        $entry['token'] = $token;
        $SESSION->qtype_stack_github[$id] = $entry;
    }

    /**
     * Forget a credential and the file list derived from it.
     * @param string $id Configured library id
     */
    public static function forget(string $id): void {
        global $SESSION;
        $entry = $SESSION->qtype_stack_github[$id] ?? null;
        if ($entry) {
            \cache::make('qtype_stack', 'githublibrary')->delete($entry['cacheid'] . '_flat_file_list');
            unset($SESSION->qtype_stack_github[$id]);
        }
    }

    /**
     * Resolve an opaque browser handle within the current user's session.
     * @param string $cacheid Browser handle
     * @return array Current connection
     */
    public static function resolve(string $cacheid): array {
        global $SESSION;
        foreach ($SESSION->qtype_stack_github ?? [] as $id => $entry) {
            if (hash_equals($entry['cacheid'], $cacheid)) {
                $current = self::for_library((string)$id);
                if (hash_equals($current['cacheid'], $cacheid)) {
                    return $current;
                }
            }
        }
        throw new \moodle_exception('githubsessionexpired', 'qtype_stack');
    }

    /**
     * Resolve a file only from this session's listing and the configured repository.
     * @param array $connection Current server-side connection
     * @param array $files Flat file list
     * @param string $path Relative path
     * @return string GitHub blob URL
     */
    public static function file_url(array $connection, array $files, string $path): string {
        $url = $files[$path]->url ?? '';
        $prefix = $connection['apiroot'] . 'git/blobs/';
        if (!str_starts_with($url, $prefix) || !preg_match('/^[a-f0-9]{40,64}$/D', substr($url, strlen($prefix)))) {
            throw new \moodle_exception('githubsessionexpired', 'qtype_stack');
        }
        return $url;
    }
}
