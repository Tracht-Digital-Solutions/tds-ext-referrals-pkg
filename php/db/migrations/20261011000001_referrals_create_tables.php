<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Partners, their commissions and the payouts that settle them.
 *
 * A partner is a person who brings in a sale without being the buyer. They
 * may have a portal login (`user_id`) or not: a buyer who names somebody at
 * checkout creates a `claimed` commission for a person who may not exist here
 * yet, and the operator assigns it.
 *
 * `(source, source_id)` is unique: sellers dispatch on EVERY paid webhook
 * delivery, so the key is what makes a redelivery a no-op.
 *
 * Timestamps follow the platform convention: PHP-written wall-clock time in the
 * pinned Europe/Berlin zone, the same clock as NOW()/CURRENT_TIMESTAMP.
 * Payout bank data does NOT live here — the IBAN is a secret in the settings
 * store (namespace `referrals_payout`), encrypted with SETTINGS_ENCRYPTION_KEY.
 */
final class ReferralsCreateTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('referral_partner', ['signed' => false])
            ->addColumn('user_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 200])
            ->addColumn('public_name', 'string', ['limit' => 120])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('code', 'string', ['limit' => 40])
            ->addColumn('rate_bp', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 16, 'default' => 'active'])
            ->addColumn('payout_name', 'string', ['limit' => 200, 'null' => true])
            ->addColumn('tax_status', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('vat_id', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['code'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addIndex(['email'])
            ->create();

        $this->table('referral_product_rate', ['id' => false, 'primary_key' => ['product_id']])
            ->addColumn('product_id', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('rate_bp', 'integer', ['signed' => false])
            ->create();

        $this->table('referral_payout', ['signed' => false])
            ->addColumn('partner_id', 'integer', ['signed' => false])
            ->addColumn('total_cents', 'integer')
            ->addColumn('reference', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('paid_at', 'datetime')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['partner_id'])
            ->create();

        $this->table('referral_commission', ['signed' => false])
            ->addColumn('partner_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('source', 'string', ['limit' => 20])
            ->addColumn('source_id', 'string', ['limit' => 64])
            ->addColumn('via', 'string', ['limit' => 10])
            ->addColumn('status', 'string', ['limit' => 12])
            ->addColumn('net_cents', 'integer')
            ->addColumn('rate_bp', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('commission_cents', 'integer', ['null' => true])
            ->addColumn('lines_json', 'text', ['null' => true])
            ->addColumn('description', 'string', ['limit' => 300, 'null' => true])
            ->addColumn('customer_email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('note', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('source_paid', 'boolean', ['default' => false])
            ->addColumn('paid_at', 'datetime', ['null' => true])
            ->addColumn('approve_after', 'datetime', ['null' => true])
            ->addColumn('payout_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['source', 'source_id'], ['unique' => true])
            ->addIndex(['partner_id', 'status'])
            ->addIndex(['status', 'approve_after'])
            ->create();
    }
}
