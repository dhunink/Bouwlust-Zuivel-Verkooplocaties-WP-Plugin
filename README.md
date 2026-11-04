# Bouwlust Zuivel Verkooplocaties – WordPress Plugin

WordPress-plugin voor de gecontroleerde BOP-afnemerslijst op hoevebouwlust.nl. BOP Postkantoor bepaalt welke afnemers actief zijn; WordPress beheert de gecontroleerde openbare locatiegegevens. De eerdere importer blijft beschikbaar als archiefgereedschap en voedt deze kaart niet.

Daarnaast bevat de plugin de kleine, websitebrede aanpassingen die specifiek bij Hoeve Bouwlust horen. Vanaf versie 0.2.1 omvat dit de Nederlandse frontendvertalingen voor Gravity Perks Nested Forms.

## Afnemers en bestaande verkooppunt-posts (0.5.0)

**Gereedschap → BOP afnemers** is het controlescherm voor het bestaande posttype
`zuivel_verkooppunt`. Iedere ontvangen BOP-afnemer krijgt één blijvende CPT-post.
De CPT-lijst krijgt een kolom **BOP-controle** met status en een directe controlelink;
het controlescherm linkt terug naar de bijbehorende post.

- Nieuwe posts beginnen als concept. Expliciet goedkeuren schrijft de eigen naam,
  `address`, `postal_code`, `city` en het ACF Google Map-veld `location`, controleert
  de opgeslagen waarden en publiceert daarna de post.
- De bestaande Pro-provider `bop-afnemers` en `[bop_afnemers]` lezen voortaan deze
  gecontroleerde CPT/ACF-waarden. Ze tonen alleen gepubliceerde, actieve en
  goedgekeurde posts met een verse BOP-beoordeling en geldige kaartpositie.
- Goedkeuren, intrekken, archiveren of wijzigen ververst met `rocket_clean_files`
  de zuivelpagina (11088). Er is geen volledige websitecacheflush nodig. De
  bestaande uitsluiting van paginacache beschermt ook tegen verlopen gegevens.
- Een wijziging van naam, adres of kaartpositie via de gewone CPT-editor wordt
  overgenomen als voorstel en vereist opnieuw controle. Alleen op **Publiceren**
  klikken in die editor keurt de BOP-locatie niet goed. Een ongewijzigd opgeslagen
  locatieveld of wijziging van andere postinhoud behoudt de goedkeuring.
- Archiveren in BOP of intrekken in WordPress zet de post op concept. Terugkeer
  in BOP hergebruikt dezelfde post; een nog geldige eerdere goedkeuring blijft
  bruikbaar. Een post in de prullenbak wordt nooit automatisch hersteld.

Bij de eerste koppeling kan één bestaande importpost worden hergebruikt wanneer
de exacte oorspronkelijke naam overeenkomt met een BOP-bronidentiteit of alias.
Onduidelijke meervoudige matches stoppen met een zichtbare fout. De oude import
bepaalt nooit de activiteit. Eigen adresgegevens kunnen als voorstel dienen;
oude Google-coördinaten zonder bekende leeftijd worden verwijderd en gelden niet
als goedkeuring. Gekoppelde posts vallen daarna buiten de oude importer.

De koppeling wordt opgeslagen op review-UUID, met herstel bij gedeeltelijke
opslagfouten. ACF wordt via de bestaande veldsleutels geschreven, zodat ook de
native ACF-editor dezelfde velden ziet. Een mislukte veldschrijfopdracht laat de
post als concept staan en levert geen kaartpunt op. Direct gewijzigde of
verlopen waarden worden ook vóór de volgende cron-run niet door de provider getoond.

`php tests/afnemer-posts.php` controleert deze keten met lokale WordPress- en
ACF-dubbels, inclusief herhaalbaarheid, bronkoppelingen, native bewerkingen,
opslagfouten, verlopen coördinaten en de gerichte WP Rocket-verversing.

## Ontvangers kiezen (0.4.1)

Onder **Gereedschap → BOP afnemers → Instellingen en meldingen** kies je alle
WordPress-beheerders of vink je specifieke WordPress-gebruikers aan. Je kunt op
naam of e-mailadres zoeken. De eerdere keuze voor alle beheerders blijft behouden
totdat je zelf een selectie opslaat; nieuwe beheerders worden bij een vaste
gebruikersselectie niet automatisch toegevoegd.

De plugin bewaart gebruikers-ID's en gebruikt bij verzending het actuele
e-mailadres. Verwijderde gebruikers en ongeldige adressen vallen weg; dubbele
adressen krijgen één bericht. Een afgevinkte ontvanger krijgt ook geen nog niet
aangeboden melding uit de wachtrij. Een al aan de mailvoorziening overgedragen
bericht kan niet worden teruggehaald. Meldingen inschakelen met een lege selectie
wordt geweigerd; met meldingen uit mag de selectie leeg zijn.

Selectie geeft geen extra rechten. Het scherm vermeldt welke gebruikers locaties
kunnen controleren. De bestaande beheercontrole blijft gelden, ook voor ontvangers
zonder beheerrechten. Het oude vrije e-mailveld is vervangen; als die oude keuze
was opgeslagen, blijven meldingen uit totdat gebruikers bewust zijn geselecteerd.

Voor WPVibe/integraties zijn `GET /wp-json/bzv/v1/afnemers/recipient-options` en
`POST /wp-json/bzv/v1/afnemers/recipients` beschikbaar na installatie. Beide vereisen
de bestaande `manage_options`-bevoegdheid. POST neemt alleen `recipientMode`
(`administrators` of `users`) en `recipientUserIds` (array) over; Google-sleutels,
mailactivering en andere instellingen worden niet gelezen of aangepast via deze
route. De validatie is dezelfde als in het instellingenformulier.

## WordPress-controle (vanaf 0.4.0)

BOP bepaalt de activiteit uit leverdatums, historische pakbonnen én toekomstige
bestellingen. Postkantoor levert een private, volledige lijst via
`GET /v1/customer-directory`. WordPress geeft iedere afnemer een vaste controlelink.
Onder **Gereedschap → BOP afnemers** kan een beheerder de voorgestelde vestiging,
het adres en de kaartpositie controleren en bewust goedkeuren. Inloggen bij
WordPress volstaat; een VPN is niet nodig. Een klik op de mailknop publiceert niets.

- Bij openen zoekt Google Places UI Kit automatisch op de bedrijfsnaam. Er zijn
  maximaal drie voorstellen. Het eerste voorstel krijgt alvast een kaartpositie.
- Open adresgegevens komen van BOP, de beheerder of PDOK/BAG. Een nabijgelegen
  adres is slechts een voorstel: controleer vooral het huisnummer en eventuele
  toevoeging. Google-bedrijfsgegevens blijven in het Google-widget.
- De beheerder kan zoeken, het adres aanpassen, de positie opnieuw opzoeken,
  goedkeuren, als concept bewaren of een bestaande vermelding intrekken.
- Alleen actieve, goedgekeurde locaties verschijnen in de bestaande Pro-provider
  `bop-afnemers` en shortcode `[bop_afnemers]`. De oude importlijst voedt die niet.
- BOP-synchronisatie overschrijft nooit de door een beheerder gecontroleerde naam
  of adresgegevens. Expliciete klantkoppelingen uit BOP behouden de controlelink;
  een onduidelijke splitsing/samenvoeging vraagt opnieuw controle.
- Na 48 uur zonder verse BOP-publicatie verdwijnt de openbare lijst. Een 304
  verlengt die termijn niet. Archivering in BOP verbergt de locatie; een bestaande
  goedkeuring kan bij terugkeer behouden blijven als de positie nog actueel is.

### Meldingen en Google-instellingen

Alle actuele WordPress-gebruikers met de rol **administrator** zijn standaard
ontvanger, totdat een beheerder de hierboven beschreven gebruikersselectie kiest.
Dubbele e-mailadressen krijgen één bericht. Dennis heeft automatische
afnemercontroles expliciet uitgezonderd van zijn communicatiegrens. De eerste
import wordt gebundeld in één kort bericht per beheerder; nieuwe controles krijgen
later een nieuwe melding. Meldingen staan uit totdat de koppeling en Google klaar
zijn. Testen gebruiken uitsluitend lokale maildubbels.

Een geaccepteerde `wp_mail`-aanroep bewijst alleen overdracht aan de mailvoorziening,
niet bezorging in de inbox. Afgewezen overdrachten worden maximaal drie keer
geprobeerd, minstens vijftien minuten uit elkaar. Bij een onderbroken overdracht
verschijnt een beheermelding; alleen een bewuste actie probeert die opnieuw.

Gebruik twee **aparte WordPress-sleutels** in het bestaande Google-project:

1. Browser: Maps JavaScript API + Places UI Kit, HTTP-referrerbeperking tot
   `https://hoevebouwlust.nl/*` (en alleen indien gebruikt `https://www.hoevebouwlust.nl/*`).
2. Server: uitsluitend Geocoding API, beperkt tot de werkelijk geverifieerde
   uitgaande IP-adressen van de WordPress-host. De BOP-serverbeperking is hiervoor
   niet geschikt. Sleutels invoeren via het beveiligde instellingenformulier;
   geen sleutels in Git, mail, Pro-elementen of deze documentatie.
3. Bestaand Google map-ID kan worden hergebruikt. De serversleutel wordt nooit
   naar de browser gestuurd. De browserkey is openbaar maar referrerbeperkt.

Google-coördinaten worden uiterlijk na 28 dagen verwijderd, met ververspogingen
vanaf dag 27. Een gewijzigde positie trekt de goedkeuring in en vraagt opnieuw
controle. Place IDs en de eigen openbare gegevens blijven bewaard. Configureer een
echte cron om elke vijftien minuten due WordPress-events uit te voeren, ook zonder
bezoek. Bestaande backupretentie hoort bij het websitebeheer; backups zijn geen
bron voor de kaart.

De implementatie volgt de [Places UI Kit-documentatie](https://developers.google.com/maps/documentation/javascript/places-ui-kit/place-search)
en de [Geocoding EEA-richtlijnen](https://developers.google.com/maps/comms/eea/geocoding).
PDOK/BAG levert alleen onafhankelijke adresvoorstellen, geen bewijs dat een
onderneming op dat adres is gevestigd.

### Migratie en controle

1. Installeer de Postkantoor-release met scopes `customer-directory:publish` voor
   de bestaande BOP-client en `customer-directory:read` voor de WordPress-client.
   De bestaande sleutelprofielen blijven behouden.
2. Installeer de bijbehorende BOP-release via het releasecentrum. Zet
   `BopPostOffice__PublishCustomerDirectoryEnabled=true` en
   `CustomerLocations__ReviewInWordPress=true`; herstart volgens de releaseprocedure.
3. Installeer plugin 0.5.1 als update van `bwlst-zuivel-verkooplocaties`. De nieuwe
   route staat standaard uit; bestaande instellingen/vertalingen blijven behouden.
4. Kies het bestaande API Connections-profiel, vul de WordPress-Googlekeys in en
   activeer de WordPress-controle. Vernieuw de lijst en controleer een echte
   locatie via het scherm. Kies de ontvangers en activeer daarna meldingen.
5. Controleer de echte Pro-kaart met minstens één door een beheerder goedgekeurde
   locatie vóór het kaartonderdeel voor bezoekers wordt gepubliceerd. De bestaande
   Pro-markers en shortcode hoeven voor deze gegevenswissel niet aangepast te worden.

Aanvullende controles: `php tests/afnemer-state.php` en
`php tests/afnemer-workflow.php` testen identiteiten, goedkeuringen, verlopen data,
Google/PDOK-grenzen, beheerrechten, mailherkansingen en de bestaande Pro-uitvoer.
Bij rollback naar 0.3.1 eerst meldingen uitzetten; lokale WordPress-beoordelingen
blijven bewaard maar de oude versie leest uitsluitend de oude BOP-publicatie.
Daarom geen oude lege feed als succesvolle kaartmigratie presenteren.

## Eerdere BOP-koppeling (0.3.x; alleen wanneer WordPress-controle uitstaat)

De plugin haalt `/v1/customer-locations` elke 15 minuten server-side op en bewaart één
volledig gecontroleerde snapshot in een duurzame WordPress-option (geen transient).
Een geldige lege lijst wist alle afnemermarkers. Een fout bewaart de vorige snapshot
zolang de BOP-publicatie jonger is dan 48 uur. Na die grens worden de opgeslagen locaties
verwijderd en verschijnt een tijdelijke beschikbaarheidsmelding. Een HTTP 304 verlengt
de BOP-publicatietijd niet. Gelijktijdige synchronisaties gebruiken een database-lock.

Kies onder **Tools → BOP afnemers** het bestaande Postkantoor-profiel uit API Connections.
Alleen het profielnummer wordt opgeslagen; de bestaande websitecredential blijft in GC API
Alchemist en wordt uitsluitend op de server gelezen. Alleen gepubliceerde/private profielen
met HTTPS en een Postkantoor-bearertoken zijn selecteerbaar. Een profielwisseling wist de
oude snapshot. Gebruik een profiel met `customer-locations:read`.

Als alternatief kun je uitsluitend op de server, buiten deze repository, twee
configuratieconstanten plaatsen. Deze gaan voor op een geselecteerd profiel:

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
Gebruik voor de kaart een Maps JavaScript-key met website/referrerrestricties; De oude 0.3.x-route gebruikt Google
Geocoding in BOP; versie 0.4.0 verplaatst die controle naar WordPress.

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

### 0.5.1

- BOP-controlekolom blijft zichtbaar bij een bestaande Admin Columns Pro-weergave.

### 0.5.0

- Eén bestaande of nieuwe CPT-post per BOP-afnemer, met ACF-locatievelden.
- Controle, expliciete publicatie, Pro-kaart en gerichte WP Rocket-verversing gekoppeld.
- Native CPT-wijzigingen vragen hercontrole; archivering en verlopen punten verdwijnen.

### 0.4.1

- Ontvangers selecteren uit actuele WordPress-gebruikers, inclusief beperkte WPVibe-REST-routes.

### 0.4.0

- Locatievoorstellen, controle en publicatie in WordPress, zonder VPN.
- Automatische, gebundelde meldingen aan alle WordPress-beheerders.
- Private BOP-activiteitslijst, blijvende controlelinks en gecontroleerde Pro-markers.

### 0.3.1

- Hergebruik van een bestaand API Connections-profiel zonder sleutels te kopiëren.
- Beveiligde profielkeuze in het afnemersscherm, met eigen WordPress-nonce.

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
