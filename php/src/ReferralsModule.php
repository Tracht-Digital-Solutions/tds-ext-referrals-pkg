<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals;

use PDO;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Tds\Ext\Referrals\Domain\PdoReferralStore;
use Tds\Ext\Referrals\Domain\ReferralStore;
use Tds\Ext\Referrals\Service\CommissionLedger;
use Tds\Ext\Referrals\Service\LedgerException;
use Tds\Ext\Referrals\Service\ReferralSettings;
use Tds\Ext\Referrals\Support\Iban;
use Tds\Ext\Referrals\Support\PartnerCode;
use Tds\Frontend\Contract\AbstractModule;
use Tds\Frontend\Contract\ApiDocSource;
use Tds\Frontend\Contract\Commerce\ReferralMatch;
use Tds\Frontend\Contract\Commerce\ReferralResolver;
use Tds\Frontend\Contract\Commerce\SaleEvent;
use Tds\Frontend\Contract\Commerce\SaleListener;
use Tds\Frontend\Contract\ModuleHttp;
use Tds\Frontend\Contract\PermissionDef;
use Tds\Frontend\Contract\SettingDef;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\SetupStatusSource;
use Tds\Frontend\Contract\UserContext;

/**
 * Empfehlungsprogramm: people who bring in a sale without being the buyer earn
 * a commission on its net value.
 *
 * Sales arrive through the contract's {@see SaleListener} (shop orders, billing
 * invoices); codes are answered through {@see ReferralResolver}. Jobs closed
 * outside both are recorded by hand. The operator pays out manually; this
 * module keeps the ledger and the statement.
 *
 * Admin routes need `referrals:read` / `referrals:manage` (admins bypass). The
 * portal routes need `referrals:partner` and are bound to the signed-in USER,
 * not to the active company: a partner is a person, often without a company.
 */
final class ReferralsModule extends AbstractModule implements ApiDocSource, SetupStatusSource, SaleListener, ReferralResolver
{
    use ModuleHttp;

    private const PAYOUT_NS = 'referrals_payout';
    private const TAX_STATUSES = ['private', 'small_business', 'vat'];
    private const VAT_RATE_BP = 1900;

    private ?ContainerInterface $c = null;

    /**
     * @param ReferralStore|null        $store for tests; production builds the PDO store
     * @param (callable(): string)|null $clock for tests; Berlin wall clock otherwise
     */
    public function __construct(
        private readonly ?ReferralStore $store = null,
        private $clock = null,
    ) {
    }

    public function id(): string
    {
        return 'referrals';
    }

    /** @return PermissionDef[] */
    public function permissions(): array
    {
        return [
            new PermissionDef('referrals:read', 'Provisionen ansehen', 'referrals'),
            new PermissionDef('referrals:manage', 'Partner und Provisionen verwalten', 'referrals'),
            new PermissionDef('referrals:partner', 'Eigenes Partnerkonto (Portal: Weiterempfehlen)', 'referrals'),
        ];
    }

    /** @return string[] */
    public function migrations(): array
    {
        return [__DIR__ . '/../db/migrations'];
    }

    /** @return SettingDef[] */
    public function settings(): array
    {
        return [
            new SettingDef('default_rate_percent', 'Standard-Provision (% vom Netto)', false, self::NS(), ReferralSettings::DEFAULT_RATE_PERCENT),
            new SettingDef('hold_days', 'Wartefrist nach Zahlung (Tage)', false, self::NS(), ReferralSettings::DEFAULT_HOLD_DAYS),
            new SettingDef('link_days', 'Gültigkeit eines Empfehlungslinks (Tage)', false, self::NS(), ReferralSettings::DEFAULT_LINK_DAYS),
            new SettingDef('link_base', 'Ziel des Empfehlungslinks', false, self::NS(), ReferralSettings::DEFAULT_LINK_BASE),
            new SettingDef('terms_url', 'Adresse der Partnerbedingungen', false, self::NS(), ''),
        ];
    }

    /** @return list<array<string,string>> */
    public function setupItems(UserContext $user): array
    {
        try {
            $terms = $this->settingsNow()->termsUrl;
        } catch (\Throwable) {
            return [];
        }
        return [[
            'id' => 'referrals:terms',
            'module' => 'referrals',
            'title' => 'Empfehlungsprogramm: Partnerbedingungen',
            'description' => 'Ohne veröffentlichte Partnerbedingungen wissen Partner nicht, welche Provision, Wartefrist und Auszahlung gelten.',
            'state' => $terms !== '' ? 'ok' : 'missing',
            'level' => 'recommended',
            'href' => '/einstellungen#settings-referrals',
        ]];
    }

    // --- contract capabilities -------------------------------------------------

    public function onSalePaid(SaleEvent $sale): void
    {
        try {
            $this->ledger()?->onSalePaid($sale);
        } catch (\Throwable $e) {
            error_log('referrals: onSalePaid failed: ' . $e->getMessage());
        }
    }

    public function onSaleReversed(string $source, string $sourceId): void
    {
        try {
            $this->ledger()?->onSaleReversed($source, $sourceId);
        } catch (\Throwable $e) {
            error_log('referrals: onSaleReversed failed: ' . $e->getMessage());
        }
    }

    public function resolveReferral(string $code): ?ReferralMatch
    {
        $normalized = PartnerCode::normalize($code);
        if ($normalized === null) {
            return null;
        }
        try {
            $partner = $this->storeNow()?->partnerByCode($normalized);
        } catch (\Throwable) {
            return null;
        }
        if ($partner === null || $partner['status'] !== 'active') {
            return null;
        }
        return new ReferralMatch($normalized, (string) $partner['public_name']);
    }

    // --- routes ----------------------------------------------------------------

    public function register(App $app): void
    {
        $this->c = $app->getContainer();
        $module = $this;

        // --- admin -------------------------------------------------------------
        $app->get('/admin/referrals/overview', function (Request $req, Response $res) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:read', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $res): Response {
                $store = $module->storeOrFail();
                $module->ledgerOrFail()->mature();
                $settings = $module->settingsNow();
                $partners = $store->partners();
                $names = array_column($partners, 'name', 'id');
                return self::json($res, [
                    'partners' => array_map(fn (array $p): array => $module->partnerView($p, $settings, true), $partners),
                    'commissions' => array_map(static fn (array $c): array => self::commissionView($c) + [
                        'partner_name' => $c['partner_id'] !== null ? ($names[$c['partner_id']] ?? null) : null,
                        'customer_email' => $c['customer_email'],
                        'note' => $c['note'],
                        'source' => $c['source'],
                        'source_id' => $c['source_id'],
                    ], $store->commissions()),
                    'payouts' => array_map(static fn (array $p): array => $p + ['partner_name' => $names[$p['partner_id']] ?? null], $store->payouts()),
                    'product_rates' => array_map(
                        static fn (string $pid, int $bp): array => ['product_id' => $pid, 'rate_percent' => $bp / 100],
                        array_keys($store->productRates()),
                        array_values($store->productRates()),
                    ),
                    'settings' => self::settingsView($settings),
                ]);
            });
        });

        $app->post('/admin/referrals/partners', function (Request $req, Response $res) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $req, $res): Response {
                $store = $module->storeOrFail();
                $fields = self::partnerFields((array) $req->getParsedBody(), null);
                $fields['code'] ??= self::freeCode($store, (string) $fields['name']);
                if ($store->partnerByCode((string) $fields['code']) !== null) {
                    throw new LedgerException('Dieser Code ist schon vergeben.', 409);
                }
                $id = $store->insertPartner($fields + ['status' => 'active']);
                return self::json($res, $module->partnerView($store->partner($id) ?? [], $module->settingsNow(), true), 201);
            });
        });

        $app->put('/admin/referrals/partners/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $req, $res, $args): Response {
                $store = $module->storeOrFail();
                $id = (int) $args['id'];
                $current = $store->partner($id) ?? throw new LedgerException('Partner nicht gefunden.', 404);
                $fields = self::partnerFields((array) $req->getParsedBody(), $current);
                if (isset($fields['code']) && $fields['code'] !== $current['code'] && $store->partnerByCode((string) $fields['code']) !== null) {
                    throw new LedgerException('Dieser Code ist schon vergeben.', 409);
                }
                $store->updatePartner($id, $fields + ['updated_at' => $module->now()]);
                return self::json($res, $module->partnerView($store->partner($id) ?? [], $module->settingsNow(), true));
            });
        });

        $app->put('/admin/referrals/partners/{id:[0-9]+}/payout-details', function (Request $req, Response $res, array $args) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $req, $res, $args): Response {
                $partner = $module->storeOrFail()->partner((int) $args['id']) ?? throw new LedgerException('Partner nicht gefunden.', 404);
                $module->savePayoutDetails($partner, (array) $req->getParsedBody());
                return self::json($res, $module->partnerView($module->storeOrFail()->partner($partner['id']) ?? [], $module->settingsNow(), true));
            });
        });

        $app->put('/admin/referrals/product-rates', function (Request $req, Response $res) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $req, $res): Response {
                $body = (array) $req->getParsedBody();
                $pid = trim((string) ($body['product_id'] ?? ''));
                if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $pid) !== 1) {
                    throw new LedgerException('Ungültige Produkt-ID.');
                }
                $raw = $body['rate_percent'] ?? null;
                $bp = $raw === null || $raw === '' ? null : ReferralSettings::percentToBp($raw);
                if ($raw !== null && $raw !== '' && $bp === null) {
                    throw new LedgerException('Der Satz muss zwischen 0 und 100 % liegen.');
                }
                $module->storeOrFail()->setProductRate($pid, $bp);
                return self::json($res, ['product_id' => $pid, 'rate_percent' => $bp === null ? null : $bp / 100]);
            });
        });

        $app->post('/admin/referrals/commissions', function (Request $req, Response $res) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $req, $res): Response {
                $b = (array) $req->getParsedBody();
                $invoiceId = isset($b['invoice_id']) && $b['invoice_id'] !== '' && $b['invoice_id'] !== null ? (int) $b['invoice_id'] : null;
                $paid = (bool) ($b['paid'] ?? false) || ($invoiceId !== null && $module->invoicePaid($invoiceId));
                $row = $module->ledgerOrFail()->recordManual(
                    (int) ($b['partner_id'] ?? 0),
                    (int) ($b['net_cents'] ?? 0),
                    (string) ($b['description'] ?? ''),
                    isset($b['customer_email']) ? (string) $b['customer_email'] : null,
                    $invoiceId,
                    $paid,
                );
                return self::json($res, self::commissionView($row), 201);
            });
        });

        foreach (['assign', 'approve', 'reject', 'confirm-paid'] as $action) {
            $app->post("/admin/referrals/commissions/{id:[0-9]+}/{$action}", function (Request $req, Response $res, array $args) use ($module, $action): Response {
                if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                    return $deny;
                }
                return $module->guard($res, static function () use ($module, $req, $res, $args, $action): Response {
                    $ledger = $module->ledgerOrFail();
                    $id = (int) $args['id'];
                    $b = (array) $req->getParsedBody();
                    match ($action) {
                        'assign' => $ledger->assign($id, (int) ($b['partner_id'] ?? 0)),
                        'approve' => $ledger->approve($id),
                        'reject' => $ledger->reject($id, isset($b['reason']) ? (string) $b['reason'] : null),
                        'confirm-paid' => $ledger->confirmPaid($id),
                    };
                    return self::json($res, self::commissionView($module->storeOrFail()->commission($id) ?? []));
                });
            });
        }

        $app->post('/admin/referrals/payouts', function (Request $req, Response $res) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:manage', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $req, $res): Response {
                $b = (array) $req->getParsedBody();
                $payout = $module->ledgerOrFail()->payout((int) ($b['partner_id'] ?? 0), isset($b['reference']) ? (string) $b['reference'] : null);
                return self::json($res, $payout, 201);
            });
        });

        $app->get('/admin/referrals/payouts/{id:[0-9]+}', function (Request $req, Response $res, array $args) use ($module): Response {
            if (($deny = self::require($module->user(), 'referrals:read', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $res, $args): Response {
                $store = $module->storeOrFail();
                $payout = $store->payout((int) $args['id']) ?? throw new LedgerException('Auszahlung nicht gefunden.', 404);
                $partner = $store->partner($payout['partner_id']) ?? [];
                $lines = array_values(array_filter(
                    $store->commissions($payout['partner_id']),
                    static fn (array $c): bool => $c['payout_id'] === $payout['id'],
                ));
                $vat = ($partner['tax_status'] ?? null) === 'vat' ? (int) round($payout['total_cents'] * self::VAT_RATE_BP / 10000) : 0;
                return self::json($res, [
                    'payout' => $payout + ['vat_cents' => $vat, 'gross_cents' => $payout['total_cents'] + $vat],
                    // The full IBAN, on purpose and only here: this is the page
                    // the operator transfers the money from.
                    'partner' => $module->partnerView($partner, $module->settingsNow(), true) + ['iban' => $module->iban($partner['id'] ?? 0)],
                    'lines' => array_map(self::commissionView(...), $lines),
                ]);
            });
        });

        // --- portal (the signed-in partner) -------------------------------------
        $app->get('/referrals/me', function (Request $req, Response $res) use ($module): Response {
            $user = $module->user();
            if (($deny = self::require($user, 'referrals:partner', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $user, $res): Response {
                $partner = $module->partnerFor($user) ?? throw new LedgerException('Ihr Zugang ist noch keinem Partnerkonto zugeordnet.', 404);
                $module->ledgerOrFail()->mature();
                $store = $module->storeOrFail();
                $settings = $module->settingsNow();
                // Claimed rows have no partner, so they never reach here. The
                // buyer's address stays with the operator.
                $commissions = array_map(self::commissionView(...), $store->commissions($partner['id']));
                $sum = static fn (string $status): int => array_sum(array_map(
                    static fn (array $c): int => (int) ($c['commission_cents'] ?? 0),
                    array_filter($commissions, static fn (array $c): bool => $c['status'] === $status),
                ));
                return self::json($res, [
                    'partner' => $module->partnerView($partner, $settings, false),
                    'commissions' => $commissions,
                    'totals' => ['pending' => $sum('pending'), 'approved' => $sum('approved'), 'paid' => $sum('paid')],
                    'payouts' => $store->payouts($partner['id']),
                    'settings' => self::settingsView($settings),
                ]);
            });
        });

        $app->put('/referrals/me/payout-details', function (Request $req, Response $res) use ($module): Response {
            $user = $module->user();
            if (($deny = self::require($user, 'referrals:partner', $res)) !== null) {
                return $deny;
            }
            return $module->guard($res, static function () use ($module, $user, $req, $res): Response {
                $partner = $module->partnerFor($user) ?? throw new LedgerException('Ihr Zugang ist noch keinem Partnerkonto zugeordnet.', 404);
                $module->savePayoutDetails($partner, (array) $req->getParsedBody());
                return self::json($res, $module->partnerView($module->storeOrFail()->partner($partner['id']) ?? [], $module->settingsNow(), false));
            });
        });
    }

    /** @return list<array<string, mixed>> */
    public function apiDocs(): array
    {
        return require __DIR__ . '/../docs/api.php';
    }

    // --- wiring (public for the route closures, not part of any contract) -------

    /** @internal */
    public function user(): UserContext
    {
        return $this->c?->get(UserContext::class) ?? throw new \LogicException('no container');
    }

    /** @internal */
    public function now(): string
    {
        return $this->clock !== null ? ($this->clock)() : date('Y-m-d H:i:s');
    }

    /** @internal */
    public function storeOrFail(): ReferralStore
    {
        return $this->storeNow() ?? throw new LedgerException('Datenbank nicht verfügbar.', 503);
    }

    /** @internal */
    public function ledgerOrFail(): CommissionLedger
    {
        return $this->ledger() ?? throw new LedgerException('Datenbank nicht verfügbar.', 503);
    }

    /** @internal */
    public function settingsNow(): ReferralSettings
    {
        return ReferralSettings::from($this->settingsStore());
    }

    /**
     * Turn route work into a response: a {@see LedgerException} is the
     * operator's message with its status, anything else a logged 500.
     *
     * @internal
     * @param callable(): Response $work
     */
    public function guard(Response $res, callable $work): Response
    {
        try {
            return $work();
        } catch (LedgerException $e) {
            return self::json($res, ['error' => $e->getMessage()], $e->status());
        } catch (\Throwable $e) {
            error_log('referrals: ' . $e->getMessage());
            return self::json($res, ['error' => 'Interner Fehler.'], 500);
        }
    }

    /**
     * The signed-in user's partner. A partner the operator created with this
     * user's sign-in address is linked on first visit — that is the whole
     * invitation flow: create the partner, invite the user, done.
     *
     * @internal
     * @return array<string,mixed>|null
     */
    public function partnerFor(UserContext $user): ?array
    {
        $store = $this->storeOrFail();
        $uid = $user->userId();
        if ($uid === null) {
            return null;
        }
        $partner = $store->partnerByUser($uid);
        if ($partner !== null) {
            return $partner;
        }
        $email = trim((string) $user->email());
        if ($email === '') {
            return null;
        }
        $unlinked = $store->unlinkedPartnerByEmail($email);
        if ($unlinked === null) {
            return null;
        }
        $store->updatePartner($unlinked['id'], ['user_id' => $uid, 'updated_at' => $this->now()]);
        return $store->partner($unlinked['id']);
    }

    /**
     * @internal
     * @param array<string,mixed> $partner
     * @param array<string,mixed> $b
     */
    public function savePayoutDetails(array $partner, array $b): void
    {
        $fields = ['updated_at' => $this->now()];
        if (array_key_exists('payout_name', $b)) {
            $fields['payout_name'] = self::text($b['payout_name'], 200);
        }
        if (array_key_exists('tax_status', $b)) {
            $tax = (string) $b['tax_status'];
            if ($tax !== '' && !in_array($tax, self::TAX_STATUSES, true)) {
                throw new LedgerException('Unbekannter Steuerstatus.');
            }
            $fields['tax_status'] = $tax === '' ? null : $tax;
        }
        if (array_key_exists('vat_id', $b)) {
            $vat = strtoupper((string) preg_replace('/\s+/', '', (string) $b['vat_id']));
            if ($vat !== '' && preg_match('/^[A-Z]{2}[A-Z0-9]{2,13}$/', $vat) !== 1) {
                throw new LedgerException('Die USt-IdNr. sieht nicht gültig aus.');
            }
            $fields['vat_id'] = $vat === '' ? null : $vat;
        }
        $taxAfter = array_key_exists('tax_status', $fields) ? $fields['tax_status'] : $partner['tax_status'];
        $vatAfter = array_key_exists('vat_id', $fields) ? $fields['vat_id'] : $partner['vat_id'];
        if ($taxAfter === 'vat' && $vatAfter === null) {
            throw new LedgerException('Bei Umsatzsteuerpflicht wird die USt-IdNr. für die Gutschrift gebraucht.');
        }
        $iban = Iban::normalize((string) ($b['iban'] ?? ''));
        if ($iban !== '') {
            if (!Iban::valid($iban)) {
                throw new LedgerException('Die IBAN ist ungültig.');
            }
            $store = $this->settingsStore() ?? throw new LedgerException('Einstellungsspeicher nicht verfügbar.', 503);
            try {
                $store->set(self::PAYOUT_NS, self::ibanKey($partner['id']), $iban, true);
            } catch (\Throwable) {
                throw new LedgerException('Bankdaten können gerade nicht verschlüsselt gespeichert werden.', 503);
            }
        }
        $this->storeOrFail()->updatePartner($partner['id'], $fields);
    }

    /** @internal */
    public function iban(int $partnerId): ?string
    {
        try {
            return $this->settingsStore()?->getSecret(self::PAYOUT_NS, self::ibanKey($partnerId));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @internal
     * @param array<string,mixed> $p
     * @return array<string,mixed>
     */
    public function partnerView(array $p, ReferralSettings $settings, bool $admin): array
    {
        if ($p === []) {
            return [];
        }
        $base = $settings->linkBase;
        $view = [
            'id' => $p['id'],
            'name' => $p['name'],
            'public_name' => $p['public_name'],
            'code' => $p['code'],
            'link' => $base . (str_contains($base, '?') ? '&' : '?') . 'ref=' . rawurlencode((string) $p['code']),
            'rate_percent' => ($p['rate_bp'] ?? $settings->defaultRateBp) / 100,
            'own_rate' => $p['rate_bp'] !== null,
            'status' => $p['status'],
            'payout_name' => $p['payout_name'],
            'tax_status' => $p['tax_status'],
            'vat_id' => $p['vat_id'],
            'iban_masked' => Iban::mask($this->iban($p['id'])),
        ];
        if ($admin) {
            $view += ['email' => $p['email'], 'user_id' => $p['user_id'], 'note' => $p['note'], 'created_at' => $p['created_at']];
        }
        return $view;
    }

    /**
     * Whether a billing invoice is already paid. Soft: the billing table may be
     * absent, and then the answer is simply "not known to be paid".
     *
     * @internal
     */
    public function invoicePaid(int $invoiceId): bool
    {
        try {
            $pdo = $this->c?->get(PDO::class);
            if (!$pdo instanceof PDO) {
                return false;
            }
            $stmt = $pdo->prepare('SELECT status FROM billing_invoice WHERE id = :id');
            $stmt->execute([':id' => $invoiceId]);
            return $stmt->fetchColumn() === 'paid';
        } catch (\Throwable) {
            return false;
        }
    }

    // --- helpers ---------------------------------------------------------------

    private function storeNow(): ?ReferralStore
    {
        if ($this->store !== null) {
            return $this->store;
        }
        if ($this->c === null) {
            return null;
        }
        $pdo = $this->c->get(PDO::class);
        return $pdo instanceof PDO ? new PdoReferralStore($pdo) : null;
    }

    private function ledger(): ?CommissionLedger
    {
        $store = $this->storeNow();
        return $store === null ? null : new CommissionLedger($store, $this->settingsNow(), $this->clock);
    }

    private function settingsStore(): ?SettingsStore
    {
        if ($this->c === null || !$this->c->has(SettingsStore::class)) {
            return null;
        }
        $store = $this->c->get(SettingsStore::class);
        return $store instanceof SettingsStore ? $store : null;
    }

    private static function NS(): string
    {
        return ReferralSettings::NS;
    }

    private static function ibanKey(int $partnerId): string
    {
        return 'iban_' . $partnerId;
    }

    /** @return array<string,mixed> */
    private static function settingsView(ReferralSettings $s): array
    {
        return [
            'default_rate_percent' => $s->defaultRateBp / 100,
            'hold_days' => $s->holdDays,
            'link_days' => $s->linkDays,
            'terms_url' => $s->termsUrl,
        ];
    }

    /**
     * What a commission looks like outside the operator's own rows: no buyer
     * address, no internal note.
     *
     * @param array<string,mixed> $c
     * @return array<string,mixed>
     */
    private static function commissionView(array $c): array
    {
        if ($c === []) {
            return [];
        }
        return [
            'id' => $c['id'],
            'partner_id' => $c['partner_id'],
            'description' => match ($c['source']) {
                'shop' => 'Shop-Bestellung',
                default => $c['description'],
            },
            'via' => $c['via'],
            'status' => $c['status'],
            'net_cents' => $c['net_cents'],
            'rate_percent' => $c['rate_bp'] === null ? null : $c['rate_bp'] / 100,
            'commission_cents' => $c['commission_cents'],
            'source_paid' => $c['source_paid'],
            'approve_after' => $c['approve_after'],
            'payout_id' => $c['payout_id'],
            'created_at' => $c['created_at'],
        ];
    }

    /**
     * @param array<string,mixed>      $b
     * @param array<string,mixed>|null $current null when creating
     * @return array<string,mixed>
     */
    private static function partnerFields(array $b, ?array $current): array
    {
        $out = [];
        if ($current === null || array_key_exists('name', $b)) {
            $out['name'] = self::text($b['name'] ?? null, 200) ?? throw new LedgerException('Bitte einen Namen angeben.');
        }
        if ($current === null || array_key_exists('public_name', $b)) {
            $out['public_name'] = self::text($b['public_name'] ?? null, 120)
                ?? self::publicNameFrom((string) ($out['name'] ?? $current['name'] ?? ''));
        }
        if (array_key_exists('email', $b)) {
            $email = self::text($b['email'], 254);
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new LedgerException('Die E-Mail-Adresse ist ungültig.');
            }
            $out['email'] = $email === null ? null : strtolower($email);
        }
        if (array_key_exists('code', $b) && trim((string) $b['code']) !== '') {
            $out['code'] = PartnerCode::normalize((string) $b['code'])
                ?? throw new LedgerException('Der Code darf nur Buchstaben, Ziffern und Bindestriche enthalten (3–40 Zeichen).');
        }
        if (array_key_exists('rate_percent', $b)) {
            $raw = $b['rate_percent'];
            $bp = $raw === null || $raw === '' ? null : ReferralSettings::percentToBp($raw);
            if ($raw !== null && $raw !== '' && $bp === null) {
                throw new LedgerException('Der Satz muss zwischen 0 und 100 % liegen.');
            }
            $out['rate_bp'] = $bp;
        }
        if (array_key_exists('status', $b)) {
            $status = (string) $b['status'];
            if (!in_array($status, ['active', 'paused'], true)) {
                throw new LedgerException('Status muss „active“ oder „paused“ sein.');
            }
            $out['status'] = $status;
        }
        if (array_key_exists('note', $b)) {
            $out['note'] = self::text($b['note'], 2000);
        }
        return $out;
    }

    /** "Anna Beispiel" → "Anna B." — what a buyer sees next to "Empfohlen von". */
    private static function publicNameFrom(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? '';
        $last = count($parts) > 1 ? end($parts) : '';
        return trim($first . ($last !== '' ? ' ' . mb_substr($last, 0, 1) . '.' : ''));
    }

    private static function freeCode(ReferralStore $store, string $name): string
    {
        for ($i = 0; $i < 20; $i++) {
            $code = PartnerCode::suggest($name);
            if ($store->partnerByCode($code) === null) {
                return $code;
            }
        }
        throw new LedgerException('Kein freier Code gefunden, bitte selbst einen angeben.', 409);
    }

    private static function text(mixed $value, int $limit): ?string
    {
        $v = trim((string) ($value ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $limit);
    }
}
