// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, cleanup, fireEvent } from "@testing-library/react";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import ReferralsPortal from "./ReferralsPortal";

const fetchMock = vi.fn();
let reply: { status: number; body: unknown } | "offline";

const ME = {
  partner: {
    id: 1,
    name: "Anna Beispiel",
    public_name: "Anna B.",
    code: "ANNA-4821",
    link: "https://shop.tracht-digital.de/?ref=ANNA-4821",
    rate_percent: 10,
    own_rate: false,
    status: "active",
    payout_name: null,
    tax_status: null,
    vat_id: null,
    iban_masked: null,
  },
  commissions: [
    {
      id: 3,
      partner_id: 1,
      description: "Shop-Bestellung",
      via: "link",
      status: "pending",
      net_cents: 50000,
      rate_percent: 10,
      commission_cents: 5000,
      source_paid: true,
      approve_after: "2026-11-01 12:00:00",
      payout_id: null,
      created_at: "2026-10-11 12:00:00",
    },
  ],
  totals: { pending: 5000, approved: 0, paid: 0 },
  payouts: [],
  settings: { default_rate_percent: 10, hold_days: 21, link_days: 30, terms_url: "https://example.org/partner" },
};

beforeEach(() => {
  primeRuntimeConfig(null);
  reply = { status: 200, body: ME };
  fetchMock.mockReset();
  fetchMock.mockImplementation(async () => {
    if (reply === "offline") throw new TypeError("Failed to fetch");
    const { status, body } = reply;
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  });
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe("ReferralsPortal", () => {
  it("reads the signed-in partner's own account through the API host", async () => {
    render(<ReferralsPortal />);
    await screen.findByDisplayValue(ME.partner.link);
    const url = new URL(String(fetchMock.mock.calls[0]![0]), "http://relative.invalid");
    expect(url.pathname).toBe("/referrals/me");
    // apiFetch resolves an absolute API base; a relative fetch would hit the
    // product's SPA fallback and render an empty, calm page.
    expect(String(fetchMock.mock.calls[0]![0])).toMatch(/^https?:\/\//);
  });

  it("shows the rate, the wait and the totals", async () => {
    render(<ReferralsPortal />);
    expect(await screen.findByText("10 %")).toBeTruthy();
    expect(screen.getByText(/Fällig ab 01\.11\.2026/)).toBeTruthy();
    expect(screen.getAllByText("50,00 €").length).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "Partnerbedingungen" }).getAttribute("href")).toBe("https://example.org/partner");
  });

  it("explains a missing partner account instead of an empty page", async () => {
    reply = { status: 404, body: { error: "x" } };
    render(<ReferralsPortal />);
    expect((await screen.findByRole("alert")).textContent).toMatch(/keinem Partnerkonto zugeordnet/);
  });

  it("survives an unreachable API", async () => {
    reply = "offline";
    render(<ReferralsPortal />);
    expect((await screen.findByRole("alert")).textContent).toMatch(/Verbindung ist unterbrochen/);
  });

  it("shows the API's refusal for bad bank data in the form", async () => {
    render(<ReferralsPortal />);
    await screen.findByDisplayValue(ME.partner.link);
    reply = { status: 422, body: { error: "Die IBAN ist ungültig." } };
    fireEvent.click(screen.getByRole("button", { name: "Speichern" }));
    expect((await screen.findByRole("alert")).textContent).toMatch(/IBAN ist ungültig.*422/);
    const [, init] = fetchMock.mock.calls[1]!;
    expect((init as RequestInit).method).toBe("PUT");
  });
});
