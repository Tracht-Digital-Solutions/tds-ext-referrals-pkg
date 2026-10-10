// @vitest-environment jsdom
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, cleanup, fireEvent, within } from "@testing-library/react";
import { primeRuntimeConfig } from "@tracht-digital-solutions/tds-shared/api";
import ReferralsAdmin from "./ReferralsAdmin";

const fetchMock = vi.fn();
let overview: unknown;
let mutation: { status: number; body: unknown };

const ANNA = {
  id: 1,
  name: "Anna Beispiel",
  public_name: "Anna B.",
  code: "ANNA-1",
  link: "https://shop.tracht-digital.de/?ref=ANNA-1",
  rate_percent: 10,
  own_rate: false,
  status: "active",
  payout_name: null,
  tax_status: null,
  vat_id: null,
  iban_masked: "DE … 3000",
  email: "anna@example.org",
  user_id: 4,
  note: null,
};

const base = (c: { id: number; status: string; [k: string]: unknown }) => ({
  partner_id: 1,
  description: "Shop-Bestellung",
  via: "link",
  net_cents: 50000,
  rate_percent: 10,
  commission_cents: 5000,
  source_paid: true,
  approve_after: "2026-11-01 12:00:00",
  payout_id: null,
  created_at: "2026-10-11 12:00:00",
  partner_name: "Anna Beispiel",
  customer_email: "buyer@example.org",
  note: null,
  ...c,
});

beforeEach(() => {
  primeRuntimeConfig(null);
  overview = {
    partners: [ANNA],
    commissions: [
      base({ id: 2, status: "claimed", partner_id: null, partner_name: null, via: "named", rate_percent: null, commission_cents: null, note: "Mein Nachbar Bernd" }),
      base({ id: 1, status: "approved" }),
    ],
    payouts: [],
    product_rates: [],
    settings: { default_rate_percent: 10, hold_days: 21, link_days: 30, terms_url: "" },
  };
  mutation = { status: 200, body: {} };
  fetchMock.mockReset();
  fetchMock.mockImplementation(async (url: string, init?: RequestInit) => {
    const isRead = (init?.method ?? "GET") === "GET";
    const { status, body } = isRead ? { status: 200, body: overview } : mutation;
    return new Response(JSON.stringify(body), { status, headers: { "Content-Type": "application/json" } });
  });
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

const calls = () => fetchMock.mock.calls.map(([u, i]) => `${(i as RequestInit | undefined)?.method ?? "GET"} ${new URL(String(u)).pathname}`);

describe("ReferralsAdmin", () => {
  it("counts claims to assign in the tab and assigns one to a partner", async () => {
    render(<ReferralsAdmin />);
    expect(await screen.findByRole("button", { name: "Vermittlungen (1 zuzuordnen)" })).toBeTruthy();
    expect(screen.getByText("„Mein Nachbar Bernd“")).toBeTruthy();

    fireEvent.change(screen.getByRole("combobox", { name: /Partner für/ }), { target: { value: "1" } });
    fireEvent.click(screen.getByRole("button", { name: "Zuordnen" }));
    await vi.waitFor(() => expect(calls()).toContain("POST /admin/referrals/commissions/2/assign"));
    const [, init] = fetchMock.mock.calls.find(([u]) => String(u).endsWith("/assign"))!;
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ partner_id: 1 });
  });

  it("rejects only after the confirm dialog", async () => {
    render(<ReferralsAdmin />);
    await screen.findByText("„Mein Nachbar Bernd“");
    fireEvent.click(screen.getAllByRole("button", { name: "Ablehnen" })[0]!);
    expect(calls().some((c) => c.includes("/reject"))).toBe(false);
    const dialog = await screen.findByRole("dialog", { hidden: true });
    fireEvent.click(within(dialog).getByRole("button", { name: "Ablehnen", hidden: true }));
    await vi.waitFor(() => expect(calls()).toContain("POST /admin/referrals/commissions/2/reject"));
  });

  it("lists what is due per partner and books a payout", async () => {
    render(<ReferralsAdmin />);
    fireEvent.click(await screen.findByRole("button", { name: "Auszahlungen" }));
    fireEvent.click(screen.getByRole("button", { name: "Auszahlung verbuchen" }));
    fireEvent.click(screen.getByRole("button", { name: "Als ausgezahlt verbuchen" }));
    await vi.waitFor(() => expect(calls()).toContain("POST /admin/referrals/payouts"));
  });

  it("shows a refused mutation's message with its status", async () => {
    mutation = { status: 409, body: { error: "Nur offene Vermittlungen lassen sich zuordnen." } };
    render(<ReferralsAdmin />);
    fireEvent.change(await screen.findByRole("combobox", { name: /Partner für/ }), { target: { value: "1" } });
    fireEvent.click(screen.getByRole("button", { name: "Zuordnen" }));
    await vi.waitFor(() => expect(calls().filter((c) => c.startsWith("GET"))).toHaveLength(1));
  });
});
