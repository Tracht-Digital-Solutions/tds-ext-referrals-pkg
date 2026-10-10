<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Support;

/** IBAN normalisation and the ISO 13616 mod-97 check. */
final class Iban
{
    public static function normalize(string $raw): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $raw));
    }

    public static function valid(string $raw): bool
    {
        $iban = self::normalize($raw);
        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            return false;
        }
        $moved = substr($iban, 4) . substr($iban, 0, 4);
        $digits = '';
        foreach (str_split($moved) as $ch) {
            $digits .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }
        $rest = 0;
        foreach (str_split($digits, 7) as $chunk) {
            $rest = (int) ((string) $rest . $chunk) % 97;
        }
        return $rest === 1;
    }

    /** `DE89 …3000` style: country + last four, never the full number. */
    public static function mask(?string $iban): ?string
    {
        if ($iban === null || $iban === '') {
            return null;
        }
        $n = self::normalize($iban);
        return substr($n, 0, 2) . ' … ' . substr($n, -4);
    }
}
