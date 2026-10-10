import { useEffect, useState } from "react";
import { Spinner, toast } from "@tracht-digital-solutions/tds-shared/components";
import { apiFetch } from "@tracht-digital-solutions/tds-shared/api";

interface Masked {
  key: string;
  value?: string;
}

const NS = "/admin/settings/referrals";

const FIELDS = [
  { key: "default_rate_percent", label: "Standard-Provision (% vom Netto)", placeholder: "10", inputMode: "decimal" as const },
  { key: "hold_days", label: "Wartefrist nach Zahlung (Tage)", placeholder: "21", inputMode: "numeric" as const },
  { key: "link_days", label: "Gültigkeit eines Empfehlungslinks (Tage)", placeholder: "30", inputMode: "numeric" as const },
  { key: "link_base", label: "Ziel des Empfehlungslinks", placeholder: "https://shop.tracht-digital.de/", inputMode: "url" as const },
  { key: "terms_url", label: "Adresse der Partnerbedingungen", placeholder: "https://…", inputMode: "url" as const },
];

/** The programme's terms, in the core settings store (admin only). Empty = default. */
export default function ReferralsSettings() {
  const [values, setValues] = useState<Record<string, string> | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const load = async () => {
    const res = await apiFetch(NS).catch(() => null);
    if (res === null) {
      setStatus("Einstellungen konnten nicht geladen werden — die API ist nicht erreichbar.");
      setValues({});
      return;
    }
    if (!res.ok) {
      setStatus(res.status === 401 || res.status === 403 ? "Nur für Administratoren." : `Fehler (HTTP ${res.status}).`);
      setValues({});
      return;
    }
    const d = (await res.json()) as { settings?: Masked[] };
    setValues(Object.fromEntries((d.settings ?? []).map((s) => [s.key, s.value ?? ""])));
  };

  useEffect(() => {
    void load();
  }, []);

  const save = async () => {
    setBusy(true);
    setStatus(null);
    const res = await apiFetch(NS, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        settings: FIELDS.map((f) => ({ key: f.key, secret: false, value: (values?.[f.key] ?? "").trim() })),
      }),
    }).catch(() => null);
    setBusy(false);
    if (res === null) {
      toast.danger("Speichern fehlgeschlagen — die API ist nicht erreichbar.");
    } else if (res.ok) {
      toast.success("Gespeichert.");
      void load();
    } else {
      toast.danger(`Speichern fehlgeschlagen (HTTP ${res.status}).`);
    }
  };

  if (values === null) return <p><Spinner /></p>;

  return (
    <div className="tds-stack">
      <p className="marginalia">
        Gilt für neue Vermittlungen. Bereits erfasste behalten ihren Satz. Leere Felder nehmen den angezeigten Standardwert.
      </p>
      {FIELDS.map((f) => (
        <label key={f.key} className="tds-field-row">
          <span>{f.label}</span>
          <input
            className="field-boxed"
            type="text"
            inputMode={f.inputMode}
            value={values[f.key] ?? ""}
            placeholder={f.placeholder}
            onChange={(e) => setValues({ ...values, [f.key]: e.target.value })}
          />
        </label>
      ))}
      {status ? <p className="tds-alert tds-alert--danger" role="alert">{status}</p> : null}
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={busy} aria-busy={busy}>
          Speichern
        </button>
      </div>
    </div>
  );
}
