<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Support;

/**
 * Partner codes: what a `?ref=` link carries and what a buyer types at the
 * checkout. Case-insensitive on input, stored upper-case, `[A-Z0-9-]`, 3–40
 * characters — readable aloud, safe in a URL without encoding.
 */
final class PartnerCode
{
    /** The canonical spelling, or null when the input cannot be a code. */
    public static function normalize(?string $raw): ?string
    {
        $code = strtoupper(trim((string) $raw));
        return preg_match('/^[A-Z0-9][A-Z0-9-]{1,38}[A-Z0-9]$/', $code) === 1 ? $code : null;
    }

    /** A fresh code from the partner's name: `ANNA-4821`. The caller checks uniqueness. */
    public static function suggest(string $name): string
    {
        $ascii = strtr($name, ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE', 'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $first = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', explode(' ', trim($ascii))[0] ?? ''));
        $stem = substr($first !== '' ? $first : 'PARTNER', 0, 12);
        return sprintf('%s-%04d', $stem, random_int(1000, 9999));
    }
}
