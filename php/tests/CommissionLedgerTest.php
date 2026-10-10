<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Tests;

use PHPUnit\Framework\TestCase;
use Tds\Ext\Referrals\Service\CommissionLedger;
use Tds\Ext\Referrals\Service\LedgerException;
use Tds\Ext\Referrals\Service\ReferralSettings;
use Tds\Ext\Referrals\Tests\Support\MemoryReferralStore;
use Tds\Frontend\Contract\Commerce\SaleEvent;
use Tds\Frontend\Contract\Commerce\SaleLine;

final class CommissionLedgerTest extends TestCase
{
    private MemoryReferralStore $store;
    private string $now = '2026-10-11 12:00:00';

    protected function setUp(): void
    {
        $this->store = new MemoryReferralStore();
        $this->store->insertPartner(['name' => 'Anna Beispiel', 'public_name' => 'Anna B.', 'code' => 'ANNA-1', 'email' => 'anna@example.org']);
    }

    private function ledger(int $holdDays = 21, int $defaultBp = 1000): CommissionLedger
    {
        return new CommissionLedger(
            $this->store,
            new ReferralSettings($defaultBp, $holdDays, 30, 'https://shop.example/', ''),
            fn (): string => $this->now,
        );
    }

    private function sale(string $id, int $net, ?string $code = 'anna-1', ?string $note = null, ?string $email = 'buyer@example.org', array $lines = []): SaleEvent
    {
        return new SaleEvent('shop', $id, $net, $email, $lines, $code, 'link', $note);
    }

    public function testCodedSaleIsPendingWithTheDefaultRateUntilTheHoldPeriodEnds(): void
    {
        $this->ledger()->onSalePaid($this->sale('7', 50000));

        $c = $this->store->commissionBySource('shop', '7');
        self::assertNotNull($c);
        self::assertSame('pending', $c['status']);
        self::assertSame('link', $c['via']);
        self::assertSame(1, $c['partner_id']);
        self::assertSame(5000, $c['commission_cents']);
        self::assertSame('2026-11-01 12:00:00', $c['approve_after']);

        self::assertSame(0, $this->ledger()->mature());
        $this->now = '2026-11-01 12:00:00';
        self::assertSame(1, $this->ledger()->mature());
        self::assertSame('approved', $this->store->commission($c['id'])['status']);
    }

    public function testRedeliveryIsANoOp(): void
    {
        $ledger = $this->ledger();
        $ledger->onSalePaid($this->sale('7', 50000));
        $this->now = '2026-10-12 08:00:00';
        $ledger->onSalePaid($this->sale('7', 50000));

        self::assertCount(1, $this->store->commissions);
        self::assertSame('2026-11-01 12:00:00', $this->store->commission(1)['approve_after'], 'the hold period does not restart');
    }

    public function testNoCodeAndNoNoteRecordsNothing(): void
    {
        $this->ledger()->onSalePaid($this->sale('8', 1000, null));
        $this->ledger()->onSalePaid($this->sale('9', 1000, 'UNKNOWN'));
        self::assertSame([], $this->store->commissions);
    }

    public function testAnUnknownCodeWithANoteBecomesAClaim(): void
    {
        $this->ledger()->onSalePaid($this->sale('9', 20000, 'UNKNOWN', 'Mein Nachbar Bernd'));
        $c = $this->store->commission(1);
        self::assertSame('claimed', $c['status']);
        self::assertSame('named', $c['via']);
        self::assertNull($c['partner_id']);
        self::assertNull($c['commission_cents']);
        $this->now = '2026-12-01 00:00:00';
        self::assertSame(0, $this->ledger()->mature(), 'a claim never matures on its own');
    }

    public function testAssigningAClaimFreezesTheRateAtThatMoment(): void
    {
        $this->ledger()->onSalePaid($this->sale('9', 20000, null, 'Anna'));
        $this->store->updatePartner(1, ['rate_bp' => 1500]);
        $this->ledger()->assign(1, 1);

        $c = $this->store->commission(1);
        self::assertSame('pending', $c['status']);
        self::assertSame(3000, $c['commission_cents']);

        $this->store->updatePartner(1, ['rate_bp' => 500]);
        self::assertSame(3000, $this->store->commission(1)['commission_cents'], 'later rate changes do not rewrite it');
    }

    public function testProductRateBeatsPartnerRateBeatsDefault(): void
    {
        $this->store->setProductRate('42', 2000);
        $this->store->updatePartner(1, ['rate_bp' => 500]);
        $this->ledger()->onSalePaid($this->sale('10', 30000, 'ANNA-1', null, 'x@example.org', [
            new SaleLine('42', 10000, 'Website'),
            new SaleLine('43', 20000, 'Wartung'),
        ]));
        // 10 000 × 20 % + 20 000 × 5 % = 2 000 + 1 000
        $c = $this->store->commission(1);
        self::assertSame(3000, $c['commission_cents']);
        self::assertSame(1000, $c['rate_bp'], 'the stored rate is the effective one');
    }

    public function testThePartnersOwnPurchaseEarnsNothing(): void
    {
        $this->ledger()->onSalePaid($this->sale('11', 10000, 'ANNA-1', null, 'Anna@Example.org'));
        $c = $this->store->commission(1);
        self::assertSame('rejected', $c['status']);
        self::assertStringContainsString('Eigenkauf', (string) $c['note']);
    }

    public function testAPausedPartnersCodeIsIgnored(): void
    {
        $this->store->updatePartner(1, ['status' => 'paused']);
        $this->ledger()->onSalePaid($this->sale('12', 10000));
        self::assertSame([], $this->store->commissions);
    }

    public function testRefundBeforePayoutReversesWithoutClawback(): void
    {
        $this->ledger()->onSalePaid($this->sale('7', 50000));
        $this->ledger()->onSaleReversed('shop', '7');
        self::assertSame('reversed', $this->store->commission(1)['status']);
        self::assertCount(1, $this->store->commissions);
    }

    public function testRefundAfterPayoutIsClawedBackInTheNextPayout(): void
    {
        $ledger = $this->ledger(0);
        $ledger->onSalePaid($this->sale('7', 50000));
        $ledger->payout(1, 'Oktober');
        self::assertSame('paid', $this->store->commission(1)['status']);

        $ledger->onSaleReversed('shop', '7');
        $ledger->onSaleReversed('shop', '7');
        $claw = $this->store->commissionBySource('clawback', '1');
        self::assertNotNull($claw);
        self::assertSame(-5000, $claw['commission_cents']);
        self::assertSame('approved', $claw['status']);
        self::assertCount(2, $this->store->commissions, 'a repeated refund claws back once');

        // A new sale of 30 000 (3 000) minus the clawback (5 000) leaves nothing to pay.
        $ledger->onSalePaid($this->sale('8', 30000));
        try {
            $ledger->payout(1, null);
            self::fail('a negative balance must not be paid out');
        } catch (LedgerException $e) {
            self::assertSame(409, $e->status());
        }
        $ledger->onSalePaid($this->sale('9', 30000));
        $payout = $ledger->payout(1, null);
        self::assertSame(1000, $payout['total_cents']);
    }

    public function testManualJobTiedToAnInvoiceBecomesDueWhenTheInvoiceIsPaid(): void
    {
        $ledger = $this->ledger();
        $row = $ledger->recordManual(1, 120000, 'Website Bäckerei Huber', null, 55);
        self::assertSame('pending', $row['status']);
        self::assertFalse($row['source_paid']);
        self::assertSame(12000, $row['commission_cents']);

        $this->now = '2026-12-31 00:00:00';
        self::assertSame(0, $ledger->mature(), 'unpaid never matures');

        // The billing module reports the invoice paid — no code on that event.
        $this->now = '2026-10-20 09:00:00';
        $ledger->onSalePaid(new SaleEvent('billing', '55', 120000));
        $c = $this->store->commission($row['id']);
        self::assertTrue($c['source_paid']);
        self::assertSame('2026-11-10 09:00:00', $c['approve_after']);
        self::assertCount(1, $this->store->commissions);

        $this->expectException(LedgerException::class);
        $ledger->recordManual(1, 1000, 'nochmal', null, 55);
    }

    public function testManualJobWithoutInvoiceIsConfirmedByHandAndCanBeApprovedEarly(): void
    {
        $ledger = $this->ledger();
        $row = $ledger->recordManual(1, 10000, 'Beratung', null, null);
        try {
            $ledger->approve($row['id']);
            self::fail('unpaid must not be approved');
        } catch (LedgerException $e) {
            self::assertSame(409, $e->status());
        }
        $ledger->confirmPaid($row['id']);
        $ledger->approve($row['id']);
        self::assertSame('approved', $this->store->commission($row['id'])['status']);
    }

    public function testRejectedStaysOutOfThePayout(): void
    {
        $ledger = $this->ledger(0);
        $ledger->onSalePaid($this->sale('7', 50000));
        $ledger->reject(1, 'Doppelt gemeldet');
        $this->expectException(LedgerException::class);
        $ledger->payout(1, null);
    }
}
