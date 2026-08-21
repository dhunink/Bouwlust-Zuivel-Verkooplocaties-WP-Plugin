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

Voor oudere data blijft de plugin compatibel met de eerdere veldnaam `adress` en enkele oude postcode-veldnamen.

## Importeren

De importer staat op de bestaande lijstpagina van **Zuivel-verkooppunten**. Naast **Nieuw toevoegen** verschijnt de knop **Importeren**.

De importer accepteert CSV en XLSX met minimaal de kolommen:

- `Klant`
- `Straat + huisnr.`
- `Postcode`
- `Plaats`

Optioneel kan een kolom `Import-ID` of `ID` worden aangeleverd. De importer zoekt in XLSX/CSV zelf naar de juiste kopregel, zodat titel- en informatieregels boven de tabel zijn toegestaan.

Voor de daadwerkelijke import wordt eerst een preview getoond met nieuwe, gewijzigde, ongewijzigde en onduidelijke records.

## Matching

Bestaande verkooppunten worden in deze volgorde gezocht:

1. Expliciete Import-ID uit het bronbestand.
2. Exacte combinatie adres + postcode.
3. Exacte klantnaam.

Nieuwe records zonder bron-ID krijgen automatisch een interne ID zoals `VP-000123`.

## Geocoding

Nieuwe verkooppunten en gewijzigde adressen worden server-side gegeocodeerd met Google Geocoding en opgeslagen in het ACF Google Map-veld `location`.

Gebruik voor productie een aparte Google API-key met alleen de **Geocoding API** en restricties voor de uitgaande IPv4- en IPv6-adressen van de webserver. De key kan via een filter buiten deze publieke repository worden ingesteld:

```php
add_filter('zuivel_import_google_api_key', function ($key) {
    return 'HIER_DE_SERVER_API_KEY';
});
```

Als deze filter niet is ingesteld, gebruikt de plugin als fallback `ZUVI_GOOGLE_MAPS_API_KEY` uit `wp-config.php` en daarna de Google Maps API-key uit ACF.

Vanaf versie 0.2.0 toont het importscherp hoeveel verkooppunten geen geldige kaartlocatie hebben. Via **Geocoding opnieuw proberen** worden alleen die records opnieuw aangeboden aan Google; het Excelbestand hoeft daarvoor niet opnieuw te worden geüpload.

## Ontbrekende verkooppunten

Een verkooppunt dat niet meer in een volgende import voorkomt, wordt standaard **niet** gewijzigd. In de importpreview kan optioneel worden gekozen om eerder door de importer beheerde, ontbrekende verkooppunten op concept te zetten.

## Importstatus

Vanaf versie 0.2.0 wordt bij een import opgeslagen:

- datum en tijd;
- bronbestandsnaam;
- aantal records;
- importresultaat.

Bij gedeeltelijke fouten wordt de resultaatmelding als waarschuwing weergegeven in plaats van als volledig geslaagde import.

## Versies

### 0.2.0

- Laatste importdatum, bronbestand en recordaantal zichtbaar in het importscherp.
- Status van ontbrekende Google Map-locaties zichtbaar.
- Knop **Geocoding opnieuw proberen** voor records zonder locatie.
- Importresultaat wordt oranje weergegeven bij gedeeltelijke fouten.
- Bestaande ACF-compatibiliteitslaag blijft oude veldnamen ondersteunen.

### 0.1.6

- ACF-compatibiliteitslaag toegevoegd voor de eerdere veldnaam `adress` en afwijkende postcode-veldnamen.
- Bestaande geïmporteerde records worden automatisch gerepareerd.

### 0.1.4

- XLSX-parser robuuster gemaakt voor namespaces, lege shared strings en werkbladen met voorloopregels.

### 0.1.2

- Importverwerking verplaatst naar `admin-post.php` voor robuustere adminverwerking en foutmeldingen.

### 0.1.1

- Importfunctie geïntegreerd in de bestaande CPT-lijstpagina.
- Knop **Importeren** naast **Nieuw toevoegen**.
- Bestaande `layout`-parameter van de adminlijst wordt behouden.
- Geen apart submenu-item meer onder Zuivel.

### 0.1.0

- Eerste importer voor CSV/XLSX.
- Preview voor import.
- Matching en geocoding.
