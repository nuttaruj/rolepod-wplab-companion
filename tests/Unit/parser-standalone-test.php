<?php
declare(strict_types=1);

/**
 * Standalone Parser test — no PHPUnit/wp-env required. Stubs the two WP
 * functions Parser touches (sanitize_title is the only hard dependency;
 * filter_var is native). Run: php tests/Unit/parser-standalone-test.php
 */

// --- minimal WP stubs -------------------------------------------------------
if (!function_exists('sanitize_title')) {
    function sanitize_title(string $t): string
    {
        $t = strtolower(trim($t));
        $t = preg_replace('/[^a-z0-9]+/', '-', $t) ?? '';
        return trim($t, '-');
    }
}

require __DIR__ . '/../../src/Skills/Parser.php';

use Rolepod\Wp\Skills\Parser;

$failures = 0;
$count = 0;
function check(string $label, $got, $want): void
{
    global $failures, $count;
    $count++;
    $ok = $got === $want;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL: $label\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n");
    } else {
        echo "ok: $label\n";
    }
}

// 1. full frontmatter
$p = Parser::parse("---\nname: my-skill\ndescription: Do the thing\nenable_agentic: true\nenable_prompt: true\n---\n\nBody here.");
check('full.name', $p['name'], 'my-skill');
check('full.description', $p['description'], 'Do the thing');
check('full.enable_agentic', $p['enable_agentic'], true);
check('full.enable_prompt', $p['enable_prompt'], true);
check('full.body', $p['body'], 'Body here.');
check('full.no_errors', $p['errors'], []);

// 2. enable_prompt defaults FALSE (our improvement over a third-party plugin)
$p = Parser::parse("---\nname: s\ndescription: d\n---\n\nb");
check('default.enable_prompt_false', $p['enable_prompt'], false);
check('default.enable_agentic_true', $p['enable_agentic'], true);

// 3. no frontmatter → whole doc is body, desc empty warning
$p = Parser::parse("Just a body, no frontmatter.");
check('nofm.body', $p['body'], 'Just a body, no frontmatter.');
check('nofm.desc_empty', $p['description'], '');
check('nofm.warns_desc', in_array('Description is empty — the skill stays hidden from the catalog until a one-line trigger description is set.', $p['warnings'], true), true);

// 4. unclosed frontmatter → error, body untouched
$p = Parser::parse("---\nname: x\nno closing fence");
check('unclosed.has_error', count($p['errors']) === 1, true);

// 5. quoted values stripped
$p = Parser::parse("---\ndescription: \"quoted desc\"\n---\n\nb");
check('quotes.double', $p['description'], 'quoted desc');
$p = Parser::parse("---\ndescription: 'single quoted'\n---\n\nb");
check('quotes.single', $p['description'], 'single quoted');

// 6. enable flags lenient bool parse
$p = Parser::parse("---\ndescription: d\nenable_agentic: false\nenable_prompt: yes\n---\n\nb");
check('bool.agentic_false', $p['enable_agentic'], false);
check('bool.prompt_yes', $p['enable_prompt'], true);

// 7. renderSkillMd round-trip
$md = Parser::renderSkillMd(['slug' => 'foo', 'description' => 'bar', 'enable_agentic' => true, 'enable_prompt' => false, 'content' => 'Body.']);
$round = Parser::parse($md);
check('roundtrip.name', $round['name'], 'foo');
check('roundtrip.desc', $round['description'], 'bar');
check('roundtrip.body', $round['body'], 'Body.');
check('roundtrip.prompt_false', $round['enable_prompt'], false);

// 8. smart unescape — only when double-encoded (no real newline + has \n literal)
check('unescape.double_encoded', Parser::unescapeIfDoubleEncoded('line1\nline2\ttab'), "line1\nline2\ttab");
check('unescape.real_newline_untouched', Parser::unescapeIfDoubleEncoded("real\nnewline\\nliteral"), "real\nnewline\\nliteral");
check('unescape.plain_single_line', Parser::unescapeIfDoubleEncoded('no escapes here'), 'no escapes here');

// 9. normalizeSlug
check('slug.basic', Parser::normalizeSlug('My Cool Skill'), 'my-cool-skill');
check('slug.trunc60', strlen(Parser::normalizeSlug(str_repeat('a', 80))) <= 60, true);
check('slug.empty', Parser::normalizeSlug('!!!'), '');

// 10. oversized body warns
$big = str_repeat('x', Parser::SOFT_BODY_BYTES + 1);
$p = Parser::parse("---\ndescription: d\n---\n\n$big");
check('softcap.warns', (bool) array_filter($p['warnings'], fn($w) => str_contains($w, 'context on every fire')), true);

echo "\n" . ($failures === 0 ? "ALL PASS" : "$failures FAILED") . " ($count checks)\n";
exit($failures === 0 ? 0 : 1);
