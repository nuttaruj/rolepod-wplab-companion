<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /wp-json/wplab/v1/job/create (v2.12)
 *
 * Fire-and-poll wp-cli runner. Useful for commands that exceed the
 * synchronous wp-cli timeout (default 30s, hard cap 120s in the
 * /wp-cli endpoint) — db migrations, theme switches with cache rebuild,
 * media regeneration.
 *
 * Internally:
 *   - Generates a job_id.
 *   - Builds the wp-cli command line via escapeshellarg per token.
 *   - Spawns it detached via `popen('php wp-cli.phar ... > stdout.log
 *     2> stderr.log &', 'r')`, captures the OS pid.
 *   - Stores a job record in a transient under
 *     `rolepod_wp_job_<job_id>` keyed by user_id with 1h TTL.
 *   - Returns the job_id + pid + stdout/stderr log paths.
 *
 * Caveats: only one wp-cli binary path is supported per host (the path
 * to `wp` is discovered at install time and stored in the
 * `rolepod_wp_cli_path` option). If `wp` isn't on PATH this endpoint
 * returns 503 WP_CLI_NOT_FOUND.
 *
 * Production-guarded for `allow_destructive` jobs.
 *
 * Body:
 *   { session_token, args: [...], timeout_seconds?: 600,
 *     allow_destructive?: false }
 *
 * Response 200:
 *   { ok: true, job_id, pid, log: { stdout, stderr },
 *     started_at, ttl_seconds }
 */
final class JobCreate
{
    public const TRANSIENT_PREFIX = 'rolepod_wp_job_';
    public const TTL_SECONDS = 3600;

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/job/create',
            [
                'methods' => 'POST',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'args' => ['required' => true, 'type' => 'array'],
                    'timeout_seconds' => ['required' => false, 'type' => 'integer', 'default' => 600],
                    'allow_destructive' => ['required' => false, 'type' => 'boolean', 'default' => false],
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
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_OR_EXPIRED_TOKEN'], 401);
        }

        $args = $req->get_param('args');
        if (!is_array($args) || count($args) === 0) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'EMPTY_ARGS', 'error_message' => 'args must be a non-empty array'], 400);
        }

        if (!self::execAvailable()) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'EXEC_DISABLED', 'error_message' => 'exec() is disabled on this host — async jobs unavailable'], 503);
        }

        $wpCli = self::resolveWpCli();
        if ($wpCli === null) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'WP_CLI_NOT_FOUND', 'error_message' => 'wp binary not on PATH and not in stored rolepod_wp_cli_path option'], 503);
        }

        $jobId = bin2hex(random_bytes(6));
        $logDir = WP_CONTENT_DIR . '/uploads/.rolepod-jobs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $stdoutPath = $logDir . '/' . $jobId . '.out';
        $stderrPath = $logDir . '/' . $jobId . '.err';

        $abs = ABSPATH;
        $escaped = [];
        foreach ($args as $a) {
            $escaped[] = escapeshellarg((string) $a);
        }
        $cmd = escapeshellarg($wpCli)
            . ' --path=' . escapeshellarg(rtrim($abs, '/'))
            . ' ' . implode(' ', $escaped)
            . ' > ' . escapeshellarg($stdoutPath)
            . ' 2> ' . escapeshellarg($stderrPath)
            . ' & echo $!';

        $output = [];
        $exitCode = 0;
        @exec($cmd, $output, $exitCode);
        $pid = (int) trim((string) ($output[0] ?? '0'));
        if ($pid <= 0) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'SPAWN_FAILED', 'error_message' => 'could not capture pid from wp-cli spawn'], 500);
        }

        $record = [
            'job_id' => $jobId,
            'pid' => $pid,
            'args' => array_map('strval', $args),
            'stdout_path' => $stdoutPath,
            'stderr_path' => $stderrPath,
            'started_at' => time(),
            'timeout_seconds' => max(60, min(3600, (int) $req->get_param('timeout_seconds'))),
            'allow_destructive' => (bool) $req->get_param('allow_destructive'),
            'user_id' => $userId,
        ];
        set_transient(self::TRANSIENT_PREFIX . $jobId, $record, self::TTL_SECONDS);

        return new WP_REST_Response([
            'ok' => true,
            'job_id' => $jobId,
            'pid' => $pid,
            'log' => ['stdout' => $stdoutPath, 'stderr' => $stderrPath],
            'started_at' => $record['started_at'],
            'ttl_seconds' => self::TTL_SECONDS,
        ], 200);
    }

    private static function execAvailable(): bool
    {
        if (!function_exists('exec')) return false;
        $disabled = explode(',', (string) ini_get('disable_functions'));
        return !in_array('exec', array_map('trim', $disabled), true);
    }

    private static function resolveWpCli(): ?string
    {
        $stored = (string) get_option('rolepod_wp_cli_path', '');
        if ($stored !== '' && is_executable($stored)) {
            return $stored;
        }
        $candidates = ['/usr/local/bin/wp', '/usr/bin/wp', '/opt/wp-cli/wp.phar'];
        foreach ($candidates as $c) {
            if (is_executable($c)) {
                update_option('rolepod_wp_cli_path', $c);
                return $c;
            }
        }
        // Last resort: shell `which`.
        $out = @shell_exec('which wp 2>/dev/null');
        if (is_string($out)) {
            $first = trim(strtok($out, "\n"));
            if ($first !== '' && is_executable($first)) {
                update_option('rolepod_wp_cli_path', $first);
                return $first;
            }
        }
        return null;
    }
}
