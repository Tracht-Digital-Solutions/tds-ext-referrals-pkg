import { useEffect, useState } from "react";
import { Alert, Spinner } from "@tracht-digital-solutions/tds-shared/components";
import { useRuntimeSettings } from "@tracht-digital-solutions/tds-shared/admin";

const FIELDS = [
  { key: "default_rate_percent", label: "Standard-Provision (% vom Netto)", placeholder: "10", inputMode: "decimal" as const },
  { key: "hold_days", label: "Wartefrist nach Zahlung (Tage)", placeholder: "21", inputMode: "numeric" as const },
  { key: "link_days", label: "Gültigkeit eines Empfehlungslinks (Tage)", placeholder: "30", inputMode: "numeric" as const },
  { key: "link_base", label: "Ziel des Empfehlungslinks", placeholder: "https://shop.tracht-digital.de/", inputMode: "url" as const },
  { key: "terms_url", label: "Adresse der Partnerbedingungen", placeholder: "https://…", inputMode: "url" as const },
];

/** The programme's terms, in the core settings store (admin only). Empty = default. */
export default function ReferralsSettings() {
  const settings = useRuntimeSettings("referrals");
  const [values, setValues] = useState<Record<string, string>>({});

  useEffect(() => {
    setValues(Object.fromEntries(FIELDS.map((f) => [f.key, settings.get(f.key)?.value ?? ""])));
  }, [settings.settings]);

  const save = () => settings.save(FIELDS.map((f) => ({ key: f.key, value: (values[f.key] ?? "").trim() })));

  if (!settings.loaded) return <p><Spinner /></p>;

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
      <Alert>{settings.error}</Alert>
      <div className="tds-toolbar">
        <button type="button" className="btn btn-primary" onClick={() => void save()} disabled={settings.busy} aria-busy={settings.busy}>
          Speichern
        </button>
      </div>
    </div>
  );
}
