import { defineExtension } from "@tracht-digital-solutions/tds-frontend-contract";

/**
 * Empfehlungsprogramm: partners who bring in a sale earn a commission on its
 * net value. One route, two products — the admin panel manages partners,
 * commissions and payouts; the customer portal shows a partner their link,
 * their referrals and what is due (`pages/Index.astro` picks the island).
 *
 * The nav and the route carry the PORTAL key `referrals:partner`: admins
 * bypass every permission, so the admin panel still sees the entry, and a
 * customer sees it only when the operator made them a partner.
 */
export default defineExtension({
  id: "referrals",
  name: "Empfehlungsprogramm",
  version: "0.1.0",
  permissions: [
    { id: "referrals:read", label: "Provisionen ansehen", group: "referrals" },
    { id: "referrals:manage", label: "Partner und Provisionen verwalten", group: "referrals" },
    { id: "referrals:partner", label: "Eigenes Partnerkonto (Portal: Weiterempfehlen)", group: "referrals" },
  ],
  nav: [
    {
      id: "referrals",
      label: "Empfehlungen",
      href: "/empfehlungen",
      icon: "users",
      group: "abrechnung",
      order: 40,
      permission: "referrals:partner",
    },
  ],
  settings: [
    {
      id: "referrals",
      label: "Empfehlungsprogramm",
      island: "@tracht-digital-solutions/tds-ext-referrals/islands/Settings.astro",
      order: 60,
    },
  ],
  routes: [
    {
      pattern: "/empfehlungen",
      entrypoint: "@tracht-digital-solutions/tds-ext-referrals/pages/Index.astro",
      permission: "referrals:partner",
    },
  ],
  i18n: {
    de: { "referrals.title": "Empfehlungen" },
    en: { "referrals.title": "Referrals" },
  },
});
