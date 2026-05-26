<?php
declare(strict_types=1);

namespace Rolepod\Wp\Security;

/**
 * v0.1: token-based PHP-payload blocklist screen.
 *
 * Rejects payloads containing forbidden function calls or language constructs.
 * This is the SECOND screen — the Node MCP runs `php-parser` AST screen first.
 * Token-based screen is sufficient as a defence-in-depth layer for v0.1; v0.2
 * upgrades to a proper PHP AST via nikic/php-parser (added as a composer dep
 * once execute-php is default-enabled).
 *
 * Approach: tokenize via token_get_all() and inspect each T_STRING for a
 * forbidden function name, plus check for T_INCLUDE / T_REQUIRE / T_EVAL.
 * Backtick operator and shell_exec syntax are rejected via raw-source scan
 * because token_get_all maps them to a special token id.
 */
final class AstScreen
{
    private const FORBIDDEN_FUNCS = [
        'eval', 'assert', 'create_function',
        'system', 'passthru', 'shell_exec', 'exec', 'proc_open', 'popen',
        'pcntl_exec', 'pcntl_fork',
        'dl', // load PHP extension
        // file ops handled by separate scope rule (v0.2)
    ];

    /**
     * @param string $payload PHP source (without opening <?php tag)
     * @return array{ok: bool, error?: string, token?: string}
     */
    public static function screen(string $payload): array
    {
        // 1. Quick scan for backtick operator (shell exec syntax).
        // token_get_all() emits these as ` characters in the token stream.
        if (preg_match('/`[^`]*`/', $payload) === 1) {
            return ['ok' => false, 'error' => 'Backtick shell-exec syntax is forbidden', 'token' => '`'];
        }

        // 2. Tokenize. Prepend <?php so token_get_all parses correctly.
        $tokens = @token_get_all('<?php ' . $payload);
        if (!is_array($tokens) || count($tokens) === 0) {
            return ['ok' => false, 'error' => 'Payload could not be tokenized'];
        }

        $forbiddenSet = array_flip(self::FORBIDDEN_FUNCS);

        foreach ($tokens as $i => $tok) {
            if (!is_array($tok)) {
                continue;
            }
            $id = $tok[0];
            $text = (string) $tok[1];

            // Language constructs
            if ($id === T_EVAL) {
                return ['ok' => false, 'error' => 'eval() is forbidden', 'token' => 'eval'];
            }
            if ($id === T_INCLUDE || $id === T_INCLUDE_ONCE || $id === T_REQUIRE || $id === T_REQUIRE_ONCE) {
                // Allow only if the very next non-whitespace token is a string literal — i.e.
                // include 'path' with a static path. Anything dynamic = reject.
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next === null || (!is_array($next) || $next[0] !== T_CONSTANT_ENCAPSED_STRING)) {
                    return ['ok' => false, 'error' => 'Dynamic include/require is forbidden', 'token' => $text];
                }
            }

            // Forbidden function names — must look like a function call: T_STRING followed by `(`.
            if ($id === T_STRING && isset($forbiddenSet[strtolower($text)])) {
                $next = self::nextNonWhitespace($tokens, $i);
                if ($next === '(') {
                    return [
                        'ok' => false,
                        'error' => "Forbidden function call: {$text}()",
                        'token' => strtolower($text),
                    ];
                }
            }
        }

        return ['ok' => true];
    }

    /**
     * @param array<int, array{0:int,1:string,2:int}|string> $tokens
     * @return array{0:int,1:string,2:int}|string|null
     */
    private static function nextNonWhitespace(array $tokens, int $i)
    {
        for ($j = $i + 1, $n = count($tokens); $j < $n; $j++) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_WHITESPACE) {
                continue;
            }
            return $t;
        }
        return null;
    }
}
