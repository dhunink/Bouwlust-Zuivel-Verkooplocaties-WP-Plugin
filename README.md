# Bouwlust Zuivel Verkooplocaties – WordPress Plugin

WordPress-plugin voor het beheren en periodiek importeren van zuivel-verkooplocaties op de website van Hoeve Bouwlust.

## Huidige opzet

De plugin sluit aan op de bestaande WordPress/ACF-configuratie:

- Custom Post Type: `zuivel_verkooppunt`
- ACF-velden:
  - `address`
  - `postal_code`
  - `city`
  - `location` (ACF Google Map)
  - `import_id`
- Themeco Pro gebruikt het CPT als Looper Provider en het ACF Google Map-veld voor dynamische markers.

## Importeren

Vanaf versie 0.1.1 is er geen apart admin-menu voor de importer. Op de bestaande lijstpagina van **Zuivel-verkooppunten** verschijnt naast **Nieuw toevoegen** een knop **Importeren**.

De importer accepteert CSV en XLSX met minimaal de kolommen:

- `Klant`
- `Straat + huisnr.`
- `Postcode`
- `Plaats`

Optioneel kan een kolom `Import-ID` of `ID` worden aangeleverd.

Voor de daadwerkelijke import wordt eerst een preview getoond met nieuwe, gewijzigde, ongewijzigde en onduidelijke records.

## Matching

Bestaande verkooppunten worden in deze volgorde gezocht:

1. Expliciete Import-ID uit het bronbestand.
2. Exacte combinatie adres + postcode.
3. Exacte klantnaam.

Nieuwe records zonder bron-ID krijgen automatisch een interne ID zoals `VP-000123`.

## Geocoding

Nieuwe verkooppunten en gewijzigde adressen worden server-side gegeocodeerd met Google Geocoding en opgeslagen in het ACF Google Map-veld `location`.

De plugin gebruikt bij voorkeur een aparte server-side API-key uit `wp-config.php`:

```php
define('ZUVI_GOOGLE_MAPS_API_KEY', '...');
```

Als die niet is ingesteld, gebruikt de plugin als fallback de Google Maps API-key uit ACF. Voor productie is een aparte key met een server-IP-restrictie veiliger dan een browser/referrer-key.

## Ontbrekende verkooppunten

Een verkooppunt dat niet meer in een volgende import voorkomt, wordt standaard **niet** gewijzigd. In de importpreview kan optioneel worden gekozen om eerder door de importer beheerde, ontbrekende verkooppunten op concept te zetten.

## Versies

### 0.1.1

- Importfunctie geïntegreerd in de bestaande CPT-lijstpagina.
- Knop **Importeren** naast **Nieuw toevoegen**.
- Bestaande `layout`-parameter van de adminlijst wordt behouden.
- Geen apart submenu-item meer onder Zuivel.
- Google server-key kan via `ZUVI_GOOGLE_MAPS_API_KEY` worden ingesteld.
- Verouderde titel-lookup vervangen door `WP_Query`/`get_posts`.

### 0.1.0

- Eerste importer voor CSV/XLSX.
- Preview voor import.
- Matching en geocoding.
