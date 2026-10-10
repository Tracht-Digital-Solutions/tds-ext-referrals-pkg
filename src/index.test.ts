import { describe, expect, it } from "vitest";
import { composeExtensions } from "@tracht-digital-solutions/tds-frontend-contract";
import manifest from "./index";

/**
 * The manifest is this package's public contract: a product folds it into one
 * build via `composeExtensions`, which hard-errors on any collision.
 */
describe("the referrals manifest", () => {
  it("declares the id the backend module uses", () => {
    expect(manifest.id).toBe("referrals");
    expect(manifest.version).toMatch(/^\d+\.\d+\.\d+$/);
  });

  it("declares the permissions the PHP module checks", () => {
    // Twin of ReferralsModule::permissions(); a mismatch means a grantable
    // permission nothing checks, or a check nobody can grant.
    expect(manifest.permissions?.map((p) => p.id)).toEqual(["referrals:read", "referrals:manage", "referrals:partner"]);
  });

  it("gates nav and route with the portal key, so customers see it only as partners", () => {
    for (const n of manifest.nav ?? []) expect(n.permission).toBe("referrals:partner");
    for (const r of manifest.routes ?? []) expect(r.permission).toBe("referrals:partner");
    expect(manifest.nav?.[0]?.href).toBe(manifest.routes?.[0]?.pattern);
  });

  it("points every specifier at this package", () => {
    const specs = [...(manifest.routes ?? []).map((r) => r.entrypoint), ...(manifest.settings ?? []).map((s) => s.island)];
    for (const s of specs) expect(s).toMatch(/^@tracht-digital-solutions\/tds-ext-referrals\//);
  });

  it("namespaces its i18n keys", () => {
    for (const lang of Object.values(manifest.i18n ?? {})) {
      for (const key of Object.keys(lang)) expect(key).toMatch(/^referrals\./);
    }
  });

  it("composes standalone", () => {
    const composed = composeExtensions([manifest]);
    expect(composed.routes.map((r) => r.pattern)).toContain("/empfehlungen");
    expect(composed.settings.map((s) => s.id)).toContain("referrals");
  });
});
