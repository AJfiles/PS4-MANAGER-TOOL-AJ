<?php
/** Pure helpers for safely editing GoldHEN payloads.ini without losing other entries. */

function ac_ini_get_section_value(string $content, string $section, string $key): ?string
{
    $current = '';
    foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
        $trimmed = trim($line);
        if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $match)) {
            $current = strtolower(trim($match[1]));
            continue;
        }
        if ($current !== strtolower($section) || $trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#') {
            continue;
        }
        $parts = explode('=', $trimmed, 2);
        if (count($parts) === 2 && strtolower(trim($parts[0])) === strtolower($key)) {
            $value = trim($parts[1]);
            $value = preg_replace('/\s*[;#].*$/', '', $value) ?? $value;
            return trim($value);
        }
    }
    return null;
}

function ac_ini_payloads_autorun_enabled(string $content): bool
{
    $current = '';
    foreach (preg_split('/\r\n|\n|\r/', $content) ?: [] as $line) {
        $trimmed = trim($line);
        if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $match)) {
            $current = strtolower(trim($match[1]));
            continue;
        }
        if ($current === 'autorun'
            && preg_match('~^/(?:user/)?data/payloads/app_control\.elf\s*=\s*(?:1|true|on)\s*(?:[;#].*)?$~i', $trimmed)) {
            return true;
        }
    }
    return false;
}

/**
 * Adds/removes only this Manager's exact AutoRun entry. Other sections, comments,
 * and unrelated GoldHEN payloads are preserved. Repeated enables are idempotent.
 */
function ac_ini_update_payloads_autorun(string $content, bool $enable): string
{
    $eol = strpos($content, "\r\n") !== false ? "\r\n" : "\n";
    $hadFinalNewline = preg_match('/(?:\r\n|\r|\n)$/', $content) === 1;
    $lines = preg_split('/\r\n|\n|\r/', $content) ?: [];
    if ($hadFinalNewline && $lines && end($lines) === '') {
        array_pop($lines);
    }

    $current = '';
    $foundSection = false;
    $inserted = false;
    $output = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (preg_match('/^\[([^\]]+)\]$/', $trimmed, $match)) {
            $current = strtolower(trim($match[1]));
            $output[] = $line;
            if ($current === 'autorun') {
                $foundSection = true;
                if ($enable && !$inserted) {
                    $output[] = '/user/data/payloads/app_control.elf = 1';
                    $inserted = true;
                }
            }
            continue;
        }

        if ($current === 'autorun'
            && preg_match('~^\s*/(?:user/)?data/payloads/app_control\.elf\s*=.*$~i', $line)) {
            continue;
        }
        $output[] = $line;
    }

    if ($enable && !$foundSection) {
        if ($output && trim((string)end($output)) !== '') {
            $output[] = '';
        }
        $output[] = '[AutoRun]';
        $output[] = '/user/data/payloads/app_control.elf = 1';
    }

    if ($enable && ac_ini_payloads_autorun_enabled($content)
        && substr_count(strtolower($content), 'app_control.elf') === 1) {
        return $content;
    }
    if (!$enable && !ac_ini_payloads_autorun_enabled($content)) {
        return $content;
    }

    $result = implode($eol, $output);
    if ($hadFinalNewline || $enable) {
        $result .= $eol;
    }
    return $result;
}
