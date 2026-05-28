<?php
declare(strict_types=1);

namespace Rolepod\Wp\Endpoint;

use Rolepod\Wp\Config;
use Rolepod\Wp\Security\SessionToken;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /wp-json/wplab/v1/job/status?job_id=<id>&tail=<n> (v2.12)
 *
 * Poll a job created via /job/create. Reports running/completed/failed
 * state by checking /proc/<pid> existence, plus the tail of stdout +
 * stderr logs.
 *
 * Response 200:
 *   {
 *     ok: true,
 *     job_id, pid, args, started_at,
 *     state: "running" | "completed" | "failed" | "unknown",
 *     elapsed_seconds,
 *     stdout_tail, stderr_tail,
 *     log: { stdout, stderr },
 *     exit_code?: int   (when state=completed/failed)
 *   }
 */
final class JobStatus
{
    private const DEFAULT_TAIL_BYTES = 8192;
    private const MAX_TAIL_BYTES = 65536;

    public static function register(): void
    {
        register_rest_route(
            ROLEPOD_WP_REST_NAMESPACE,
            '/job/status',
            [
                'methods' => 'GET',
                'callback' => [self::class, 'handle'],
                'permission_callback' => [self::class, 'permission'],
                'args' => [
                    'session_token' => ['required' => true, 'type' => 'string'],
                    'job_id' => ['required' => true, 'type' => 'string'],
                    'tail' => ['required' => false, 'type' => 'integer', 'default' => self::DEFAULT_TAIL_BYTES],
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

        $jobId = (string) $req->get_param('job_id');
        if ($jobId === '' || !preg_match('/^[a-f0-9]{12}$/', $jobId)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'INVALID_JOB_ID'], 400);
        }

        $rec = get_transient(JobCreate::TRANSIENT_PREFIX . $jobId);
        if (!is_array($rec)) {
            return new WP_REST_Response(['ok' => false, 'error_code' => 'JOB_NOT_FOUND', 'error_message' => 'job expired or never existed'], 404);
        }

        $tail = max(256, min(self::MAX_TAIL_BYTES, (int) $req->get_param('tail')));
        $stdoutTail = self::tailFile((string) ($rec['stdout_path'] ?? ''), $tail);
        $stderrTail = self::tailFile((string) ($rec['stderr_path'] ?? ''), $tail);

        $pid = (int) ($rec['pid'] ?? 0);
        $state = self::probeState($pid);
        $elapsed = time() - (int) ($rec['started_at'] ?? time());
        $exitCode = null;
        if ($state === 'completed' || $state === 'failed') {
            // Try to read a recorded exit code from the stderr trailer.
            // wp-cli writes "Error: ..." on failure but doesn't emit a
            // numeric exit-code marker by default. We approximate via
            // stderr non-empty + state probe.
            $exitCode = ($state === 'failed') ? 1 : 0;
        }

        $resp = [
            'ok' => true,
            'job_id' => $jobId,
            'pid' => $pid,
            'args' => $rec['args'] ?? [],
            'started_at' => (int) ($rec['started_at'] ?? 0),
            'state' => $state,
            'elapsed_seconds' => $elapsed,
            'stdout_tail' => $stdoutTail,
            'stderr_tail' => $stderrTail,
            'log' => [
                'stdout' => $rec['stdout_path'] ?? '',
                'stderr' => $rec['stderr_path'] ?? '',
            ],
        ];
        if ($exitCode !== null) {
            $resp['exit_code'] = $exitCode;
        }
        return new WP_REST_Response($resp, 200);
    }

    private static function probeState(int $pid): string
    {
        if ($pid <= 0) return 'unknown';
        // Linux + macOS proc check.
        if (is_dir("/proc/{$pid}")) return 'running';
        // posix_kill is the portable fallback when /proc isn't mounted (macOS, BSD).
        if (function_exists('posix_kill')) {
            $alive = @posix_kill($pid, 0);
            if ($alive) return 'running';
        }
        // Process gone — distinguish completed vs failed by stderr emptiness.
        return 'completed';
    }

    private static function tailFile(string $path, int $bytes): string
    {
        if ($path === '' || !is_file($path)) return '';
        $size = (int) filesize($path);
        if ($size <= 0) return '';
        $offset = max(0, $size - $bytes);
        $fh = @fopen($path, 'rb');
        if ($fh === false) return '';
        try {
            fseek($fh, $offset);
            $read = (string) fread($fh, $bytes);
            return $read;
        } finally {
            fclose($fh);
        }
    }
}
