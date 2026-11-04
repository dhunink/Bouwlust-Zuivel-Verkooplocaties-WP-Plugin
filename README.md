# Bouwlust Zuivel Verkooplocaties — 0.6.0

BOP bepaalt wie afnemer is en of die actief is. Postkantoor levert de private,
volledige lijst. WordPress beheert de naam, het adres, het kaartpunt en publicatie.

## Gewone WordPress-werkwijze

Nieuwe afnemers worden concepten van het bestaande CPT `zuivel_verkooppunt`.
Open **Zuivel-verkooppunten**, controleer de naam en vul de bestaande ACF-velden
`address`, `postal_code`, `city` en `location` in. Zoek de juiste vestiging in het
ACF Google Map-veld en klik bewust op **Publiceren**. Concept opslaan publiceert
niets. De oude aparte controlepagina verwijst voortaan naar deze CPT-lijst.

De Google-sleutel wordt via de publieke getter van GravityWiz Address Autocomplete
hergebruikt. Sleutelbeheer blijft onder Gravity Forms → Instellingen → Autocomplete.
Er zijn geen aparte Google-instellingen, eigen kaartinterface of servergeocoder.
ACF verzorgt de zoekfunctie; beschikbare BOP-adressen worden bij de eerste import
vooringevuld. Als BOP geen adres kent, zoek en controleer je de vestiging zelf.

BOP-verversingen overschrijven de door een beheerder ingevulde naam, het adres en
kaartpunt niet. De bronidentiteit en expliciete BOP-aliassen houden de CPT-koppeling
stabiel. Er wordt niet op oude importnamen gegokt. Archivering of verdwijnen uit
BOP zet de post op concept; terugkeer vraagt opnieuw Publiceren. Een post in de
prullenbak wordt niet hersteld. Ongeldige of onduidelijke bronlijsten worden geweigerd.

## Kleine koppelinginstelling

Onder **Instellingen → BOP-koppeling** kies je het bestaande API Connections-profiel,
automatische import en de WordPress-gebruikers die meldingen ontvangen. Alleen hun
ID's worden bewaard; actuele e-mailadressen worden bij verzending opgehaald en
ontdubbeld. Selectie verleent geen extra rechten. Standaard blijven de bestaande
ontvangers geselecteerd; nieuwe beheerders worden niet stilzwijgend toegevoegd.

**Verbinding testen** leest de lijst zonder posts te maken. **Nu importeren** start
handmatig de import. Automatische import staat bij de upgrade uit, zodat de oude
lijst eerst bewust kan worden opgeruimd. Activering laat WP-Cron elke vijftien
minuten importeren. Zonder websitebezoek is daarvoor een echte server-cron nodig.

Een korte automatische mail verwijst naar de beveiligde native editor; bij meerdere
nieuwe afnemers wordt één bericht per ontvanger gebundeld met een link naar de
conceptenlijst. De maillink publiceert niets. Een melding wordt vóór overdracht
vastgelegd om dubbele verzending na onderbreking te voorkomen. Mislukte overdracht
wordt gemeld in de importstatus; bezorging controleer je in Gravity SMTP. Er is geen
eigen mailwachtrij of automatisch herstel van mislukte/onderbroken verzending.

## Kaart en cache

De bestaande Pro-provider `bop-afnemers` en shortcode `[bop_afnemers]` lezen uitsluitend
actieve, gepubliceerde CPT-posts met complete ACF-locatiegegevens en een actuele
BOP-generatie. Postkantoor hoeft bij paginaweergave niet te worden opgevraagd.
Een bronlijst ouder dan 48 uur levert geen publieke markers. Een netwerkfout bewaart
de vorige geldige lijst tot die grens. Een lege bronlijst archiveert eerdere koppelingen.

Native opslaan/publiceren, prullenbakacties en import verversen gericht de
WP Rocket-cache van de zuivelpagina (11088). Deze dynamische pagina is uitgesloten
van volledige Rocket-paginacache, zodat een verlopen bron geen oude markers toont.

Pro Map Marker gebruikt `{{dc:looper:field key="latitude"}}`,
`{{dc:looper:field key="longitude"}}` en `{{dc:looper:field key="popupHtml"}}`.
De popup ontsmet naam en adres. De vaste Hoeve Bouwlust-marker blijft apart.
`tools/prepare-pro-map.py` bereidt een native Pro-document voor zonder het te versturen.

## Installatie en controles

- BOP: `BopPostOffice__PublishCustomerDirectoryEnabled=true` en
  `CustomerLocations__ReviewInWordPress=true`.
- Postkantoor: `/v1/customer-directory`, publishscope voor BOP en readscope voor
  het bestaande WordPress-profiel. De bearer blijft uitsluitend in API Connections.
- Installeer als update met mapnaam `bwlst-zuivel-verkooplocaties`.
- Test de verbinding, verwijder de oude lijst naar de prullenbak, activeer de gewenste
  import/meldingen en importeer. Controleer daarna een echte vestiging bewust.
- `php tests/native-workflow.php`: contract, identiteiten, CPT/ACF, publiceren,
  cache/Pro, ontvangers, fouten, archivering en prullenbak met lokale dubbels.
- `python3 tests/pro-map.py`: native Pro-structuur en behoud van andere pagina-inhoud.

Versie 0.6.0 vervangt de eigen reviewopslag, reviewinterface, Google/PDOK-code,
CSV/XLSX-import en review-REST-routes door native WordPress en bestaande ACF-velden.
De oude opties worden niet gelezen als bron voor publicatie. Rollback naar een oude
plugin activeert niet automatisch dezelfde workflow; houd dan import/meldingen uit.

## Nederlandse GravityWiz-teksten

De bestaande gettextcatalogus onder `languages/` voor `gp-nested-forms` blijft
behouden voor `nl_NL` en `nl_BE`. Updates van Gravity Perks overschrijven deze niet.
