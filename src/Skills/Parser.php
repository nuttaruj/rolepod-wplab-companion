<?php
declare(strict_types=1);

namespace Rolepod\Wp\Skills;

/**
 * SKILL.md parsing + rendering.
 *
 * A skill is a single Markdown document: an optional `---` frontmatter block
 * (name / description / enable_agentic / enable_prompt) followed by the body.
 * Parsing is lenient — a malformed frontmatter block is reported in `errors`
 * but never throws, and unknown keys are ignored. Soft problems (empty
 * description, oversized body, no obvious trigger phrase) surface in
 * `warnings` so the write path can echo actionable feedback to the agent
 * without rejecting the save.
 *
 * Format is intentionally Claude-Code-skill compatible: the same frontmatter
 * keys round-trip, so a SKILL.md authored here can be lifted into a
 * client-side skill and vice versa.
 */
final class Parser
{
    /** Hard cap. Writes above this are rejected. */
    public const MAX_BODY_BYTES = 1_048_576; // 1 MiB

    /** Soft cap. Above this we warn — the body loads into context on every fire. */
    public const SOFT_BODY_BYTES = 51_200; // ~50 KiB ≈ 8k words

    /**
     * Parse a raw SKILL.md string.
     *
     * @return array{
     *   name: string, description: string,
     *   enable_agentic: bool, enable_prompt: bool,
     *   body: string, errors: list<string>, warnings: list<string>
     * }
     */
    public static function parse(string $raw): array
    {
        $name = '';
        $description = '';
        $enableAgentic = true;
        $enablePrompt = false; // opt-in (differs from a third-party plugin's true default)
        $body = $raw;
        $errors = [];
        $warnings = [];

        $normalized = preg_replace('/\r\n?/', "\n", $raw);
        if (!is_string($normalized)) {
            $normalized = $raw;
        }

        if (str_starts_with($normalized, "---\n")) {
            $close = strpos($normalized, "\n---\n", 4);
            if ($close === false && str_ends_with($normalized, "\n---")) {
                $close = strlen($normalized) - 4; // trailing fence, no newline after
            }

            if ($close === false) {
                $errors[] = 'Frontmatter opened with "---" but never closed; treating the whole document as body.';
            } else {
                $fm = substr($normalized, 4, $close - 4);
                $body = ltrim(substr($normalized, $close + 5), "\n");

                foreach (explode("\n", $fm) as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                        continue;
                    }
                    $colon = strpos($line, ':');
                    if ($colon === false) {
                        continue;
                    }
                    $key = strtolower(trim(substr($line, 0, $colon)));
                    $value = self::stripQuotes(trim(substr($line, $colon + 1)));

                    switch ($key) {
                        case 'name':
                            $name = $value;
                            break;
                        case 'description':
                            $description = $value;
                            break;
                        case 'enable_agentic':
                            $enableAgentic = self::toBool($value, true);
                            break;
                        case 'enable_prompt':
                            $enablePrompt = self::toBool($value, false);
                            break;
                        // unknown keys ignored (lenient)
                    }
                }
            }
        }

        $body = self::unescapeIfDoubleEncoded($body);

        // Soft warnings — non-fatal, surfaced to the agent on write.
        if (trim($description) === '') {
            $warnings[] = 'Description is empty — the skill stays hidden from the catalog until a one-line trigger description is set.';
        }
        if (trim($body) === '') {
            $warnings[] = 'Body is empty — the skill has no instructions to load.';
        }
        $bytes = strlen($body);
        if ($bytes > self::SOFT_BODY_BYTES) {
            $warnings[] = sprintf(
                'Body is %d KB — large skills cost context on every fire. Trim to the procedural knowledge the agent cannot infer.',
                (int) round($bytes / 1024)
            );
        }

        return [
            'name' => $name,
            'description' => $description,
            'enable_agentic' => $enableAgentic,
            'enable_prompt' => $enablePrompt,
            'body' => $body,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Reconstruct a canonical SKILL.md string from a stored record.
     *
     * @param array{slug?: string, description?: string, enable_agentic?: bool, enable_prompt?: bool, content?: string} $skill
     */
    public static function renderSkillMd(array $skill): string
    {
        return sprintf(
            "---\nname: %s\ndescription: %s\nenable_agentic: %s\nenable_prompt: %s\n---\n\n%s",
            $skill['slug'] ?? '',
            // description is a single logical line in frontmatter — collapse newlines.
            str_replace("\n", ' ', $skill['description'] ?? ''),
            ($skill['enable_agentic'] ?? true) ? 'true' : 'false',
            ($skill['enable_prompt'] ?? false) ? 'true' : 'false',
            $skill['content'] ?? ''
        );
    }

    /**
     * Normalize a title/slug candidate to the canonical internal slug
     * (lowercase, dash-separated, ≤ 60 chars). Returns '' when nothing usable
     * survives — the caller decides how to react.
     */
    public static function normalizeSlug(string $raw): string
    {
        $candidate = sanitize_title($raw);
        if ($candidate === '') {
            return '';
        }
        if (strlen($candidate) > 60) {
            $candidate = rtrim(substr($candidate, 0, 60), '-');
        }
        return $candidate;
    }

    /**
     * Unescape C-style sequences ONLY when the body is unambiguously
     * double-JSON-encoded: it carries literal `\n` two-char sequences AND has
     * no real newline at all. Some MCP clients double-encode tool arguments so
     * a multi-line body arrives as one line with literal `\n`. Blindly running
     * stripcslashes (as some implementations do) corrupts bodies that
     * legitimately contain `\n` inside a code sample — so we gate on the
     * "no real newline present" signal, which only a flattened payload shows.
     */
    public static function unescapeIfDoubleEncoded(string $body): string
    {
        if ($body === '' || str_contains($body, "\n")) {
            return $body; // already has real newlines — leave escapes untouched
        }
        if (!str_contains($body, '\\n') && !str_contains($body, '\\t')) {
            return $body; // genuinely single-line, no escapes to expand
        }
        return stripcslashes($body);
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }
        return $value;
    }

    private static function toBool(string $value, bool $default): bool
    {
        $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $parsed ?? $default;
    }
}
