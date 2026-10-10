<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Service;

use Tds\Ext\Referrals\Domain\ReferralStore;
use Tds\Ext\Referrals\Support\PartnerCode;
use Tds\Frontend\Contract\Commerce\SaleEvent;
use Tds\Frontend\Contract\Commerce\SaleLine;

/**
 * The money rules. Every state change of a commission goes through here.
 *
 *   claimed  — a buyer named somebody; no partner assigned yet, no amount owed
 *   pending  — partner known; waits for payment and the hold period
 *   approved — due; part of the next payout
 *   paid     — settled by a payout
 *   rejected — never owed (own purchase, operator decision)
 *   reversed — the sale was refunded; a paid one is clawed back by a negative
 *              `clawback` row in the next payout
 *
 * The rate is FROZEN when the partner is attached: changing a partner's rate
 * later never rewrites what they were promised for past sales.
 */
final class CommissionLedger
{
    /** @var callable(): string */
    private $clock;

    /** @param (callable(): string)|null $clock Berlin wall-clock "Y-m-d H:i:s" */
    public function __construct(
        private readonly ReferralStore $store,
        private readonly ReferralSettings $settings,
        ?callable $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): string => date('Y-m-d H:i:s');
    }

    // --- sales from other modules ---------------------------------------------

    public function onSalePaid(SaleEvent $sale): void
    {
        $now = ($this->clock)();
        $existing = $this->store->commissionBySource($sale->source, $sale->sourceId);
        if ($existing !== null) {
            // A redelivery, or the payment for a commission the operator
            // recorded by hand against this invoice.
            $this->markPaid($existing, $now);
            return;
        }

        $code = PartnerCode::normalize($sale->referralCode);
        $partner = $code === null ? null : $this->store->partnerByCode($code);
        if ($partner !== null && $partner['status'] !== 'active') {
            $partner = null;
        }
        $note = self::clip($sale->referredByNote, 500);
        if ($partner === null && $note === null) {
            return;
        }

        $fields = [
            'partner_id' => null,
            'source' => $sale->source,
            'source_id' => $sale->sourceId,
            'via' => $partner !== null ? ($sale->referralVia === 'link' ? 'link' : 'code') : 'named',
            'status' => 'claimed',
            'net_cents' => $sale->netCents,
            'lines_json' => json_encode(array_map(
                static fn (SaleLine $l): array => ['productId' => $l->productId, 'netCents' => $l->netCents, 'title' => $l->title],
                $sale->lines,
            ), JSON_THROW_ON_ERROR),
            'description' => self::describe($sale),
            'customer_email' => self::clip($sale->customerEmail, 254),
            'note' => $note,
            'source_paid' => true,
            'paid_at' => $now,
            'approve_after' => $this->dueFrom($now),
            'created_at' => $now,
        ];
        if ($partner !== null) {
            $fields = array_merge($fields, $this->attach($partner, $fields));
        }
        $this->store->insertCommission($fields);
    }

    public function onSaleReversed(string $source, string $sourceId): void
    {
        $row = $this->store->commissionBySource($source, $sourceId);
        if ($row === null || in_array($row['status'], ['reversed', 'rejected'], true)) {
            return;
        }
        $now = ($this->clock)();
        $this->store->updateCommission($row['id'], ['status' => 'reversed', 'updated_at' => $now]);
        if ($row['status'] === 'paid' && (int) $row['commission_cents'] !== 0) {
            $this->store->insertCommission([
                'partner_id' => $row['partner_id'],
                'source' => 'clawback',
                'source_id' => (string) $row['id'],
                'via' => $row['via'],
                'status' => 'approved',
                'net_cents' => -$row['net_cents'],
                'rate_bp' => $row['rate_bp'],
                'commission_cents' => -(int) $row['commission_cents'],
                'description' => 'Rückbuchung: ' . ($row['description'] ?? 'erstatteter Auftrag'),
                'source_paid' => true,
                'paid_at' => $now,
                'approve_after' => $now,
                'created_at' => $now,
            ]);
        }
    }

    // --- operator actions ------------------------------------------------------

    /**
     * A job closed outside the shop. With `$invoiceId` it is tied to a billing
     * invoice and becomes due once that invoice is paid; without, the operator
     * confirms the payment by hand ({@see confirmPaid()}).
     *
     * @return array<string,mixed>
     */
    public function recordManual(int $partnerId, int $netCents, string $description, ?string $customerEmail, ?int $invoiceId, bool $alreadyPaid = false): array
    {
        $partner = $this->store->partner($partnerId) ?? throw new LedgerException('Partner nicht gefunden.', 404);
        if ($netCents <= 0) {
            throw new LedgerException('Der Nettobetrag muss größer als 0 sein.');
        }
        $description = self::clip($description, 300) ?? throw new LedgerException('Bitte den Auftrag kurz beschreiben.');
        $now = ($this->clock)();
        $source = $invoiceId !== null ? 'billing' : 'manual';
        $sourceId = $invoiceId !== null ? (string) $invoiceId : 'm-' . bin2hex(random_bytes(6));
        if ($this->store->commissionBySource($source, $sourceId) !== null) {
            throw new LedgerException('Für diese Rechnung ist schon eine Vermittlung erfasst.', 409);
        }
        $fields = [
            'source' => $source,
            'source_id' => $sourceId,
            'via' => 'manual',
            'net_cents' => $netCents,
            'description' => $description,
            'customer_email' => self::clip($customerEmail, 254),
            'source_paid' => $alreadyPaid,
            'paid_at' => $alreadyPaid ? $now : null,
            'approve_after' => $alreadyPaid ? $this->dueFrom($now) : null,
            'created_at' => $now,
        ];
        $fields = array_merge($fields, $this->attach($partner, $fields));
        $id = $this->store->insertCommission($fields) ?? throw new LedgerException('Bereits erfasst.', 409);
        return $this->store->commission($id) ?? [];
    }

    /** Give a claimed (or not yet settled) commission to a partner. */
    public function assign(int $commissionId, int $partnerId): void
    {
        $row = $this->mustFind($commissionId);
        if (!in_array($row['status'], ['claimed', 'pending', 'rejected'], true)) {
            throw new LedgerException('Nur offene Vermittlungen lassen sich zuordnen.', 409);
        }
        $partner = $this->store->partner($partnerId) ?? throw new LedgerException('Partner nicht gefunden.', 404);
        $this->store->updateCommission($commissionId, $this->attach($partner, $row) + ['updated_at' => ($this->clock)()]);
    }

    /** The payment for a manual job arrived. */
    public function confirmPaid(int $commissionId): void
    {
        $this->markPaid($this->mustFind($commissionId), ($this->clock)());
    }

    /** Due now, without waiting for the hold period. */
    public function approve(int $commissionId): void
    {
        $row = $this->mustFind($commissionId);
        if ($row['status'] !== 'pending') {
            throw new LedgerException('Nur wartende Vermittlungen lassen sich freigeben.', 409);
        }
        if (!$row['source_paid']) {
            throw new LedgerException('Der Auftrag ist noch nicht bezahlt.', 409);
        }
        $this->store->updateCommission($commissionId, ['status' => 'approved', 'updated_at' => ($this->clock)()]);
    }

    public function reject(int $commissionId, ?string $reason): void
    {
        $row = $this->mustFind($commissionId);
        if (!in_array($row['status'], ['claimed', 'pending', 'approved'], true)) {
            throw new LedgerException('Diese Vermittlung lässt sich nicht mehr ablehnen.', 409);
        }
        $this->store->updateCommission($commissionId, [
            'status' => 'rejected',
            'note' => self::clip(trim(($row['note'] ?? '') . "\nAbgelehnt: " . ($reason ?? '')), 500),
            'updated_at' => ($this->clock)(),
        ]);
    }

    /** pending → approved once paid and past the hold period. Cheap; run on reads. */
    public function mature(): int
    {
        return $this->store->matureDue(($this->clock)());
    }

    /**
     * Settle everything approved for a partner, clawbacks included.
     *
     * @return array<string,mixed> the payout
     */
    public function payout(int $partnerId, ?string $reference): array
    {
        $this->store->partner($partnerId) ?? throw new LedgerException('Partner nicht gefunden.', 404);
        $this->mature();
        $due = array_values(array_filter(
            $this->store->commissions($partnerId),
            static fn (array $c): bool => $c['status'] === 'approved',
        ));
        $total = array_sum(array_map(static fn (array $c): int => (int) $c['commission_cents'], $due));
        if ($due === [] || $total <= 0) {
            throw new LedgerException('Für diesen Partner ist nichts zur Auszahlung fällig.', 409);
        }
        $id = $this->store->createPayout(
            $partnerId,
            $total,
            self::clip($reference, 120),
            ($this->clock)(),
            array_map(static fn (array $c): int => $c['id'], $due),
        );
        return $this->store->payout($id) ?? [];
    }

    // --- rules -----------------------------------------------------------------

    /**
     * The partner-dependent fields: who, which rate, how much, which status.
     *
     * Rate precedence per line: product rate → partner rate → default. With no
     * lines the whole net amount takes the partner (or default) rate. The
     * stored `rate_bp` is the effective rate over the sale.
     *
     * @param array<string,mixed> $partner
     * @param array<string,mixed> $sale commission fields (net_cents, lines_json, customer_email, status, source_paid)
     * @return array<string,mixed>
     */
    private function attach(array $partner, array $sale): array
    {
        $partnerRate = $partner['rate_bp'] ?? $this->settings->defaultRateBp;
        $net = (int) $sale['net_cents'];
        $lines = json_decode((string) ($sale['lines_json'] ?? '[]'), true);
        $commission = 0;
        if (is_array($lines) && $lines !== []) {
            $productRates = $this->store->productRates();
            foreach ($lines as $line) {
                $pid = (string) ($line['productId'] ?? '');
                $rate = $pid !== '' && isset($productRates[$pid]) ? $productRates[$pid] : $partnerRate;
                $commission += (int) round((int) ($line['netCents'] ?? 0) * $rate / 10000);
            }
        } else {
            $commission = (int) round($net * $partnerRate / 10000);
        }
        $effective = $net > 0 ? (int) round($commission * 10000 / $net) : (int) $partnerRate;

        $buyer = strtolower(trim((string) ($sale['customer_email'] ?? '')));
        $own = $buyer !== '' && $buyer === strtolower(trim((string) ($partner['email'] ?? '')));

        return [
            'partner_id' => $partner['id'],
            'rate_bp' => $effective,
            'commission_cents' => $commission,
            'status' => $own ? 'rejected' : 'pending',
            'note' => $own
                ? self::clip(trim(($sale['note'] ?? '') . "\nEigenkauf des Partners — keine Provision."), 500)
                : ($sale['note'] ?? null),
        ];
    }

    /** @param array<string,mixed> $row */
    private function markPaid(array $row, string $now): void
    {
        if ($row['source_paid']) {
            return;
        }
        $this->store->updateCommission($row['id'], [
            'source_paid' => true,
            'paid_at' => $now,
            'approve_after' => $this->dueFrom($now),
            'updated_at' => $now,
        ]);
    }

    private function dueFrom(string $now): string
    {
        return date('Y-m-d H:i:s', (int) strtotime($now . ' +' . $this->settings->holdDays . ' days'));
    }

    /** @return array<string,mixed> */
    private function mustFind(int $id): array
    {
        return $this->store->commission($id) ?? throw new LedgerException('Vermittlung nicht gefunden.', 404);
    }

    private static function describe(SaleEvent $sale): string
    {
        $label = match ($sale->source) {
            'shop' => 'Shop-Bestellung',
            'billing' => 'Rechnung',
            default => ucfirst($sale->source),
        };
        return "{$label} #{$sale->sourceId}";
    }

    private static function clip(?string $value, int $limit): ?string
    {
        $v = trim((string) $value);
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }
}
