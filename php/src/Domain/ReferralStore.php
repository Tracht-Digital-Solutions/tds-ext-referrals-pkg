<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Domain;

/**
 * Persistence for partners, commissions and payouts. Rows are plain arrays
 * with the column names of the migration; ints are ints, `source_paid` a bool.
 *
 * An interface so {@see \Tds\Ext\Referrals\Service\CommissionLedger} — where
 * the money rules live — is testable without a database. The production
 * implementation is {@see PdoReferralStore}.
 */
interface ReferralStore
{
    /** @return list<array<string,mixed>> newest first */
    public function partners(): array;

    /** @return array<string,mixed>|null */
    public function partner(int $id): ?array;

    /** @return array<string,mixed>|null exact, already-normalised code */
    public function partnerByCode(string $code): ?array;

    /** @return array<string,mixed>|null */
    public function partnerByUser(int $userId): ?array;

    /** @return array<string,mixed>|null an unlinked partner (user_id NULL) with this email */
    public function unlinkedPartnerByEmail(string $email): ?array;

    /** @param array<string,mixed> $fields */
    public function insertPartner(array $fields): int;

    /** @param array<string,mixed> $fields */
    public function updatePartner(int $id, array $fields): void;

    /** @return array<string,int> product id => rate in basis points */
    public function productRates(): array;

    public function setProductRate(string $productId, ?int $rateBp): void;

    /** @return array<string,mixed>|null */
    public function commission(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function commissionBySource(string $source, string $sourceId): ?array;

    /**
     * @param array<string,mixed> $fields
     * @return int|null the new id, or null when `(source, source_id)` already exists
     */
    public function insertCommission(array $fields): ?int;

    /** @param array<string,mixed> $fields */
    public function updateCommission(int $id, array $fields): void;

    /** @return list<array<string,mixed>> newest first */
    public function commissions(?int $partnerId = null): array;

    /** pending + paid source + due → approved. @return int rows changed */
    public function matureDue(string $now): int;

    /**
     * Create a payout and mark the given commissions paid with it, atomically.
     *
     * @param list<int> $commissionIds
     */
    public function createPayout(int $partnerId, int $totalCents, ?string $reference, string $paidAt, array $commissionIds): int;

    /** @return list<array<string,mixed>> newest first */
    public function payouts(?int $partnerId = null): array;

    /** @return array<string,mixed>|null */
    public function payout(int $id): ?array;
}
