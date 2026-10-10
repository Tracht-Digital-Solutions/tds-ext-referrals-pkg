<?php
/**
 * API documentation for the Empfehlungsprogramm routes — consumed through
 * `ApiDocSource` and rendered in the admin frontend's API reference.
 * `php/tests/ReferralsApiDocsTest.php` asserts documented and mounted routes
 * are the same set; `pattern` is the Slim pattern verbatim.
 */

declare(strict_types=1);

$id = ['in' => 'path', 'name' => 'id', 'description' => 'Numerische Id.'];
$err = ['status' => 422, 'description' => '`{error}` — deutsche Meldung, unverändert anzeigbar.'];

$commissionAction = static fn (string $action, string $summary, string $description, array $params = []): array => [
    'method' => 'POST',
    'pattern' => "/admin/referrals/commissions/{id:[0-9]+}/{$action}",
    'summary' => $summary,
    'description' => $description,
    'tag' => 'Vermittlungen',
    'permission' => 'referrals:manage',
    'params' => array_merge([$id], $params),
    'responses' => [
        ['status' => 200, 'description' => 'Die Vermittlung danach.'],
        ['status' => 404, 'description' => 'Vermittlung oder Partner nicht gefunden.'],
        ['status' => 409, 'description' => 'Im aktuellen Status nicht möglich.'],
    ],
];

return [
    [
        'method' => 'GET',
        'pattern' => '/admin/referrals/overview',
        'summary' => 'Partner, Vermittlungen, Auszahlungen und Sätze für das Panel',
        'description' => 'Ein Aufruf für die ganze Verwaltungsseite. Gibt vorher fällige Provisionen frei '
            . '(bezahlt und Wartefrist vorbei) — ohne Cron, wie der Amazon-Abgleich.',
        'tag' => 'Übersicht',
        'permission' => 'referrals:read',
        'responses' => [['status' => 200, 'description' => '`{partners, commissions, payouts, product_rates, settings}`']],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/referrals/partners',
        'summary' => 'Partner anlegen',
        'description' => 'Ohne `code` wird einer aus dem Namen erzeugt. Mit der `email` eines Portal-Zugangs '
            . 'verknüpft sich der Partner beim ersten Besuch von „Weiterempfehlen“.',
        'tag' => 'Partner',
        'permission' => 'referrals:manage',
        'params' => [
            ['in' => 'body', 'name' => 'name', 'description' => 'Pflicht.'],
            ['in' => 'body', 'name' => 'public_name', 'description' => 'Was Käufer bei „Empfohlen von“ sehen; Standard „Vorname N.“.'],
            ['in' => 'body', 'name' => 'email', 'description' => 'Optional; verknüpft den Portal-Zugang und erkennt Eigenkäufe.'],
            ['in' => 'body', 'name' => 'code', 'description' => 'Optional, `[A-Z0-9-]`, 3–40 Zeichen.'],
            ['in' => 'body', 'name' => 'rate_percent', 'description' => 'Optional; leer = Standardsatz.'],
            ['in' => 'body', 'name' => 'note', 'description' => 'Interne Notiz.'],
        ],
        'responses' => [
            ['status' => 201, 'description' => 'Der Partner.'],
            ['status' => 409, 'description' => 'Code vergeben.'],
            $err,
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/referrals/partners/{id:[0-9]+}',
        'summary' => 'Partner ändern oder pausieren',
        'description' => 'Felder wie beim Anlegen, dazu `status` (`active`|`paused`). Ein pausierter Code wird an '
            . 'der Kasse verworfen. Ein geänderter Satz gilt nur für neue Vermittlungen.',
        'tag' => 'Partner',
        'permission' => 'referrals:manage',
        'params' => [$id],
        'responses' => [['status' => 200, 'description' => 'Der Partner.'], ['status' => 409, 'description' => 'Code vergeben.'], $err],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/referrals/partners/{id:[0-9]+}/payout-details',
        'summary' => 'Auszahlungsdaten eines Partners setzen',
        'description' => 'IBAN (verschlüsselt im Einstellungsspeicher, leer = behalten), Kontoinhaber, Steuerstatus '
            . '(`private`|`small_business`|`vat`) und USt-IdNr.',
        'tag' => 'Partner',
        'permission' => 'referrals:manage',
        'params' => [
            $id,
            ['in' => 'body', 'name' => 'iban', 'description' => 'Wird per Prüfziffer geprüft.'],
            ['in' => 'body', 'name' => 'payout_name', 'description' => 'Kontoinhaber.'],
            ['in' => 'body', 'name' => 'tax_status', 'description' => '`private`, `small_business` oder `vat`.'],
            ['in' => 'body', 'name' => 'vat_id', 'description' => 'Pflicht bei `vat`.'],
        ],
        'responses' => [
            ['status' => 200, 'description' => 'Der Partner, IBAN maskiert.'],
            ['status' => 503, 'description' => 'SETTINGS_ENCRYPTION_KEY fehlt.'],
            $err,
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/admin/referrals/product-rates',
        'summary' => 'Eigenen Provisionssatz für ein Produkt setzen oder entfernen',
        'description' => 'Geht dem Satz des Partners vor. `rate_percent` leer entfernt den Produktsatz.',
        'tag' => 'Sätze',
        'permission' => 'referrals:manage',
        'params' => [
            ['in' => 'body', 'name' => 'product_id', 'description' => 'Produkt-Id des verkaufenden Moduls (Shop: numerische Id).'],
            ['in' => 'body', 'name' => 'rate_percent', 'description' => '0–100 oder leer.'],
        ],
        'responses' => [['status' => 200, 'description' => '`{product_id, rate_percent}`'], $err],
    ],
    [
        'method' => 'POST',
        'pattern' => '/admin/referrals/commissions',
        'summary' => 'Vermittelten Auftrag von Hand erfassen',
        'description' => 'Für Aufträge außerhalb des Shops. Mit `invoice_id` an eine Billing-Rechnung gebunden: '
            . 'die Provision wird fällig, sobald die Rechnung bezahlt ist. Ohne sie bestätigt der Betreiber die '
            . 'Zahlung selbst (`paid` oder später „Zahlung eingegangen“).',
        'tag' => 'Vermittlungen',
        'permission' => 'referrals:manage',
        'params' => [
            ['in' => 'body', 'name' => 'partner_id', 'description' => 'Pflicht.'],
            ['in' => 'body', 'name' => 'net_cents', 'description' => 'Nettobetrag des Auftrags in Cent.'],
            ['in' => 'body', 'name' => 'description', 'description' => 'Pflicht; sieht auch der Partner.'],
            ['in' => 'body', 'name' => 'customer_email', 'description' => 'Optional; erkennt Eigenkäufe.'],
            ['in' => 'body', 'name' => 'invoice_id', 'description' => 'Optional, Billing-Rechnung.'],
            ['in' => 'body', 'name' => 'paid', 'description' => 'Optional, schon bezahlt.'],
        ],
        'responses' => [['status' => 201, 'description' => 'Die Vermittlung.'], ['status' => 409, 'description' => 'Rechnung schon erfasst.'], $err],
    ],
    $commissionAction('assign', 'Vermittlung einem Partner zuordnen', 'Für Nennungen („Wer hat dich empfohlen?“) ohne Code. Satz und Betrag werden dabei festgeschrieben.', [
        ['in' => 'body', 'name' => 'partner_id', 'description' => 'Pflicht.'],
    ]),
    $commissionAction('approve', 'Vermittlung sofort freigeben', 'Ohne die Wartefrist abzuwarten; nur wenn der Auftrag bezahlt ist.'),
    $commissionAction('reject', 'Vermittlung ablehnen', 'Keine Provision; der Grund wird in der Notiz vermerkt.', [
        ['in' => 'body', 'name' => 'reason', 'description' => 'Optional.'],
    ]),
    $commissionAction('confirm-paid', 'Zahlungseingang eines Auftrags bestätigen', 'Startet die Wartefrist für einen von Hand erfassten Auftrag.'),
    [
        'method' => 'POST',
        'pattern' => '/admin/referrals/payouts',
        'summary' => 'Auszahlung an einen Partner verbuchen',
        'description' => 'Fasst alle freigegebenen Provisionen des Partners zusammen, Rückbuchungen eingeschlossen, '
            . 'und markiert sie als ausgezahlt. Die Überweisung selbst macht der Betreiber.',
        'tag' => 'Auszahlungen',
        'permission' => 'referrals:manage',
        'params' => [
            ['in' => 'body', 'name' => 'partner_id', 'description' => 'Pflicht.'],
            ['in' => 'body', 'name' => 'reference', 'description' => 'Optional, z. B. Verwendungszweck.'],
        ],
        'responses' => [['status' => 201, 'description' => 'Die Auszahlung.'], ['status' => 409, 'description' => 'Nichts fällig.']],
    ],
    [
        'method' => 'GET',
        'pattern' => '/admin/referrals/payouts/{id:[0-9]+}',
        'summary' => 'Provisionsabrechnung einer Auszahlung',
        'description' => 'Positionen, Partner mit vollständiger IBAN und bei `vat` die Umsatzsteuer (19 %) — die Vorlage für die Gutschrift.',
        'tag' => 'Auszahlungen',
        'permission' => 'referrals:read',
        'params' => [$id],
        'responses' => [['status' => 200, 'description' => '`{payout, partner, lines}`'], ['status' => 404, 'description' => 'Nicht gefunden.']],
    ],
    [
        'method' => 'GET',
        'pattern' => '/referrals/me',
        'summary' => 'Eigenes Partnerkonto (Portal)',
        'description' => 'Link, Code, Vermittlungen ohne Käuferdaten, Summen und Auszahlungen des angemeldeten '
            . 'Benutzers. Ein Partner mit derselben E-Mail ohne Zugang wird dabei verknüpft.',
        'tag' => 'Portal',
        'permission' => 'referrals:partner',
        'responses' => [
            ['status' => 200, 'description' => '`{partner, commissions, totals, payouts, settings}`'],
            ['status' => 404, 'description' => 'Kein Partnerkonto zugeordnet.'],
        ],
    ],
    [
        'method' => 'PUT',
        'pattern' => '/referrals/me/payout-details',
        'summary' => 'Eigene Auszahlungsdaten pflegen (Portal)',
        'description' => 'Wie die Verwaltungsroute, für das eigene Partnerkonto.',
        'tag' => 'Portal',
        'permission' => 'referrals:partner',
        'params' => [
            ['in' => 'body', 'name' => 'iban', 'description' => 'Leer = behalten.'],
            ['in' => 'body', 'name' => 'payout_name', 'description' => 'Kontoinhaber.'],
            ['in' => 'body', 'name' => 'tax_status', 'description' => '`private`, `small_business` oder `vat`.'],
            ['in' => 'body', 'name' => 'vat_id', 'description' => 'Pflicht bei `vat`.'],
        ],
        'responses' => [['status' => 200, 'description' => 'Das Partnerkonto.'], $err],
    ],
];
