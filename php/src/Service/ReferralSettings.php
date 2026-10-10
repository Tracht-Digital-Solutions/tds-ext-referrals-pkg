<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Service;

use Tds\Frontend\Contract\SettingsStore;

/**
 * The programme's terms as the operator set them (Einstellungen →
 * Empfehlungsprogramm). Read per request, defaults when unset.
 */
final class ReferralSettings
{
    public const NS = 'referrals';
    public const DEFAULT_RATE_PERCENT = '10';
    /** 14 days of Widerruf plus a week for the refund to arrive. */
    public const DEFAULT_HOLD_DAYS = '21';
    public const DEFAULT_LINK_DAYS = '30';
    public const DEFAULT_LINK_BASE = 'https://shop.tracht-digital.de/';

    public function __construct(
        public readonly int $defaultRateBp,
        public readonly int $holdDays,
        public readonly int $linkDays,
        public readonly string $linkBase,
        public readonly string $termsUrl,
    ) {
    }

    public static function from(?SettingsStore $store): self
    {
        $get = static function (string $key, string $default) use ($store): string {
            try {
                $v = $store?->get(self::NS, $key);
            } catch (\Throwable) {
                $v = null;
            }
            return $v === null || trim($v) === '' ? $default : trim($v);
        };
        return new self(
            self::percentToBp($get('default_rate_percent', self::DEFAULT_RATE_PERCENT)) ?? 1000,
            max(0, min(365, (int) $get('hold_days', self::DEFAULT_HOLD_DAYS))),
            max(1, min(365, (int) $get('link_days', self::DEFAULT_LINK_DAYS))),
            $get('link_base', self::DEFAULT_LINK_BASE),
            $get('terms_url', ''),
        );
    }

    /** "12,5" or "12.5" → 1250; null outside 0–100 % or unparsable. */
    public static function percentToBp(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $s = str_replace(',', '.', trim((string) $raw));
        if (!is_numeric($s)) {
            return null;
        }
        $bp = (int) round((float) $s * 100);
        return $bp < 0 || $bp > 10000 ? null : $bp;
    }
}
