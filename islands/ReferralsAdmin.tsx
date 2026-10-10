import { useEffect, useMemo, useState } from "react";
import { ConfirmDialog, Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";
import {
  TAX,
  VIA,
  day,
  errorText,
  euros,
  parseEuros,
  percent,
  statusOf,
  waitLabel,
  type Commission,
  type Partner,
  type Payout,
  type ProgrammeSettings,
  type TaxStatus,
} from "./format";

interface Overview {
  partners: Partner[];
  commissions: Commission[];
  payouts: Payout[];
  product_rates: { product_id: string; rate_percent: number }[];
  settings: ProgrammeSettings;
}

interface Statement {
  payout: Payout & { vat_cents: number; gross_cents: number };
  partner: Partner & { iban: string | null };
  lines: Commission[];
}

type Tab = "commissions" | "partners" | "payouts";

const json = (body: unknown): RequestInit => ({
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify(body),
});

/**
 * The operator's side of the Empfehlungsprogramm: who the partners are, which
 * sales they brought in, and settling what is due. One `GET` loads it all; every
 * mutation reloads, so the list never shows a state the server does not have.
 */
export default function ReferralsAdmin() {
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [tab, setTab] = useState<Tab>("commissions");

  const load = async () => {
    const res = await apiFetch("/admin/referrals/overview").catch(() => null);
    if (res === null) {
      setError("Provisionen konnten nicht geladen werden — die API ist nicht erreichbar.");
    } else if (!res.ok) {
      setError(await errorText(res, "Provisionen konnten nicht geladen werden"));
    } else {
      setError(null);
      setData((await res.json()) as Overview);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  /** Run a mutation, toast its outcome, reload. Returns whether it worked. */
  const mutate = async (path: string, init: RequestInit, success: string): Promise<boolean> => {
    const res = await apiFetch(path, init).catch(() => null);
    if (res === null) {
      toast.danger("Fehlgeschlagen — die API ist nicht erreichbar.");
      return false;
    }
    if (!res.ok) {
      toast.danger(await errorText(res, "Fehlgeschlagen"));
      return false;
    }
    toast.success(success);
    await load();
    return true;
  };

  if (error) return <p className="tds-alert tds-alert--danger" role="alert">{error}</p>;
  if (data === null) {
    return (
      <p className="tds-empty" aria-busy="true">
        <Spinner /> Wird geladen …
      </p>
    );
  }

  const claimed = data.commissions.filter((c) => c.status === "claimed").length;
  const tabs: { id: Tab; label: string }[] = [
    { id: "commissions", label: claimed > 0 ? `Vermittlungen (${claimed} zuzuordnen)` : "Vermittlungen" },
    { id: "partners", label: `Partner (${data.partners.length})` },
    { id: "payouts", label: "Auszahlungen" },
  ];

  return (
    <div className="tds-stack tds-stack--loose">
      <div className="tds-toolbar" role="group" aria-label="Bereich">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            className={tab === t.id ? "btn btn-primary" : "btn btn-ghost"}
            aria-pressed={tab === t.id}
            onClick={() => setTab(t.id)}
          >
            {t.label}
          </button>
        ))}
      </div>
      {tab === "commissions" ? <Commissions data={data} mutate={mutate} /> : null}
      {tab === "partners" ? <Partners data={data} mutate={mutate} /> : null}
      {tab === "payouts" ? <Payouts data={data} mutate={mutate} /> : null}
    </div>
  );
}

type Mutate = (path: string, init: RequestInit, success: string) => Promise<boolean>;

// --- Vermittlungen ------------------------------------------------------------

function Commissions({ data, mutate }: { data: Overview; mutate: Mutate }) {
  const [rejecting, setRejecting] = useState<Commission | null>(null);
  const [busy, setBusy] = useState(false);
  const active = data.partners.filter((p) => p.status === "active");

  const exportCsv = () => {
    const rows = [
      ["Datum", "Auftrag", "Partner", "Weg", "Status", "Netto", "Satz", "Provision", "Kunde", "Notiz"],
      ...data.commissions.map((c) => [
        day(c.created_at),
        c.description ?? "",
        c.partner_name ?? "",
        VIA[c.via] ?? c.via,
        statusOf(c.status).label,
        (c.net_cents / 100).toFixed(2).replace(".", ","),
        c.rate_percent === null ? "" : String(c.rate_percent).replace(".", ","),
        c.commission_cents === null ? "" : (c.commission_cents / 100).toFixed(2).replace(".", ","),
        c.customer_email ?? "",
        (c.note ?? "").replace(/\s+/g, " "),
      ]),
    ];
    const csv = rows.map((r) => r.map((v) => `"${String(v).replace(/"/g, '""')}"`).join(";")).join("\r\n");
    const url = URL.createObjectURL(new Blob(["﻿" + csv], { type: "text/csv;charset=utf-8" }));
    const a = document.createElement("a");
    a.href = url;
    a.download = `provisionen-${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="tds-stack tds-stack--loose">
      <ManualForm partners={active} mutate={mutate} />

      <section className="tds-stack" aria-labelledby="ref-commissions">
        <div className="tds-row tds-row--between">
          <h2 id="ref-commissions" className="text-lg font-semibold">Vermittlungen</h2>
          {data.commissions.length > 0 ? (
            <button type="button" className="btn btn-ghost" onClick={exportCsv}>
              Als CSV exportieren
            </button>
          ) : null}
        </div>
        {data.commissions.length === 0 ? (
          <p className="tds-empty">
            Noch keine Vermittlungen. Sie entstehen, wenn jemand mit Code oder Link im Shop kauft, an der Kasse einen
            Namen nennt oder Sie einen Auftrag oben erfassen.
          </p>
        ) : (
          <ul className="tds-list" aria-label="Vermittlungen">
            {data.commissions.map((c) => (
              <CommissionRow
                key={c.id}
                c={c}
                partners={active}
                mutate={mutate}
                onReject={() => setRejecting(c)}
              />
            ))}
          </ul>
        )}
      </section>

      <ConfirmDialog
        open={rejecting !== null}
        title="Vermittlung ablehnen?"
        message={rejecting ? `${rejecting.description ?? "Auftrag"}: Es wird keine Provision fällig.` : null}
        confirmLabel="Ablehnen"
        busy={busy}
        onCancel={() => setRejecting(null)}
        onConfirm={async () => {
          if (!rejecting) return;
          setBusy(true);
          await mutate(`/admin/referrals/commissions/${rejecting.id}/reject`, { method: "POST", ...json({}) }, "Abgelehnt.");
          setBusy(false);
          setRejecting(null);
        }}
      />
    </div>
  );
}

function CommissionRow({
  c,
  partners,
  mutate,
  onReject,
}: {
  c: Commission;
  partners: Partner[];
  mutate: Mutate;
  onReject: () => void;
}) {
  const [assignTo, setAssignTo] = useState("");
  const s = statusOf(c.status);
  const post = (action: string, body: unknown, ok: string) =>
    mutate(`/admin/referrals/commissions/${c.id}/${action}`, { method: "POST", ...json(body) }, ok);

  return (
    <li className="tds-list__row">
      <div className="min-w-0 tds-stack tds-stack--tight">
        <p className="font-semibold">{c.description ?? "Auftrag"}</p>
        <p className="marginalia">
          {day(c.created_at)} · {VIA[c.via] ?? c.via} · {c.partner_name ?? "kein Partner"}
          {c.customer_email ? ` · ${c.customer_email}` : ""}
        </p>
        <p className="marginalia tabular-nums">
          Netto {euros(c.net_cents)} · {percent(c.rate_percent)} · Provision {euros(c.commission_cents)}
          {waitLabel(c) ? ` · ${waitLabel(c)}` : ""}
        </p>
        {c.note ? <p className="marginalia">„{c.note}“</p> : null}
      </div>
      <div className="tds-toolbar">
        <span className={s.chip}>{s.label}</span>
        {c.status === "claimed" || c.status === "rejected" ? (
          <>
            <select
              className="field-boxed"
              aria-label={`Partner für ${c.description ?? "Auftrag"}`}
              value={assignTo}
              onChange={(e) => setAssignTo(e.target.value)}
            >
              <option value="">Partner wählen …</option>
              {partners.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} ({p.code})
                </option>
              ))}
            </select>
            <button
              type="button"
              className="btn btn-primary"
              disabled={assignTo === ""}
              onClick={() => void post("assign", { partner_id: Number(assignTo) }, "Zugeordnet.")}
            >
              Zuordnen
            </button>
          </>
        ) : null}
        {c.status === "pending" && !c.source_paid ? (
          <button type="button" className="btn btn-ghost" onClick={() => void post("confirm-paid", {}, "Zahlung vermerkt.")}>
            Zahlung eingegangen
          </button>
        ) : null}
        {c.status === "pending" && c.source_paid ? (
          <button type="button" className="btn btn-ghost" onClick={() => void post("approve", {}, "Freigegeben.")}>
            Sofort freigeben
          </button>
        ) : null}
        {c.status === "claimed" || c.status === "pending" || c.status === "approved" ? (
          <button type="button" className="btn btn-danger" onClick={onReject}>
            Ablehnen
          </button>
        ) : null}
      </div>
    </li>
  );
}

function ManualForm({ partners, mutate }: { partners: Partner[]; mutate: Mutate }) {
  const [open, setOpen] = useState(false);
  const [partnerId, setPartnerId] = useState("");
  const [description, setDescription] = useState("");
  const [net, setNet] = useState("");
  const [email, setEmail] = useState("");
  const [invoiceId, setInvoiceId] = useState("");
  const [paid, setPaid] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  if (!open) {
    return (
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => setOpen(true)} disabled={partners.length === 0}>
          Vermittelten Auftrag erfassen
        </button>
        {partners.length === 0 ? <span className="marginalia">Legen Sie zuerst einen Partner an.</span> : null}
      </div>
    );
  }

  const submit = async () => {
    const cents = parseEuros(net);
    if (partnerId === "" || description.trim() === "" || cents === null) {
      setProblem("Bitte Partner, Beschreibung und einen Nettobetrag über 0 angeben.");
      return;
    }
    setProblem(null);
    setBusy(true);
    const ok = await mutate(
      "/admin/referrals/commissions",
      {
        method: "POST",
        ...json({
          partner_id: Number(partnerId),
          description,
          net_cents: cents,
          customer_email: email || null,
          invoice_id: invoiceId === "" ? null : Number(invoiceId),
          paid,
        }),
      },
      "Auftrag erfasst.",
    );
    setBusy(false);
    if (ok) {
      setOpen(false);
      setDescription("");
      setNet("");
      setEmail("");
      setInvoiceId("");
      setPaid(false);
    }
  };

  return (
    <section className="tds-card p-4 tds-stack" aria-labelledby="ref-manual">
      <h2 id="ref-manual" className="text-lg font-semibold">Vermittelten Auftrag erfassen</h2>
      <p className="marginalia">Für Aufträge außerhalb des Shops, etwa wenn ein Kunde am Telefon sagt, wer Sie empfohlen hat.</p>
      <label className="tds-field-row">
        <span>Partner</span>
        <select className="field-boxed" value={partnerId} onChange={(e) => setPartnerId(e.target.value)}>
          <option value="">Bitte wählen …</option>
          {partners.map((p) => (
            <option key={p.id} value={p.id}>
              {p.name} ({p.code}, {percent(p.rate_percent)})
            </option>
          ))}
        </select>
      </label>
      <label className="tds-field-row">
        <span>Auftrag</span>
        <input className="field-boxed" type="text" value={description} onChange={(e) => setDescription(e.target.value)} placeholder="z. B. Website Bäckerei Huber" />
      </label>
      <label className="tds-field-row">
        <span>Nettobetrag (€)</span>
        <input className="field-boxed" type="text" inputMode="decimal" value={net} onChange={(e) => setNet(e.target.value)} placeholder="1.200,00" />
      </label>
      <label className="tds-field-row">
        <span>E-Mail des Kunden (optional)</span>
        <input className="field-boxed" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
      </label>
      <label className="tds-field-row">
        <span>Rechnungs-Nr. im Panel (optional)</span>
        <input className="field-boxed" type="number" min="1" value={invoiceId} onChange={(e) => setInvoiceId(e.target.value)} />
      </label>
      <label className="tds-toggle-row">
        <input type="checkbox" checked={paid} onChange={(e) => setPaid(e.target.checked)} />
        <span>Der Kunde hat schon bezahlt</span>
      </label>
      <p className="marginalia">
        Mit Rechnungs-Nr. wird die Provision fällig, sobald die Rechnung als bezahlt gemeldet ist. Ohne bestätigen Sie die
        Zahlung selbst.
      </p>
      {problem ? <p className="tds-alert tds-alert--danger" role="alert">{problem}</p> : null}
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void submit()} disabled={busy} aria-busy={busy}>
          Erfassen
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)}>
          Abbrechen
        </button>
      </div>
    </section>
  );
}

// --- Partner ------------------------------------------------------------------

interface PartnerDraft {
  name: string;
  public_name: string;
  email: string;
  code: string;
  rate_percent: string;
  status: "active" | "paused";
  note: string;
  payout_name: string;
  tax_status: TaxStatus | "";
  vat_id: string;
  iban: string;
}

const draftOf = (p: Partner | null): PartnerDraft => ({
  name: p?.name ?? "",
  public_name: p?.public_name ?? "",
  email: p?.email ?? "",
  code: p?.code ?? "",
  rate_percent: p && p.own_rate ? String(p.rate_percent).replace(".", ",") : "",
  status: p?.status ?? "active",
  note: p?.note ?? "",
  payout_name: p?.payout_name ?? "",
  tax_status: p?.tax_status ?? "",
  vat_id: p?.vat_id ?? "",
  iban: "",
});

function Partners({ data, mutate }: { data: Overview; mutate: Mutate }) {
  const [editing, setEditing] = useState<number | "new" | null>(null);

  return (
    <div className="tds-stack tds-stack--loose">
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => setEditing("new")}>
          Neuer Partner
        </button>
        <span className="marginalia">
          Standardsatz {percent(data.settings.default_rate_percent)}, Wartefrist {data.settings.hold_days} Tage (Einstellungen →
          Empfehlungsprogramm).
        </span>
      </div>
      {editing === "new" ? <PartnerForm partner={null} mutate={mutate} onDone={() => setEditing(null)} /> : null}

      {data.partners.length === 0 ? (
        <p className="tds-empty">
          Noch keine Partner. Legen Sie einen mit der E-Mail-Adresse seines Portal-Zugangs an. Beim ersten Besuch von
          „Empfehlungen“ verknüpft er sich selbst.
        </p>
      ) : (
        <ul className="tds-list" aria-label="Partner">
          {data.partners.map((p) =>
            editing === p.id ? (
              <li key={p.id} className="tds-list__row">
                <PartnerForm partner={p} mutate={mutate} onDone={() => setEditing(null)} />
              </li>
            ) : (
              <li key={p.id} className="tds-list__row">
                <div className="min-w-0 tds-stack tds-stack--tight">
                  <p className="font-semibold">
                    {p.name} <span className="marginalia">({p.public_name})</span>
                  </p>
                  <p className="marginalia">
                    Code {p.code} · {percent(p.rate_percent)}
                    {p.own_rate ? " (eigener Satz)" : ""} · {p.email ?? "keine E-Mail"} ·{" "}
                    {p.user_id ? "Portal verknüpft" : "noch nicht im Portal"}
                  </p>
                  <p className="marginalia">
                    Auszahlung: {p.iban_masked ?? "keine IBAN"}
                    {p.tax_status ? ` · ${TAX[p.tax_status]}` : ""}
                  </p>
                </div>
                <div className="tds-toolbar">
                  <span className={p.status === "active" ? "chip chip--success" : "chip chip--neutral"}>
                    {p.status === "active" ? "Aktiv" : "Pausiert"}
                  </span>
                  <button
                    type="button"
                    className="btn btn-ghost"
                    onClick={() =>
                      void navigator.clipboard.writeText(p.link).then(
                        () => toast.success("Link kopiert."),
                        () => toast.danger("Link konnte nicht kopiert werden."),
                      )
                    }
                  >
                    Link kopieren
                  </button>
                  <button type="button" className="btn btn-ghost" onClick={() => setEditing(p.id)}>
                    Bearbeiten
                  </button>
                </div>
              </li>
            ),
          )}
        </ul>
      )}

      <ProductRates data={data} mutate={mutate} />
    </div>
  );
}

function PartnerForm({ partner, mutate, onDone }: { partner: Partner | null; mutate: Mutate; onDone: () => void }) {
  const [d, setD] = useState<PartnerDraft>(draftOf(partner));
  const [busy, setBusy] = useState(false);
  const set = <K extends keyof PartnerDraft>(k: K, v: PartnerDraft[K]) => setD((prev) => ({ ...prev, [k]: v }));

  const save = async () => {
    setBusy(true);
    const body = {
      name: d.name,
      public_name: d.public_name,
      email: d.email,
      code: d.code,
      rate_percent: d.rate_percent.trim() === "" ? null : d.rate_percent.replace(",", "."),
      note: d.note,
      ...(partner ? { status: d.status } : {}),
    };
    const ok = partner
      ? await mutate(`/admin/referrals/partners/${partner.id}`, { method: "PUT", ...json(body) }, "Partner gespeichert.")
      : await mutate("/admin/referrals/partners", { method: "POST", ...json(body) }, "Partner angelegt.");
    // Bank data goes through its own route: it is encrypted, and a partner
    // without any must still be savable.
    const payoutTouched = d.iban !== "" || d.payout_name !== (partner?.payout_name ?? "") || d.tax_status !== (partner?.tax_status ?? "") || d.vat_id !== (partner?.vat_id ?? "");
    if (ok && partner && payoutTouched) {
      await mutate(
        `/admin/referrals/partners/${partner.id}/payout-details`,
        { method: "PUT", ...json({ iban: d.iban, payout_name: d.payout_name, tax_status: d.tax_status, vat_id: d.vat_id }) },
        "Auszahlungsdaten gespeichert.",
      );
    }
    setBusy(false);
    if (ok) onDone();
  };

  return (
    <section className="tds-card p-4 tds-stack w-full" aria-label={partner ? `Partner ${partner.name} bearbeiten` : "Neuer Partner"}>
      <label className="tds-field-row">
        <span>Name</span>
        <input className="field-boxed" type="text" value={d.name} onChange={(e) => set("name", e.target.value)} />
      </label>
      <label className="tds-field-row">
        <span>Angezeigter Name (leer = „Vorname N.“)</span>
        <input className="field-boxed" type="text" value={d.public_name} onChange={(e) => set("public_name", e.target.value)} />
      </label>
      <label className="tds-field-row">
        <span>E-Mail des Portal-Zugangs</span>
        <input className="field-boxed" type="email" value={d.email} onChange={(e) => set("email", e.target.value)} />
      </label>
      <label className="tds-field-row">
        <span>Code (leer = automatisch)</span>
        <input className="field-boxed" type="text" value={d.code} onChange={(e) => set("code", e.target.value.toUpperCase())} />
      </label>
      {partner && d.code !== partner.code ? (
        <p className="tds-alert tds-alert--warning" role="status">Ein neuer Code macht bereits geteilte Links ungültig.</p>
      ) : null}
      <label className="tds-field-row">
        <span>Eigener Satz in % (leer = Standard)</span>
        <input className="field-boxed" type="text" inputMode="decimal" value={d.rate_percent} onChange={(e) => set("rate_percent", e.target.value)} />
      </label>
      {partner ? (
        <>
          <label className="tds-toggle-row">
            <input type="checkbox" checked={d.status === "active"} onChange={(e) => set("status", e.target.checked ? "active" : "paused")} />
            <span>Aktiv (pausiert: Code und Link zählen nicht mehr)</span>
          </label>
          <label className="tds-field-row">
            <span>Kontoinhaber</span>
            <input className="field-boxed" type="text" value={d.payout_name} onChange={(e) => set("payout_name", e.target.value)} />
          </label>
          <label className="tds-field-row">
            <span>IBAN ({partner.iban_masked ?? "keine"})</span>
            <input className="field-boxed" type="text" value={d.iban} onChange={(e) => set("iban", e.target.value)} placeholder="leer = behalten" autoComplete="off" />
          </label>
          <label className="tds-field-row">
            <span>Steuerstatus</span>
            <select className="field-boxed" value={d.tax_status} onChange={(e) => set("tax_status", e.target.value as TaxStatus | "")}>
              <option value="">unbekannt</option>
              {(Object.keys(TAX) as TaxStatus[]).map((k) => (
                <option key={k} value={k}>
                  {TAX[k]}
                </option>
              ))}
            </select>
          </label>
          {d.tax_status === "vat" ? (
            <label className="tds-field-row">
              <span>USt-IdNr.</span>
              <input className="field-boxed" type="text" value={d.vat_id} onChange={(e) => set("vat_id", e.target.value)} />
            </label>
          ) : null}
        </>
      ) : null}
      <label className="tds-field-row">
        <span>Interne Notiz</span>
        <textarea className="field-boxed" rows={2} value={d.note} onChange={(e) => set("note", e.target.value)} />
      </label>
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={busy || d.name.trim() === ""} aria-busy={busy}>
          Speichern
        </button>
        <button type="button" className="btn btn-ghost" onClick={onDone}>
          Abbrechen
        </button>
      </div>
    </section>
  );
}

function ProductRates({ data, mutate }: { data: Overview; mutate: Mutate }) {
  const [productId, setProductId] = useState("");
  const [rate, setRate] = useState("");

  const save = async (pid: string, value: string | null, ok: string) => {
    const done = await mutate(
      "/admin/referrals/product-rates",
      { method: "PUT", ...json({ product_id: pid, rate_percent: value === null ? null : value.replace(",", ".") }) },
      ok,
    );
    if (done && value !== null) {
      setProductId("");
      setRate("");
    }
  };

  return (
    <section className="tds-stack" aria-labelledby="ref-rates">
      <h2 id="ref-rates" className="text-lg font-semibold">Sätze pro Produkt</h2>
      <p className="marginalia">Ein Produktsatz geht dem Satz des Partners vor. Die Produkt-ID steht in der Produktliste des Shops.</p>
      {data.product_rates.length > 0 ? (
        <ul className="tds-list" aria-label="Produktsätze">
          {data.product_rates.map((r) => (
            <li key={r.product_id} className="tds-list__row">
              <span>
                Produkt {r.product_id}: <strong>{percent(r.rate_percent)}</strong>
              </span>
              <button type="button" className="btn btn-ghost" onClick={() => void save(r.product_id, null, "Produktsatz entfernt.")}>
                Entfernen
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      <div className="tds-row">
        <label className="tds-field-row">
          <span>Produkt-ID</span>
          <input className="field-boxed" type="text" value={productId} onChange={(e) => setProductId(e.target.value)} />
        </label>
        <label className="tds-field-row">
          <span>Satz in %</span>
          <input className="field-boxed" type="text" inputMode="decimal" value={rate} onChange={(e) => setRate(e.target.value)} />
        </label>
        <button
          type="button"
          className="btn btn-ghost"
          disabled={productId.trim() === "" || rate.trim() === ""}
          onClick={() => void save(productId.trim(), rate, "Produktsatz gespeichert.")}
        >
          Satz setzen
        </button>
      </div>
    </section>
  );
}

// --- Auszahlungen -------------------------------------------------------------

function Payouts({ data, mutate }: { data: Overview; mutate: Mutate }) {
  const [settling, setSettling] = useState<{ partner: Partner; total: number } | null>(null);
  const [reference, setReference] = useState("");
  const [busy, setBusy] = useState(false);
  const [statement, setStatement] = useState<Statement | null>(null);

  const due = useMemo(() => {
    const byPartner = new Map<number, number>();
    for (const c of data.commissions) {
      if (c.status === "approved" && c.partner_id !== null) {
        byPartner.set(c.partner_id, (byPartner.get(c.partner_id) ?? 0) + (c.commission_cents ?? 0));
      }
    }
    return data.partners
      .map((partner) => ({ partner, total: byPartner.get(partner.id) ?? 0 }))
      .filter((r) => r.total !== 0);
  }, [data]);

  const openStatement = async (id: number) => {
    const res = await apiFetch(`/admin/referrals/payouts/${id}`).catch(() => null);
    if (res === null || !res.ok) {
      toast.danger(res === null ? "Abrechnung nicht erreichbar." : await errorText(res, "Abrechnung nicht geladen"));
      return;
    }
    setStatement((await res.json()) as Statement);
  };

  if (statement) return <StatementView s={statement} onClose={() => setStatement(null)} />;

  return (
    <div className="tds-stack tds-stack--loose">
      <section className="tds-stack" aria-labelledby="ref-due">
        <h2 id="ref-due" className="text-lg font-semibold">Fällig</h2>
        {due.length === 0 ? (
          <p className="tds-empty">Gerade ist nichts fällig. Provisionen werden fällig, wenn der Auftrag bezahlt und die Wartefrist vorbei ist.</p>
        ) : (
          <ul className="tds-list" aria-label="Fällige Provisionen">
            {due.map(({ partner, total }) => (
              <li key={partner.id} className="tds-list__row">
                <div className="min-w-0">
                  <p className="font-semibold">{partner.name}</p>
                  <p className="marginalia">
                    {partner.iban_masked ? `IBAN ${partner.iban_masked}` : "Keine IBAN hinterlegt"}
                    {partner.tax_status ? ` · ${TAX[partner.tax_status]}` : ""}
                  </p>
                </div>
                <div className="tds-toolbar">
                  <strong className="tabular-nums">{euros(total)}</strong>
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={total <= 0}
                    title={total <= 0 ? "Rückbuchungen übersteigen die fälligen Provisionen." : undefined}
                    onClick={() => {
                      setReference(`Provision ${new Date().toISOString().slice(0, 7)}`);
                      setSettling({ partner, total });
                    }}
                  >
                    Auszahlung verbuchen
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>

      {settling ? (
        <section className="tds-card p-4 tds-stack" aria-labelledby="ref-settle">
          <h2 id="ref-settle" className="text-lg font-semibold">Auszahlung an {settling.partner.name}</h2>
          <p>
            Überweisen Sie <strong>{euros(settling.total)}</strong>
            {settling.partner.tax_status === "vat" ? " zuzüglich 19 % Umsatzsteuer" : ""} und verbuchen Sie die Auszahlung
            danach hier.
          </p>
          <label className="tds-field-row">
            <span>Verwendungszweck</span>
            <input className="field-boxed" type="text" value={reference} onChange={(e) => setReference(e.target.value)} />
          </label>
          <div className="tds-toolbar">
            <button
              type="button"
              className="btn btn-primary"
              disabled={busy}
              aria-busy={busy}
              onClick={async () => {
                setBusy(true);
                const ok = await mutate("/admin/referrals/payouts", { method: "POST", ...json({ partner_id: settling.partner.id, reference }) }, "Auszahlung verbucht.");
                setBusy(false);
                if (ok) setSettling(null);
              }}
            >
              Als ausgezahlt verbuchen
            </button>
            <button type="button" className="btn btn-ghost" onClick={() => setSettling(null)}>
              Abbrechen
            </button>
          </div>
        </section>
      ) : null}

      <section className="tds-stack" aria-labelledby="ref-payouts">
        <h2 id="ref-payouts" className="text-lg font-semibold">Bisherige Auszahlungen</h2>
        {data.payouts.length === 0 ? (
          <p className="tds-empty">Noch keine Auszahlungen.</p>
        ) : (
          <ul className="tds-list" aria-label="Auszahlungen">
            {data.payouts.map((p) => (
              <li key={p.id} className="tds-list__row">
                <span>
                  {day(p.paid_at)} · {p.partner_name ?? `Partner ${p.partner_id}`} · {p.reference ?? "ohne Zweck"}
                </span>
                <div className="tds-toolbar">
                  <strong className="tabular-nums">{euros(p.total_cents)}</strong>
                  <button type="button" className="btn btn-ghost" onClick={() => void openStatement(p.id)}>
                    Abrechnung
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function StatementView({ s, onClose }: { s: Statement; onClose: () => void }) {
  const vat = s.partner.tax_status === "vat";
  return (
    <section className="tds-card p-4 tds-stack" aria-labelledby="ref-statement">
      <div className="tds-row tds-row--between">
        <h2 id="ref-statement" className="text-lg font-semibold">
          Provisionsabrechnung {s.payout.reference ?? `#${s.payout.id}`}
        </h2>
        <div className="tds-toolbar">
          <button type="button" className="btn btn-primary" onClick={() => window.print()}>
            Drucken
          </button>
          <button type="button" className="btn btn-ghost" onClick={onClose}>
            Zurück
          </button>
        </div>
      </div>
      <p>
        {vat ? "Gutschrift" : "Abrechnung"} vom {day(s.payout.paid_at)} für <strong>{s.partner.payout_name ?? s.partner.name}</strong>
        {s.partner.vat_id ? `, USt-IdNr. ${s.partner.vat_id}` : ""}
        {s.partner.iban ? `, IBAN ${s.partner.iban}` : ""}.
      </p>
      <ul className="tds-list" aria-label="Positionen">
        {s.lines.map((l) => (
          <li key={l.id} className="tds-list__row">
            <span>
              {day(l.created_at)} · {l.description ?? "Auftrag"} · Netto {euros(l.net_cents)} · {percent(l.rate_percent)}
            </span>
            <strong className="tabular-nums">{euros(l.commission_cents)}</strong>
          </li>
        ))}
      </ul>
      <p className="tabular-nums">
        Summe netto <strong>{euros(s.payout.total_cents)}</strong>
        {vat ? (
          <>
            {" "}· zzgl. 19 % USt {euros(s.payout.vat_cents)} · Auszahlung <strong>{euros(s.payout.gross_cents)}</strong>
          </>
        ) : null}
      </p>
      <p className="marginalia">
        {s.partner.tax_status === "small_business"
          ? "Der Partner ist Kleinunternehmer nach § 19 UStG; es wird keine Umsatzsteuer ausgewiesen."
          : vat
            ? "Gutschrift nach § 14 Abs. 2 Satz 2 UStG."
            : "Die Provision ist vom Empfänger selbst zu versteuern."}
      </p>
    </section>
  );
}
