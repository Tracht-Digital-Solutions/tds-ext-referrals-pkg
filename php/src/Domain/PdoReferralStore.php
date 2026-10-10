<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Domain;

use PDO;

/**
 * {@see ReferralStore} on the shared PDO connection. Plain, portable SQL: every
 * timestamp is written by PHP (pinned Europe/Berlin, the NOW() clock), never by
 * a SQL function, so no condition mixes two clocks.
 */
final class PdoReferralStore implements ReferralStore
{
    private const PARTNER_COLUMNS = [
        'user_id', 'name', 'public_name', 'email', 'code', 'rate_bp', 'status',
        'payout_name', 'tax_status', 'vat_id', 'note', 'updated_at',
    ];

    private const COMMISSION_COLUMNS = [
        'partner_id', 'source', 'source_id', 'via', 'status', 'net_cents', 'rate_bp',
        'commission_cents', 'lines_json', 'description', 'customer_email', 'note',
        'source_paid', 'paid_at', 'approve_after', 'payout_id', 'created_at', 'updated_at',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function partners(): array
    {
        return array_map(self::partnerRow(...), $this->all('SELECT * FROM referral_partner ORDER BY id DESC'));
    }

    public function partner(int $id): ?array
    {
        return self::maybe(self::partnerRow(...), $this->one('SELECT * FROM referral_partner WHERE id = :id', [':id' => $id]));
    }

    public function partnerByCode(string $code): ?array
    {
        return self::maybe(self::partnerRow(...), $this->one('SELECT * FROM referral_partner WHERE code = :c', [':c' => $code]));
    }

    public function partnerByUser(int $userId): ?array
    {
        return self::maybe(self::partnerRow(...), $this->one('SELECT * FROM referral_partner WHERE user_id = :u', [':u' => $userId]));
    }

    public function unlinkedPartnerByEmail(string $email): ?array
    {
        return self::maybe(self::partnerRow(...), $this->one(
            'SELECT * FROM referral_partner WHERE user_id IS NULL AND LOWER(email) = :e ORDER BY id LIMIT 1',
            [':e' => strtolower($email)],
        ));
    }

    public function insertPartner(array $fields): int
    {
        return $this->insert('referral_partner', self::only($fields, self::PARTNER_COLUMNS));
    }

    public function updatePartner(int $id, array $fields): void
    {
        $this->update('referral_partner', $id, self::only($fields, self::PARTNER_COLUMNS));
    }

    public function productRates(): array
    {
        $out = [];
        foreach ($this->all('SELECT product_id, rate_bp FROM referral_product_rate ORDER BY product_id') as $row) {
            $out[(string) $row['product_id']] = (int) $row['rate_bp'];
        }
        return $out;
    }

    public function setProductRate(string $productId, ?int $rateBp): void
    {
        $this->pdo->prepare('DELETE FROM referral_product_rate WHERE product_id = :p')->execute([':p' => $productId]);
        if ($rateBp !== null) {
            $this->pdo->prepare('INSERT INTO referral_product_rate (product_id, rate_bp) VALUES (:p, :r)')
                ->execute([':p' => $productId, ':r' => $rateBp]);
        }
    }

    public function commission(int $id): ?array
    {
        return self::maybe(self::commissionRow(...), $this->one('SELECT * FROM referral_commission WHERE id = :id', [':id' => $id]));
    }

    public function commissionBySource(string $source, string $sourceId): ?array
    {
        return self::maybe(self::commissionRow(...), $this->one(
            'SELECT * FROM referral_commission WHERE source = :s AND source_id = :i',
            [':s' => $source, ':i' => $sourceId],
        ));
    }

    public function insertCommission(array $fields): ?int
    {
        try {
            return $this->insert('referral_commission', self::only($fields, self::COMMISSION_COLUMNS));
        } catch (\PDOException $e) {
            // 23000 = integrity constraint: a concurrent redelivery won the
            // unique (source, source_id). That is the no-op we want.
            if ((string) $e->getCode() === '23000') {
                return null;
            }
            throw $e;
        }
    }

    public function updateCommission(int $id, array $fields): void
    {
        $this->update('referral_commission', $id, self::only($fields, self::COMMISSION_COLUMNS));
    }

    public function commissions(?int $partnerId = null): array
    {
        $rows = $partnerId === null
            ? $this->all('SELECT * FROM referral_commission ORDER BY id DESC')
            : $this->all('SELECT * FROM referral_commission WHERE partner_id = :p ORDER BY id DESC', [':p' => $partnerId]);
        return array_map(self::commissionRow(...), $rows);
    }

    public function matureDue(string $now): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE referral_commission SET status = 'approved', updated_at = :now "
            . "WHERE status = 'pending' AND source_paid = 1 AND partner_id IS NOT NULL "
            . 'AND approve_after IS NOT NULL AND approve_after <= :due',
        );
        $stmt->execute([':now' => $now, ':due' => $now]);
        return $stmt->rowCount();
    }

    public function createPayout(int $partnerId, int $totalCents, ?string $reference, string $paidAt, array $commissionIds): int
    {
        $this->pdo->beginTransaction();
        try {
            $id = $this->insert('referral_payout', [
                'partner_id' => $partnerId,
                'total_cents' => $totalCents,
                'reference' => $reference,
                'paid_at' => $paidAt,
            ]);
            $stmt = $this->pdo->prepare(
                "UPDATE referral_commission SET status = 'paid', payout_id = :pid, updated_at = :now "
                . "WHERE id = :id AND partner_id = :partner AND status = 'approved'",
            );
            foreach ($commissionIds as $cid) {
                $stmt->execute([':pid' => $id, ':now' => $paidAt, ':id' => $cid, ':partner' => $partnerId]);
                if ($stmt->rowCount() !== 1) {
                    throw new \RuntimeException("Provision {$cid} ist nicht mehr freigegeben.");
                }
            }
            $this->pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function payouts(?int $partnerId = null): array
    {
        $rows = $partnerId === null
            ? $this->all('SELECT * FROM referral_payout ORDER BY id DESC')
            : $this->all('SELECT * FROM referral_payout WHERE partner_id = :p ORDER BY id DESC', [':p' => $partnerId]);
        return array_map(self::payoutRow(...), $rows);
    }

    public function payout(int $id): ?array
    {
        return self::maybe(self::payoutRow(...), $this->one('SELECT * FROM referral_payout WHERE id = :id', [':id' => $id]));
    }

    // --- helpers ---------------------------------------------------------------

    /** @param array<string,mixed> $fields */
    private function insert(string $table, array $fields): int
    {
        $cols = array_keys($fields);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $cols)),
        );
        $this->pdo->prepare($sql)->execute(self::params($fields));
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    private function update(string $table, int $id, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $set = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", array_keys($fields)));
        // `:row_id`, not `:id`: native prepares reject a name used twice, and a
        // future `id` field must not collide with the key.
        $this->pdo->prepare("UPDATE {$table} SET {$set} WHERE id = :row_id")
            ->execute(self::params($fields) + [':row_id' => $id]);
    }

    /** @param array<string,mixed> $fields @return array<string,mixed> */
    private static function params(array $fields): array
    {
        $out = [];
        foreach ($fields as $k => $v) {
            $out[':' . $k] = is_bool($v) ? (int) $v : $v;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $fields
     * @param list<string>        $allowed
     * @return array<string,mixed>
     */
    private static function only(array $fields, array $allowed): array
    {
        return array_intersect_key($fields, array_flip($allowed));
    }

    /** @param array<string,mixed> $params @return list<array<string,mixed>> */
    private function all(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<string,mixed> $params @return array<string,mixed>|null */
    private function one(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $map @param array<string,mixed>|null $row */
    private static function maybe(callable $map, ?array $row): ?array
    {
        return $row === null ? null : $map($row);
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function partnerRow(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['user_id'] = $r['user_id'] === null ? null : (int) $r['user_id'];
        $r['rate_bp'] = $r['rate_bp'] === null ? null : (int) $r['rate_bp'];
        return $r;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function commissionRow(array $r): array
    {
        foreach (['id', 'net_cents'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        foreach (['partner_id', 'rate_bp', 'commission_cents', 'payout_id'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        $r['source_paid'] = (bool) (int) $r['source_paid'];
        return $r;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function payoutRow(array $r): array
    {
        foreach (['id', 'partner_id', 'total_cents'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        return $r;
    }
}
