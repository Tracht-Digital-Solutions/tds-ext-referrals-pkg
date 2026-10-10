<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Referrals\Service\ReferralSettings;
use Tds\Ext\Referrals\Support\Iban;
use Tds\Ext\Referrals\Support\PartnerCode;

final class SupportTest extends TestCase
{
    public function testCodesAreNormalisedAndValidated(): void
    {
        self::assertSame('ANNA-4821', PartnerCode::normalize(' anna-4821 '));
        self::assertNull(PartnerCode::normalize('ab'));
        self::assertNull(PartnerCode::normalize('-ANNA'));
        self::assertNull(PartnerCode::normalize('ANNA 1'));
        self::assertNull(PartnerCode::normalize(null));
        self::assertMatchesRegularExpression('/^JOERG-\d{4}$/', PartnerCode::suggest('Jörg Müller'));
        self::assertMatchesRegularExpression('/^PARTNER-\d{4}$/', PartnerCode::suggest('  '));
    }

    public function testIbanCheckDigits(): void
    {
        self::assertTrue(Iban::valid('DE89 3704 0044 0532 0130 00'));
        self::assertFalse(Iban::valid('DE88 3704 0044 0532 0130 00'));
        self::assertFalse(Iban::valid('kein iban'));
        self::assertSame('DE … 3000', Iban::mask('de89370400440532013000'));
        self::assertNull(Iban::mask(null));
    }

    public function testPercentParsing(): void
    {
        self::assertSame(1250, ReferralSettings::percentToBp('12,5'));
        self::assertSame(1000, ReferralSettings::percentToBp(10));
        self::assertNull(ReferralSettings::percentToBp('101'));
        self::assertNull(ReferralSettings::percentToBp('zehn'));
        self::assertNull(ReferralSettings::percentToBp(''));
    }

    public function testSettingsFallBackToDefaults(): void
    {
        $s = ReferralSettings::from(null);
        self::assertSame(1000, $s->defaultRateBp);
        self::assertSame(21, $s->holdDays);
        self::assertSame(30, $s->linkDays);
    }
}
