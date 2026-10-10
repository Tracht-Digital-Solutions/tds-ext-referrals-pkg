<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Tests\Support;

use Tds\Ext\Referrals\Domain\ReferralStore;

/** In-memory {@see ReferralStore} with the same row shapes and the same unique key as the migration. */
final class MemoryReferralStore implements ReferralStore
{
    /** @var array<int,array<string,mixed>> */
    public array $partners = [];
    /** @var array<int,array<string,mixed>> */
    public array $commissions = [];
    /** @var array<int,array<string,mixed>> */
    public array $payouts = [];
    /** @var array<string,int> */
    public array $rates = [];

    public function partners(): array
    {
        return array_values(array_reverse($this->partners, true));
    }

    public function partner(int $id): ?array
    {
        return $this->partners[$id] ?? null;
    }

    public function partnerByCode(string $code): ?array
    {
        foreach ($this->partners as $p) {
            if ($p['code'] === $code) {
                return $p;
            }
        }
        return null;
    }

    public function partnerByUser(int $userId): ?array
    {
        foreach ($this->partners as $p) {
            if ($p['user_id'] === $userId) {
                return $p;
            }
        }
        return null;
    }

    public function unlinkedPartnerByEmail(string $email): ?array
    {
        foreach ($this->partners as $p) {
            if ($p['user_id'] === null && strtolower((string) $p['email']) === strtolower($email)) {
                return $p;
            }
        }
        return null;
    }

    public function insertPartner(array $fields): int
    {
        $id = count($this->partners) + 1;
        $this->partners[$id] = $fields + [
            'id' => $id, 'user_id' => null, 'email' => null, 'rate_bp' => null, 'status' => 'active',
            'payout_name' => null, 'tax_status' => null, 'vat_id' => null, 'note' => null,
            'created_at' => '2026-10-01 10:00:00', 'updated_at' => null,
        ];
        $this->partners[$id]['id'] = $id;
        return $id;
    }

    public function updatePartner(int $id, array $fields): void
    {
        $this->partners[$id] = $fields + $this->partners[$id];
    }

    public function productRates(): array
    {
        return $this->rates;
    }

    public function setProductRate(string $productId, ?int $rateBp): void
    {
        unset($this->rates[$productId]);
        if ($rateBp !== null) {
            $this->rates[$productId] = $rateBp;
        }
    }

    public function commission(int $id): ?array
    {
        return $this->commissions[$id] ?? null;
    }

    public function commissionBySource(string $source, string $sourceId): ?array
    {
        foreach ($this->commissions as $c) {
            if ($c['source'] === $source && $c['source_id'] === $sourceId) {
                return $c;
            }
        }
        return null;
    }

    public function insertCommission(array $fields): ?int
    {
        if ($this->commissionBySource((string) $fields['source'], (string) $fields['source_id']) !== null) {
            return null;
        }
        $id = count($this->commissions) + 1;
        $this->commissions[$id] = $fields + [
            'partner_id' => null, 'rate_bp' => null, 'commission_cents' => null, 'lines_json' => null,
            'description' => null, 'customer_email' => null, 'note' => null, 'source_paid' => false,
            'paid_at' => null, 'approve_after' => null, 'payout_id' => null, 'updated_at' => null,
            'created_at' => '2026-10-01 10:00:00',
        ];
        $this->commissions[$id]['id'] = $id;
        return $id;
    }

    public function updateCommission(int $id, array $fields): void
    {
        $this->commissions[$id] = $fields + $this->commissions[$id];
    }

    public function commissions(?int $partnerId = null): array
    {
        $rows = array_filter($this->commissions, static fn (array $c): bool => $partnerId === null || $c['partner_id'] === $partnerId);
        return array_values(array_reverse($rows, true));
    }

    public function matureDue(string $now): int
    {
        $n = 0;
        foreach ($this->commissions as $id => $c) {
            if ($c['status'] === 'pending' && $c['source_paid'] && $c['partner_id'] !== null && $c['approve_after'] !== null && $c['approve_after'] <= $now) {
                $this->commissions[$id]['status'] = 'approved';
                $n++;
            }
        }
        return $n;
    }

    public function createPayout(int $partnerId, int $totalCents, ?string $reference, string $paidAt, array $commissionIds): int
    {
        $id = count($this->payouts) + 1;
        $this->payouts[$id] = [
            'id' => $id, 'partner_id' => $partnerId, 'total_cents' => $totalCents,
            'reference' => $reference, 'paid_at' => $paidAt, 'created_at' => $paidAt,
        ];
        foreach ($commissionIds as $cid) {
            $this->commissions[$cid]['status'] = 'paid';
            $this->commissions[$cid]['payout_id'] = $id;
        }
        return $id;
    }

    public function payouts(?int $partnerId = null): array
    {
        $rows = array_filter($this->payouts, static fn (array $p): bool => $partnerId === null || $p['partner_id'] === $partnerId);
        return array_values(array_reverse($rows, true));
    }

    public function payout(int $id): ?array
    {
        return $this->payouts[$id] ?? null;
    }
}
