import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";
import {
  TAX,
  VIA,
  day,
  errorText,
  euros,
  percent,
  statusOf,
  waitLabel,
  type Commission,
  type Partner,
  type Payout,
  type ProgrammeSettings,
  type TaxStatus,
} from "./format";

interface Me {
  partner: Partner;
  commissions: Commission[];
  totals: { pending: number; approved: number; paid: number };
  payouts: Payout[];
  settings: ProgrammeSettings;
}

/**
 * The partner's own view in the customer portal: their link and code, what
 * they brought in, what is due, and where the money goes. Bound to the
 * signed-in user (`GET /referrals/me`), never to the active company.
 */
export default function ReferralsPortal() {
  const [me, setMe] = useState<Me | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    const res = await apiFetch("/referrals/me").catch(() => null);
    if (res === null) {
      setError("Die Übersicht konnte nicht geladen werden — die Verbindung ist unterbrochen. Bitte später erneut versuchen.");
    } else if (res.status === 404) {
      setError("Ihr Zugang ist noch keinem Partnerkonto zugeordnet. Bitte melden Sie sich bei uns, dann schalten wir es frei.");
    } else if (res.status === 403) {
      setError("Ihr Zugang enthält keine Berechtigung für das Empfehlungsprogramm.");
    } else if (!res.ok) {
      setError(await errorText(res, "Die Übersicht konnte nicht geladen werden"));
    } else {
      setError(null);
      setMe((await res.json()) as Me);
    }
  };

  useEffect(() => {
    void load();
  }, []);

  if (error) return <p className="tds-alert tds-alert--danger" role="alert">{error}</p>;
  if (me === null) {
    return (
      <p className="tds-empty" aria-busy="true">
        <Spinner /> Wird geladen …
      </p>
    );
  }

  const { partner, commissions, totals, settings } = me;

  return (
    <div className="tds-stack tds-stack--loose">
      {partner.status === "paused" ? (
        <p className="tds-alert tds-alert--warning" role="status">
          Ihr Partnerkonto ist pausiert. Neue Empfehlungen werden gerade nicht gezählt.
        </p>
      ) : null}

      <ShareCard partner={partner} settings={settings} />

      <div className="tds-row">
        <Figure label="Wartet" value={euros(totals.pending)} hint={`Fällig ${settings.hold_days} Tage nach Zahlung`} />
        <Figure label="Fällig" value={euros(totals.approved)} hint="Kommt mit der nächsten Auszahlung" />
        <Figure label="Ausgezahlt" value={euros(totals.paid)} hint={`${me.payouts.length} Auszahlung${me.payouts.length === 1 ? "" : "en"}`} />
      </div>

      <section className="tds-stack" aria-labelledby="ref-list">
        <h2 id="ref-list" className="text-lg font-semibold">Ihre Vermittlungen</h2>
        {commissions.length === 0 ? (
          <p className="tds-empty">Noch keine Vermittlungen. Sobald jemand über Ihren Link oder Code bestellt, erscheint es hier.</p>
        ) : (
          <ul className="tds-list" aria-label="Vermittlungen">
            {commissions.map((c) => {
              const s = statusOf(c.status);
              return (
                <li key={c.id} className="tds-list__row">
                  <div className="min-w-0">
                    <p className="font-semibold">{c.description ?? "Auftrag"}</p>
                    <p className="marginalia">
                      {day(c.created_at)} · {VIA[c.via] ?? c.via}
                      {waitLabel(c) ? ` · ${waitLabel(c)}` : ""}
                    </p>
                  </div>
                  <div className="tds-row">
                    <span className={s.chip}>{s.label}</span>
                    <strong className="tabular-nums">{euros(c.commission_cents)}</strong>
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </section>

      <PayoutDetails partner={partner} onSaved={(p) => setMe({ ...me, partner: p })} />

      {settings.terms_url ? (
        <p className="marginalia">
          Es gelten unsere{" "}
          <a href={settings.terms_url} target="_blank" rel="noopener noreferrer">
            Partnerbedingungen
          </a>
          .
        </p>
      ) : null}
    </div>
  );
}

function Figure({ label, value, hint }: { label: string; value: string; hint: string }) {
  return (
    <div className="tds-card p-4 tds-stack tds-stack--tight">
      <span className="marginalia">{label}</span>
      <strong className="text-2xl tabular-nums">{value}</strong>
      <span className="marginalia">{hint}</span>
    </div>
  );
}

function ShareCard({ partner, settings }: { partner: Partner; settings: ProgrammeSettings }) {
  const copy = async (text: string, what: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success(`${what} kopiert.`);
    } catch {
      toast.danger(`${what} konnte nicht kopiert werden. Bitte markieren und von Hand kopieren.`);
    }
  };

  return (
    <section className="tds-card p-4 tds-stack" aria-labelledby="ref-share">
      <h2 id="ref-share" className="text-lg font-semibold">Ihr Empfehlungslink</h2>
      <p>
        Sie erhalten <strong>{percent(partner.rate_percent)}</strong> vom Nettobetrag jedes bezahlten Auftrags, den Sie vermitteln.
        Ein Link gilt {settings.link_days} Tage.
      </p>
      <label className="tds-field-row">
        <span>Link</span>
        <input className="field-boxed" type="text" readOnly value={partner.link} onFocus={(e) => e.currentTarget.select()} />
      </label>
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void copy(partner.link, "Link")}>
          Link kopieren
        </button>
        <button type="button" className="btn btn-ghost" onClick={() => void copy(partner.code, "Code")}>
          Code {partner.code} kopieren
        </button>
      </div>
      <p className="marginalia">
        Ohne Link geht es auch: An der Kasse gibt man Ihren Code ein. Für Aufträge außerhalb des Shops reicht es, wenn
        der Kunde Ihren Namen nennt.
      </p>
    </section>
  );
}

function PayoutDetails({ partner, onSaved }: { partner: Partner; onSaved: (p: Partner) => void }) {
  const [name, setName] = useState(partner.payout_name ?? "");
  const [iban, setIban] = useState("");
  const [tax, setTax] = useState<TaxStatus | "">(partner.tax_status ?? "");
  const [vatId, setVatId] = useState(partner.vat_id ?? "");
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState<string | null>(null);

  const save = async () => {
    setBusy(true);
    setProblem(null);
    const res = await apiFetch("/referrals/me/payout-details", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ payout_name: name, iban, tax_status: tax, vat_id: vatId }),
    }).catch(() => null);
    setBusy(false);
    if (res === null) {
      toast.danger("Speichern fehlgeschlagen — die API ist nicht erreichbar.");
      return;
    }
    if (!res.ok) {
      setProblem(await errorText(res, "Speichern fehlgeschlagen"));
      return;
    }
    setIban("");
    onSaved((await res.json()) as Partner);
    toast.success("Auszahlungsdaten gespeichert.");
  };

  return (
    <section className="tds-card p-4 tds-stack" aria-labelledby="ref-payout">
      <h2 id="ref-payout" className="text-lg font-semibold">Auszahlung</h2>
      <p className="marginalia">Wir überweisen fällige Provisionen und schicken Ihnen eine Abrechnung.</p>
      <label className="tds-field-row">
        <span>Kontoinhaber</span>
        <input className="field-boxed" type="text" value={name} onChange={(e) => setName(e.target.value)} autoComplete="name" />
      </label>
      <label className="tds-field-row">
        <span>
          IBAN <em className="marginalia">({partner.iban_masked ? `hinterlegt: ${partner.iban_masked}` : "noch keine"})</em>
        </span>
        <input
          className="field-boxed"
          type="text"
          value={iban}
          onChange={(e) => setIban(e.target.value)}
          placeholder={partner.iban_masked ? "leer = behalten" : "DE…"}
          autoComplete="off"
          inputMode="text"
        />
      </label>
      <label className="tds-field-row">
        <span>Steuerstatus</span>
        <select className="field-boxed" value={tax} onChange={(e) => setTax(e.target.value as TaxStatus | "")}>
          <option value="">Bitte wählen</option>
          {(Object.keys(TAX) as TaxStatus[]).map((k) => (
            <option key={k} value={k}>
              {TAX[k]}
            </option>
          ))}
        </select>
      </label>
      {tax === "vat" ? (
        <label className="tds-field-row">
          <span>USt-IdNr.</span>
          <input className="field-boxed" type="text" value={vatId} onChange={(e) => setVatId(e.target.value)} />
        </label>
      ) : null}
      {problem ? <p className="tds-alert tds-alert--danger" role="alert">{problem}</p> : null}
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={busy} aria-busy={busy}>
          Speichern
        </button>
      </div>
    </section>
  );
}
