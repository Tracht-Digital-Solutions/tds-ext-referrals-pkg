# Architecture

## Shape

| Path | Role |
|---|---|
| `src/index.ts` | Manifest: permissions, one nav entry, one route `/empfehlungen`, settings, i18n |
| `pages/Index.astro` | The route. Picks `ReferralsAdmin` (admin panel) or `ReferralsPortal` (customer portal) by `PUBLIC_FRONTEND_TARGET` |
| `islands/` | `ReferralsAdmin`, `ReferralsPortal`, `ReferralsSettings`, shared `format.ts` |
| `php/src/ReferralsModule.php` | Routes, permissions, settings, setup item; implements `SaleListener` and `ReferralResolver` |
| `php/src/Service/CommissionLedger.php` | Every state change of a commission |
| `php/src/Domain/ReferralStore.php` | Persistence interface; `PdoReferralStore` in production |
| `php/db/migrations/20261011*` | Migration band of this module |

## How sales arrive

Sellers never call this module directly. They call the contract's `Commerce\SaleEvents`
(bound by the core API), which fans out to every `SaleListener`:

- **Shop:** `paid()` on every PAID webhook delivery, with the code (`?ref=` link or typed)
  and the free-text "Wer hat dich empfohlen?". `reversed()` on refund.
- **Billing:** `paid()` when an invoice is paid. No code: it only matures a commission the
  operator recorded by hand against that invoice.
- **Manual:** jobs outside both are recorded in the panel and confirmed paid by hand.

`resolveReferral()` lets the shop show "Empfohlen von …" and drop unknown codes. A paused
partner's code resolves to nothing.

## Commission states

`claimed` (named, no partner) → `pending` (waits for payment + hold period) → `approved`
(due) → `paid` (in a payout). Side exits: `rejected`, `reversed`.

- The rate is frozen when a partner is attached. Precedence per line: product rate →
  partner rate → default.
- Maturing runs on reads of the overview and the portal, no cron (the host has none).
- A refund after payout writes a negative `clawback` row that the next payout nets off.
  A payout with a total ≤ 0 is refused.
- The buyer's own purchase (same email as the partner) is recorded as `rejected`.

## Data boundaries

- The **IBAN** lives in the core settings store, namespace `referrals_payout`, key
  `iban_<partnerId>`, encrypted with `SETTINGS_ENCRYPTION_KEY`. Only the payout statement
  returns it in full.
- The **portal** never sees the buyer's email or the operator's note. Shop sales read as
  "Shop-Bestellung".
- A partner is a **person**, bound to the signed-in user, not to the active company. An
  operator-created partner links itself on the first portal visit by sign-in address.

## Nav gating

Nav and route carry `referrals:partner`. Admins bypass it, so the admin panel shows the
entry. A customer sees it only with that key (system group "Vermittler" in tds-auth-api).
