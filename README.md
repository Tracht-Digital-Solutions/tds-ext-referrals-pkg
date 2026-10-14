# tds-ext-referrals-pkg

Das **Empfehlungsprogramm** der TDS-Plattform. Wer einen Auftrag vermittelt, ohne selbst
Auftraggeber zu sein, bekommt eine Provision auf den Nettobetrag.

- **Zuordnung:** Empfehlungslink (`?ref=CODE`), Code an der Shop-Kasse, Nennung durch den
  Kunden („Wer hat dich empfohlen?“) oder von Hand im Panel.
- **Fällig:** wenn der Auftrag bezahlt und die Wartefrist (Standard 21 Tage) vorbei ist.
- **Auszahlung:** von Hand per Überweisung. Das Panel verbucht sie und liefert die Abrechnung.

## Einbinden

- **API:** `new ReferralsModule()` in `tds-core-frontend-api` (`Modules::enabled()`) und in
  die Extension-Listen des Gateways.
- **Panels:** das Manifest in `astro.config.mjs` von `tds-admin-frontend` und
  `tds-customer-frontend`.
- **Verkäufer:** Shop und Billing melden Verkäufe über `Commerce\SaleEvents` aus dem
  Contract (≥ 1.15). Dieses Modul kennen sie nicht.

## Partner einrichten

1. Panel → Empfehlungen → Partner → „Neuer Partner“, mit der E-Mail des Portal-Zugangs.
2. Den Benutzer im Portal einladen, Gruppe „Vermittler“ (`referrals:partner`).
3. Beim ersten Besuch von „Empfehlungen“ verknüpft sich das Partnerkonto.

## Entwickeln

```bash
npm install --no-package-lock && npm run test:run && npm run build
php composer.phar install && composer test
```

## Lizenz / License

**Öffentlich einsehbar, alle Rechte vorbehalten.** Der Code kann gelesen und
zur Beurteilung der Programmierkenntnisse betrachtet werden. Es besteht keine
allgemeine Erlaubnis zum Kopieren, Verändern oder kommerziellen Weiterverwenden.
Die vollständigen Bedingungen und Ausnahmen stehen in [LICENSE](LICENSE).

**Publicly viewable, all rights reserved.** Public access permits inspection of
the code, but grants no general permission to copy, modify or commercially reuse
it. See [LICENSE](LICENSE) for the complete terms and exceptions.
