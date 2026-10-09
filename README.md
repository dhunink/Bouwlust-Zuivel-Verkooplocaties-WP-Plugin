# Bouwlust Zuivel Verkooplocaties – WordPress Plugin

WordPress-plugin voor de gecontroleerde BOP-afnemerslijst op hoevebouwlust.nl. De nieuwe kaart haalt haar gegevens uitsluitend uit BOP Postkantoor. De eerdere importer blijft beschikbaar als archiefgereedschap en voedt deze kaart niet.

Daarnaast bevat de plugin de kleine, websitebrede aanpassingen die specifiek bij Hoeve Bouwlust horen. Vanaf versie 0.2.1 omvat dit de Nederlandse frontendvertalingen voor Gravity Perks Nested Forms.

## BOP-koppeling (0.3.0)

De plugin haalt `/v1/customer-locations` elke 15 minuten server-side op en bewaart één
volledig gecontroleerde snapshot in een duurzame WordPress-option (geen transient).
Een geldige lege lijst wist alle afnemermarkers. Een fout bewaart de vorige snapshot
zolang de BOP-publicatie jonger is dan 48 uur. Na die grens worden de opgeslagen locaties
verwijderd en verschijnt een tijdelijke beschikbaarheidsmelding. Een HTTP 304 verlengt
de BOP-publicatietijd niet. Gelijktijdige synchronisaties gebruiken een database-lock.

Plaats uitsluitend op de server, buiten deze repository, de twee configuratieconstanten:

```php
define('BOP_CUSTOMER_LOCATIONS_URL', 'https://<postkantoor-host>');
define('BOP_CUSTOMER_LOCATIONS_TOKEN', '<websitecredential met customer-locations:read>');
```

De URL bevat geen `/v1`-pad. Gebruik HTTPS en een reader-credential; de plugin volgt geen
redirects met de credential. De browser krijgt geen token, interne klantnamen of orders.
**Gereedschap → BOP afnemers** toont status, laatste publicatie en de knop **Lijst nu ophalen**.
Dat scherm vereist beheerrechten en de knop een WordPress-nonce. Er worden geen e-mails verstuurd.

### Pro Map

In de bestaande Map op pagina 11088 kies je Looper Provider **BOP afnemers**. De provider
is geregistreerd via Themeco's [Looper API](https://theme.co/docs/looper-api). Laat één
Map Marker alle resultaten consumeren en gebruik:

| Pro-veld | Waarde |
| --- | --- |
| Latitude | `{{dc:looper:field key="latitude"}}` |
| Longitude | `{{dc:looper:field key="longitude"}}` |
| Content | `{{dc:looper:field key="popupHtml"}}` |

`popupHtml` bevat uitsluitend escaped naam, adres en een veilige Google Maps-link.
De vaste Hoeve Bouwlust-marker blijft een afzonderlijk element. Plaats `[bop_afnemers]`
in een Text-element onder de kaart voor dezelfde lijst als leesbare adressen.
Gebruik voor de kaart een Maps JavaScript-key met website/referrerrestricties; Google
Geocoding gebeurt uitsluitend in BOP met een afzonderlijke serversleutel.

`tools/prepare-pro-map.py` bereidt op basis van een actuele native Pro-export de exacte
wijziging voor. Het script verstuurt niets. Het bewaart overige pagina-inhoud, de vaste
boerderijmarker en de bestaande zichtbaarheid voor alleen beheerders. De native Pro
`document-update`-route verzorgt bij toepassing validatie en de eigen opslag/cachehooks.
Lees de pagina vlak vóór toepassen opnieuw, vergelijk met de bron en bewaar een privébackup.
Publiceer het onderdeel pas na controle met werkelijke goedgekeurde BOP-locaties.
De publieksintroductie moet over **Hier vind je** en afnemers spreken; de oude tekst die
alle afnemers als winkels/verkooppunten aanduidt hoort niet bij de definitieve publicatie.

### Cache en planning

De kaartpagina (standaard 11088, wijzigbaar via `bzv_bop_locations_page_ids`) is uitgesloten
van volledige WP Rocket-paginacache. De plugin vernieuwt de Rocket-configuratie eenmalig
per pluginversie en wist de betrokken pagina bij wijzigingen/verloop. De snapshotcache
zorgt dat paginaweergave zelf geen Postkantoorverzoek nodig heeft. Een upstream CDN/proxy
moet de `no-cache`-headers respecteren; controleer dat bij uitrol. Redis mag de duurzame
option versnellen, maar is niet de enige opslag van de laatst geldige lijst.

WP-Cron wordt door bezoeken gestart. Configureer een echte server-cron voor vaste intervallen
zonder bezoek. De weergave controleert de 48-uursgrens ook zelf en toont nooit bewust een
verlopen snapshot. De HTML-opslag/cache van de pagina moet deze grens eveneens respecteren.

### Testen en uitrollen

- `php tests/bop-locations.php`: validatie, gehele vervanging, geldige lege lijst, 304,
  fouten, generatievolgorde, verval, pagina-cache, HTML-escaping en synchronisatielock.
- `python3 tests/pro-map.py`: native Pro-structuur, behoud overige inhoud/zichtbaarheid/
  boerderijmarker en herhaalbaarheid.
- Installeer ZIP met mapnaam `bwlst-zuivel-verkooplocaties` om de bestaande plugin bij te werken.
  De Nederlandse GravityWiz-vertalingen blijven onderdeel van hetzelfde pakket.
- Rol eerst BOP/Postkantoor uit; configureer hun Google- en Firestore-vervalinstellingen.
  Installeer dan deze plugin, stel de reader-verbinding in, haal de lijst op en controleer
  de Pro-kaart terwijl zij nog alleen voor beheerders zichtbaar is.

## Eerdere importopzet (archief)

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

## Nederlandse GravityWiz-teksten

Voor een Nederlandstalige website (`nl_NL` of `nl_BE`) laadt de plugin een eigen gettextcatalogus voor het textdomain `gp-nested-forms`. De vertaling staat onder `languages/` en blijft daardoor buiten de map van Gravity Perks; updates van GP Nested Forms overschrijven haar niet.

De catalogus vertaalt de frontendteksten voor toevoegen, een lege producttabel, annuleren, bewerken, verwijderen, dupliceren en de relevante invoer- en validatiemeldingen. De loader wordt vanaf WordPress `init` geactiveerd en doet voor andere talen niets.

## Versies

### 0.3.0

- BOP Postkantoor als bron voor gecontroleerde afnemers.
- Pro-provider, adreslijst, beheerscherm en veilige snapshot-/cacheverversing.
- Oude importgegevens zijn geen bron voor de BOP-kaart.

### 0.2.2

- Exacte frontendbronstrings van GP Nested Forms 1.2.33 verwerkt.
- Ontbrekende vertalingen voor de lege tabel, annuleren en overige invoeracties toegevoegd.

### 0.2.1

- Nederlandse frontendvertalingen toegevoegd voor Gravity Perks Nested Forms.
- Vertalingen updatebestendig ondergebracht in de bestaande Bouwlust-plugin.

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
