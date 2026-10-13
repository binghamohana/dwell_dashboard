<?php
declare(strict_types=1);

namespace Dwell\Core;

final class Util
{
    /** Trim, strip control characters and cap the length of user-supplied text. */
    public static function cleanText(mixed $value, int $max, bool $multiline = false): string
    {
        $value = is_scalar($value) ? (string) $value : '';
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        $value = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value);
        $pattern = $multiline ? '/[\x00-\x09\x0B-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u';
        $value = preg_replace($pattern, $multiline ? '' : ' ', $value) ?? '';
        $value = $multiline ? (preg_replace("/\n{3,}/", "\n\n", $value) ?? $value) : (preg_replace('/ {2,}/', ' ', $value) ?? $value);
        return mb_substr(trim($value), 0, $max);
    }

    public static function nullable(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';
        return $value === '' ? null : $value;
    }

    public static function token(int $bytes = 24): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }

    public static function sign(string $data): string
    {
        return self::base64url(hash_hmac('sha256', $data, App::secret(), true));
    }

    public static function isValidEmail(string $email): bool
    {
        return strlen($email) <= 190
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && preg_match('/@[^.@]+(\.[^.@]+)+$/', $email) === 1;
    }

    public static function normalizeEmail(mixed $email): ?string
    {
        $email = strtolower(trim(is_scalar($email) ? (string) $email : ''));
        return $email === '' ? null : $email;
    }

    /** "MARIA DE LA CRUZ" → "Maria De La Cruz"; mixed-case names are left alone. */
    public static function titleCase(string $name): string
    {
        if ($name === '' || mb_strtoupper($name) !== $name || mb_strtolower($name) === $name) {
            return $name;
        }
        $out = mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
        // Mc / Mac / O' prefixes
        $out = preg_replace_callback("/\\b(Mc|O')(\\p{Ll})/u", static fn ($m) => $m[1] . mb_strtoupper($m[2]), $out) ?? $out;
        return preg_replace_callback('/\b(Ii|Iii|Iv)\b/u', static fn ($m) => strtoupper($m[1]), $out) ?? $out;
    }

    /** Lowercase, accent-free, punctuation-free form of a name, for duplicate detection. */
    public static function nameKey(string $name): string
    {
        $name = mb_strtolower($name);
        if (function_exists('transliterator_transliterate')) {
            $name = (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
        } else {
            $name = (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        }
        $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name) ?? '';
        $name = preg_replace('/\b(jr|sr|ii|iii|iv)\b/', ' ', $name) ?? '';
        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    public static function initials(string $first, string $last): string
    {
        $a = mb_substr(trim($first), 0, 1);
        $parts = preg_split('/\s+/u', trim($last)) ?: [];
        $b = $parts ? mb_substr($parts[0], 0, 1) : '';
        return mb_strtoupper($a . $b);
    }

    public static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function decode(?string $json, mixed $default = []): mixed
    {
        if ($json === null || $json === '') {
            return $default;
        }
        $value = json_decode($json, true);
        return $value === null ? $default : $value;
    }

    public static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
