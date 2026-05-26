<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/syntax-check
 *
 * Validates a PHP or JSON payload BEFORE the MCP commits it via /fs-write.
 * Eliminates the "AI writes broken functions.php → White Screen of Death"
 * class of regression, and the "AI writes broken theme.json → Site Editor
 * white-page" class. JSON validation also runs Node-side as a fast path;
 * this endpoint is the authoritative server-side check.
 *
 * Body:
 *   { session_token, language: "php"|"json", content }
 *
 * Response (200):
 *   { ok: true }
 *
 * Response (200 with error):
 *   { ok: false, error_code, error_line, error_message }
 *
 * For PHP: writes payload to a temp file under sys_get_temp_dir() with a
 * unique name, runs `php -l <file>` via exec(), parses stdout for "Parse
 * error" / "syntax error", cleans up the temp file. Refuses if exec() is
 * disabled (returns 503 EXEC_DISABLED — caller falls back to "trust the
 * write, hope for the best").
 *
 * For JSON: pure json_decode + json_last_error.
 */
final class SyntaxCheck
{
    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/syntax-check',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'language' => ['required' => true, 'type' => 'string'],
                    'content' => ['required' => true, 'type' => 'string'],
                ],
            ]
        );
    }

    public static function permission(WP_REST_Request $req)
    {
        if (!Config::endpointsEnabled()) {
            return new WP_Error('rolepod_wp_disabled', 'Companion endpoints disabled.', ['status' => 403]);
        }
        if (!current_user_can('manage_options')) {
            return new WP_Error('rolepod_wp_unauthorized', 'manage_options required.', ['status' => 403]);
        }
        return true;
    }

    public static function handle(WP_REST_Request $req): WP_REST_Response
    {
        $userId = get_current_user_id();
        $token = (string) $req->get_param('session_token');
        if (!SessionToken::verify($token, $userId)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'INVALID_OR_EXPIRED_TOKEN',
            ], 401);
        }

        $language = strtolower((string) $req->get_param('language'));
        $content = (string) $req->get_param('content');

        switch ($language) {
            case 'php':
                return self::checkPhp($content);
            case 'json':
                return self::checkJson($content);
            default:
                return new WP_REST_Response([
                    'ok' => false,
                    'error_code' => 'UNSUPPORTED_LANGUAGE',
                    'error_message' => "language must be 'php' or 'json', got '{$language}'",
                ], 400);
        }
    }

    private static function checkPhp(string $content): WP_REST_Response
    {
        if (!function_exists('exec') || in_array('exec', explode(',', (string) ini_get('disable_functions')), true)) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'EXEC_DISABLED',
                'error_message' => "PHP exec() is disabled on this host — cannot run php -l. The MCP will fall back to writing without server-side validation.",
            ], 503);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rolepod_wp_syntax_');
        if ($tmp === false) {
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'TMPFILE_FAILED',
                'error_message' => 'could not create temp file',
            ], 500);
        }
        // Ensure the file ends .php so php -l reads it.
        $tmpPhp = $tmp . '.php';
        @rename($tmp, $tmpPhp);
        if (file_put_contents($tmpPhp, $content) === false) {
            @unlink($tmpPhp);
            return new WP_REST_Response([
                'ok' => false,
                'error_code' => 'TMPFILE_WRITE_FAILED',
                'error_message' => 'could not write temp file',
            ], 500);
        }

        $cmd = 'php -l ' . escapeshellarg($tmpPhp) . ' 2>&1';
        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        @unlink($tmpPhp);

        $stdout = implode("\n", $output);
        if ($exitCode === 0) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $line = null;
        if (preg_match('/on line (\d+)/', $stdout, $m)) {
            $line = (int) $m[1];
        }

        return new WP_REST_Response([
            'ok' => false,
            'error_code' => 'PHP_SYNTAX_ERROR',
            'error_line' => $line,
            'error_message' => trim($stdout),
        ], 200);
    }

    private static function checkJson(string $content): WP_REST_Response
    {
        $decoded = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        // json_last_error() doesn't expose line/col. Approximate by re-scanning.
        // For now we surface the error message verbatim; line discovery is a
        // best-effort offset scan.
        $errMsg = json_last_error_msg();
        $line = null;
        if (preg_match('/at offset (\d+)/', (string) (json_last_error_msg()), $m)) {
            $offset = (int) $m[1];
            $line = substr_count(substr($content, 0, $offset), "\n") + 1;
        }

        return new WP_REST_Response([
            'ok' => false,
            'error_code' => 'JSON_PARSE_ERROR',
            'error_line' => $line,
            'error_message' => $errMsg,
        ], 200);
    }
}
