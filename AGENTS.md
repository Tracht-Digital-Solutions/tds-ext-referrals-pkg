# AGENTS.md — tds-ext-referrals-pkg

**Empfehlungsprogramm.** People who bring in a sale without being the buyer earn a
commission on its net value. The admin panel manages partners, commissions and payouts. In
the customer portal, a partner sees their link, their referrals and what is due. It
contributes a PHP `Module` to `tds-core-frontend-api` and one route to both panels.

Read `tds-frontend-contract-pkg/AGENTS.md` first (capabilities `Commerce\SaleListener`,
`Commerce\ReferralResolver`, contract ≥ 1.15).

## Commands

```bash
npm install --no-package-lock   # never npm ci
npm run type-check
npm run lint:primitives
npm run test:run                # vitest
npm run build                   # tsup
composer install && composer test   # phpunit (DB-free)
```

## Hard rules

- Money rules live in `CommissionLedger` only; routes call it, never write commission rows themselves.
- Listener methods never throw and stay idempotent per `(source, source_id)`.
- Amounts are net cents. A rate is frozen when a partner is attached.
- The IBAN never goes into a table or a log; it is a settings-store secret.
- The portal never returns buyer data or operator notes.
- Bind container entries unconditionally; PHP-DI's `has()` is always true for concrete classes.
- Every route change updates `php/docs/api.php`. Migrations stay in the `20261011*` band.
- No CSS; shared `tds-shared` classes only. `apiFetch`, never a relative `fetch`.
- Versions move via the release workflow only; stay in the `0.1.x` line the products pin.

## Topic files

| File | Read before |
|---|---|
| [docs/agents/architecture.md](docs/agents/architecture.md) | Changing states, rates, sources, the portal or the data model |
| [docs/agents/backend.md](docs/agents/backend.md) | Touching PHP, migrations or `php/docs/api.php` |
| [docs/agents/frontend-conventions.md](docs/agents/frontend-conventions.md) | Touching any island or page markup |
| [docs/agents/testing.md](docs/agents/testing.md) | Writing or changing tests |
| [docs/agents/release.md](docs/agents/release.md) | Releasing or changing CI |

Workspace rules: `../CLAUDE.md`. Cross-repo state: `../MIGRATION-STATUS.md`.
