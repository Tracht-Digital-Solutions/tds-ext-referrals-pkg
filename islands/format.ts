/** Shared formatting and wire types for the Empfehlungen islands. */

export type CommissionStatus = "claimed" | "pending" | "approved" | "paid" | "rejected" | "reversed";

export interface Commission {
  id: number;
  partner_id: number | null;
  description: string | null;
  via: "link" | "code" | "named" | "manual";
  status: CommissionStatus;
  net_cents: number;
  rate_percent: number | null;
  commission_cents: number | null;
  source_paid: boolean;
  approve_after: string | null;
  payout_id: number | null;
  created_at: string;
  // Admin only:
  partner_name?: string | null;
  customer_email?: string | null;
  note?: string | null;
  source?: string;
  source_id?: string;
}

export interface Partner {
  id: number;
  name: string;
  public_name: string;
  code: string;
  link: string;
  rate_percent: number;
  own_rate: boolean;
  status: "active" | "paused";
  payout_name: string | null;
  tax_status: TaxStatus | null;
  vat_id: string | null;
  iban_masked: string | null;
  // Admin only:
  email?: string | null;
  user_id?: number | null;
  note?: string | null;
}

export type TaxStatus = "private" | "small_business" | "vat";

export interface Payout {
  id: number;
  partner_id: number;
  total_cents: number;
  reference: string | null;
  paid_at: string;
  partner_name?: string | null;
}

export interface ProgrammeSettings {
  default_rate_percent: number;
  hold_days: number;
  link_days: number;
  terms_url: string;
}

export const euros = (cents: number | null) =>
  cents === null ? "—" : new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(cents / 100);

/** `2026-10-07…` → `07.10.2026`, without a time-zone round trip. */
export const day = (iso: string | null | undefined) => (iso ? iso.slice(0, 10).split("-").reverse().join(".") : "—");

export const percent = (value: number | null) =>
  value === null ? "—" : `${new Intl.NumberFormat("de-DE", { maximumFractionDigits: 2 }).format(value)} %`;

/** Euro text as typed ("1.250,50", "1250.5") → cents, or null. */
export const parseEuros = (raw: string): number | null => {
  const s = raw.trim().replace(/\s|€/g, "");
  if (s === "") return null;
  const normalized = s.includes(",") ? s.replace(/\./g, "").replace(",", ".") : s;
  const n = Number(normalized);
  return Number.isFinite(n) && n > 0 ? Math.round(n * 100) : null;
};

/** Explicit map — never interpolate a chip class (Tailwind cannot extract it). */
export const STATUS: Record<CommissionStatus, { label: string; chip: string }> = {
  claimed: { label: "Genannt", chip: "chip chip--info" },
  pending: { label: "Wartet", chip: "chip chip--warning" },
  approved: { label: "Fällig", chip: "chip chip--success" },
  paid: { label: "Ausgezahlt", chip: "chip chip--neutral" },
  rejected: { label: "Abgelehnt", chip: "chip chip--danger" },
  reversed: { label: "Storniert", chip: "chip chip--danger" },
};

export const statusOf = (s: string) => STATUS[s as CommissionStatus] ?? { label: s, chip: "chip chip--neutral" };

export const VIA: Record<string, string> = {
  link: "Empfehlungslink",
  code: "Code an der Kasse",
  named: "Vom Kunden genannt",
  manual: "Von Hand erfasst",
};

export const TAX: Record<TaxStatus, string> = {
  private: "Privatperson",
  small_business: "Kleinunternehmer (§ 19 UStG)",
  vat: "Umsatzsteuerpflichtig",
};

/** The wait line a partner or operator reads next to a pending commission. */
export const waitLabel = (c: Commission) => {
  if (c.status !== "pending") return null;
  if (!c.source_paid) return "Wartet auf Zahlung des Auftrags";
  return c.approve_after ? `Fällig ab ${day(c.approve_after)}` : "Wartet";
};

/** The API's `{error}` text, or the status. */
export async function errorText(res: Response, fallback: string): Promise<string> {
  try {
    const body = (await res.json()) as { error?: string };
    if (body.error) return `${body.error} (HTTP ${res.status})`;
  } catch {
    // not JSON
  }
  return `${fallback} (HTTP ${res.status}).`;
}
