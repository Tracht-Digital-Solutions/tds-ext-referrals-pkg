<?php
declare(strict_types=1);

namespace Tds\Ext\Referrals\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\Ext\Referrals\ReferralsModule;
use Tds\Ext\Referrals\Tests\Support\MemoryReferralStore;
use Tds\Frontend\Contract\Commerce\SaleEvent;
use Tds\Frontend\Contract\Commerce\SaleEvents;
use Tds\Frontend\Contract\ModuleRegistry;
use Tds\Frontend\Contract\SettingsStore;
use Tds\Frontend\Contract\UserContext;

final class ReferralsModuleTest extends TestCase
{
    private MemoryReferralStore $store;
    private MemorySettings $settings;
    private ReferralsModule $module;

    protected function setUp(): void
    {
        $this->store = new MemoryReferralStore();
        $this->settings = new MemorySettings();
        $this->module = new ReferralsModule($this->store, static fn (): string => '2026-10-11 12:00:00');
    }

    private function app(UserContext $user): App
    {
        $services = [UserContext::class => $user, SettingsStore::class => $this->settings];
        $container = new class ($services) implements ContainerInterface {
            /** @param array<string,object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new \RuntimeException("unbound: {$id}");
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };
        $app = AppFactory::create(null, $container);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        (new ModuleRegistry([$this->module]))->registerAll($app);
        return $app;
    }

    /** @param string[] $perms */
    private function user(?int $id, array $perms = [], ?string $email = null, bool $admin = false): UserContext
    {
        $user = $this->createMock(UserContext::class);
        $user->method('isAuthenticated')->willReturn($id !== null);
        $user->method('userId')->willReturn($id);
        $user->method('email')->willReturn($email);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('has')->willReturnCallback(static fn (string $p): bool => $admin || in_array($p, $perms, true));
        return $user;
    }

    /** @param array<string,mixed>|null $body @return array{0:int,1:array<string,mixed>} */
    private function call(UserContext $user, string $method, string $path, ?array $body = null): array
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== null) {
            $request = $request->withHeader('Content-Type', 'application/json');
            $request->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR));
        }
        $response = $this->app($user)->handle($request);
        return [$response->getStatusCode(), self::body($response)];
    }

    /** @return array<string,mixed> */
    private static function body(ResponseInterface $response): array
    {
        return (array) json_decode((string) $response->getBody(), true);
    }

    public function testAdminRoutesCheckTheirPermissions(): void
    {
        self::assertSame(401, $this->call($this->user(null), 'GET', '/admin/referrals/overview')[0]);
        self::assertSame(403, $this->call($this->user(5, ['referrals:partner']), 'GET', '/admin/referrals/overview')[0]);
        self::assertSame(200, $this->call($this->user(5, ['referrals:read']), 'GET', '/admin/referrals/overview')[0]);
        self::assertSame(403, $this->call($this->user(5, ['referrals:read']), 'POST', '/admin/referrals/partners', ['name' => 'X'])[0]);
    }

    public function testCreatePartnerSuggestsACodeAndAShortPublicName(): void
    {
        [$status, $partner] = $this->call($this->user(1, [], null, true), 'POST', '/admin/referrals/partners', [
            'name' => 'Anna Beispiel',
            'email' => 'Anna@Example.org',
        ]);
        self::assertSame(201, $status);
        self::assertMatchesRegularExpression('/^ANNA-\d{4}$/', $partner['code']);
        self::assertSame('Anna B.', $partner['public_name']);
        self::assertSame('anna@example.org', $partner['email']);
        self::assertSame(10, $partner['rate_percent']);
        self::assertSame('https://shop.tracht-digital.de/?ref=' . $partner['code'], $partner['link']);

        [$dup] = $this->call($this->user(1, [], null, true), 'POST', '/admin/referrals/partners', ['name' => 'B', 'code' => $partner['code']]);
        self::assertSame(409, $dup);
    }

    public function testPortalLinksThePartnerBySignInAddressAndHidesTheBuyer(): void
    {
        $this->store->insertPartner(['name' => 'Anna Beispiel', 'public_name' => 'Anna B.', 'code' => 'ANNA-1', 'email' => 'anna@example.org']);
        $this->module->onSalePaid(new SaleEvent('shop', '7', 50000, 'buyer@example.org', [], 'ANNA-1', 'code'));

        $stranger = $this->user(9, ['referrals:partner'], 'other@example.org');
        self::assertSame(404, $this->call($stranger, 'GET', '/referrals/me')[0]);

        $anna = $this->user(4, ['referrals:partner'], 'ANNA@example.org');
        [$status, $me] = $this->call($anna, 'GET', '/referrals/me');
        self::assertSame(200, $status);
        self::assertSame(4, $this->store->partner(1)['user_id'], 'linked on first visit');
        self::assertSame('ANNA-1', $me['partner']['code']);
        self::assertArrayNotHasKey('email', $me['partner']);
        self::assertSame(5000, $me['totals']['pending']);
        self::assertSame('Shop-Bestellung', $me['commissions'][0]['description']);
        self::assertStringNotContainsString('buyer@example.org', json_encode($me, JSON_THROW_ON_ERROR));
    }

    public function testPortalPayoutDetailsValidateAndEncryptTheIban(): void
    {
        $this->store->insertPartner(['name' => 'Anna', 'public_name' => 'Anna', 'code' => 'ANNA-1', 'email' => 'anna@example.org', 'user_id' => 4]);
        $anna = $this->user(4, ['referrals:partner'], 'anna@example.org');

        [$bad] = $this->call($anna, 'PUT', '/referrals/me/payout-details', ['iban' => 'DE00 1234']);
        self::assertSame(422, $bad);
        [$vatMissing] = $this->call($anna, 'PUT', '/referrals/me/payout-details', ['tax_status' => 'vat']);
        self::assertSame(422, $vatMissing);

        [$ok, $partner] = $this->call($anna, 'PUT', '/referrals/me/payout-details', [
            'iban' => 'DE89 3704 0044 0532 0130 00',
            'payout_name' => 'Anna Beispiel',
            'tax_status' => 'small_business',
        ]);
        self::assertSame(200, $ok);
        self::assertSame('DE … 3000', $partner['iban_masked']);
        self::assertSame(['referrals_payout', 'iban_1', true], $this->settings->lastSecret);
        self::assertNull($this->store->partner(1)['iban'] ?? null, 'the IBAN never lands in the partner row');
    }

    public function testPayoutStatementAddsVatForVatRegisteredPartners(): void
    {
        $this->store->insertPartner(['name' => 'Firma', 'public_name' => 'Firma', 'code' => 'FIRMA-1', 'tax_status' => 'vat', 'vat_id' => 'DE123456789']);
        $admin = $this->user(1, [], null, true);
        [$created] = $this->call($admin, 'POST', '/admin/referrals/commissions', [
            'partner_id' => 1, 'net_cents' => 100000, 'description' => 'Website', 'paid' => true,
        ]);
        self::assertSame(201, $created);
        self::assertSame(200, $this->call($admin, 'POST', '/admin/referrals/commissions/1/approve')[0]);
        [$paid, $payout] = $this->call($admin, 'POST', '/admin/referrals/payouts', ['partner_id' => 1, 'reference' => 'PROV-2026-10']);
        self::assertSame(201, $paid);

        [, $statement] = $this->call($admin, 'GET', '/admin/referrals/payouts/' . $payout['id']);
        self::assertSame(10000, $statement['payout']['total_cents']);
        self::assertSame(1900, $statement['payout']['vat_cents']);
        self::assertSame(11900, $statement['payout']['gross_cents']);
        self::assertCount(1, $statement['lines']);
    }

    public function testLedgerRefusalsAreMessagesNotErrors(): void
    {
        [$status, $body] = $this->call($this->user(1, [], null, true), 'POST', '/admin/referrals/commissions/99/approve');
        self::assertSame(404, $status);
        self::assertSame('Vermittlung nicht gefunden.', $body['error']);
    }

    public function testResolverAnswersActiveCodesOnly(): void
    {
        $this->store->insertPartner(['name' => 'Anna Beispiel', 'public_name' => 'Anna B.', 'code' => 'ANNA-1']);
        $this->store->insertPartner(['name' => 'Paul', 'public_name' => 'Paul', 'code' => 'PAUL-1', 'status' => 'paused']);
        $events = new SaleEvents([], [$this->module]);

        self::assertSame('Anna B.', $events->resolveReferral('anna-1')?->displayName);
        self::assertSame('ANNA-1', $events->resolveReferral('anna-1')?->code);
        self::assertNull($events->resolveReferral('PAUL-1'));
        self::assertNull($events->resolveReferral('<script>'));
    }

    public function testAListenerWithoutADatabaseDoesNothing(): void
    {
        // Not registered: no container, no store — must be silent, never throw.
        (new ReferralsModule())->onSalePaid(new SaleEvent('shop', '1', 100, null, [], 'ANNA-1'));
        self::assertNull((new ReferralsModule())->resolveReferral('ANNA-1'));
        $this->addToAssertionCount(1);
    }

    public function testSetupItemReportsMissingTerms(): void
    {
        $this->app($this->user(1, [], null, true));
        self::assertSame('missing', $this->module->setupItems($this->user(1, [], null, true))[0]['state']);
        $this->settings->values['referrals']['terms_url'] = 'https://example.org/partner';
        self::assertSame('ok', $this->module->setupItems($this->user(1, [], null, true))[0]['state']);
    }
}

final class MemorySettings implements SettingsStore
{
    /** @var array<string,array<string,string>> */
    public array $values = [];
    /** @var array{0:string,1:string,2:bool}|null */
    public ?array $lastSecret = null;

    public function get(string $namespace, string $key, ?string $default = null): ?string
    {
        return $this->values[$namespace][$key] ?? $default;
    }

    public function getSecret(string $namespace, string $key): ?string
    {
        return $this->values[$namespace][$key] ?? null;
    }

    public function set(string $namespace, string $key, string $value, bool $secret): void
    {
        $this->values[$namespace][$key] = $value;
        if ($secret) {
            $this->lastSecret = [$namespace, $key, $secret];
        }
    }

    public function delete(string $namespace, string $key): void
    {
        unset($this->values[$namespace][$key]);
    }

    public function allMasked(string $namespace): array
    {
        return [];
    }
}
