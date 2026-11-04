# Bouwlust Zuivel Verkooplocaties — 0.7.1

BOP bepaalt de afnemers en hun activiteit. Postkantoor levert de volledige private
lijst van uitsluitend actieve afnemers. WordPress beheert de lokale locatie, kaartkeuze en bewuste publicatie.

## Standaard beheer

- **ACF Pro** beheert de optiespagina **Instellingen → BOP-koppeling**,
  het API Connections-profiel, automatische import, meldingen, de meervoudige
  WordPress-gebruikerskeuze en de pagina waarvan de cache moet worden ververst.
  Alleen beheerders met `manage_options` kunnen de instellingen wijzigen.
- **ACF Google Map** (`location`) beheert het volledige openbare adres en punt.
  Een bestaande ACF-sleutel blijft behouden; anders gebruikt de kleine adapter
  de bestaande sleutel uit GravityWiz Address Autocomplete.
- **ACF true_false** (`show_on_map`) beheert Ja/Nee voor de openbare kaart.
  Bij Nee blijft de afnemer in WordPress en is geocoderen niet verplicht.
- **ACF Date Picker** (`bop_inactive_since`) toont **Inactief sinds**, alleen-lezen.
  Admin Columns verzorgt de datumkolom, sortering en datumfilters.
- **ACF Message** toont BOP-status, website-status en een referentieadres alleen
  ter informatie. De actuele berekening blijft onderdeel van de BOP-koppeling.
- **Admin Columns Pro** beheert alle lijstkolommen, sortering en slimme filters.
  Het adres komt rechtstreeks uit het Maps-veld. BOP-status (`_bop_active`) is
  alleen-lezen; de ACF-kaartkeuze is met native inline-/bulkbewerking aanpasbaar.
- **Pro** beheert de popup en openbare adressenlijst met gewone Loopers en
  Dynamic Content. Er is geen eigen popup-HTML of `[bop_afnemers]`-shortcode meer.
- **WP Rocket** beheert de vaste cache-uitzondering en Always Purge-configuratie.
  De adapter ververst ook na imports en relevante metadata-/geldigheidswijzigingen.
- **WordPress** beheert CPT-posts, concepten, publiceren, prullenbak en cron.
  **Gravity SMTP** verzorgt de overdracht en logging van de automatische mails.

Nieuwe afnemers worden concepten van `zuivel_verkooppunt`. Controleer naam en
Google-locatie, kies de kaartzichtbaarheid en klik bewust op **Publiceren**.
Een concept wordt niet automatisch gepubliceerd. Voor kaartzichtbaarheid zijn
ook een actieve BOP-status, volledige recente bron en geldig kaartpunt nodig.

De oude straat/postcode/plaats/import-ID-definities staan in een uitgeschakelde
ACF-groep. De opgeslagen klantwaarden zijn niet verwijderd of gemigreerd. Het
import-ID staat niet meer in de editor; BOP gebruikt zijn beschermde bronmetadata.
De dubbele adreswaarden worden niet meer uit Google gekopieerd. Voor nieuwe
concepten blijft het oorspronkelijke BOP-adres beschikbaar als referentiemetadata.

Een bestaande ontbrekende `show_on_map`-waarde blijft voor de kaart standaard Ja.
Admin Columns toont een lege cel totdat de gebruiker een waarde opslaat. Dennis
heeft bulkinitialisatie van bestaande afnemers geweigerd: voer die niet uit.
Alleen bij eerste initialisatie van een nieuwe import wordt de ACF-default Ja
bewaard; een bestaande eigen Nee wordt nooit door de import overschreven.

## Specifieke koppellogica

De adapter valideert een volledige Postkantoor-snapshot vóór schrijven, bewaart
stabiele bronidentiteiten en expliciete aliassen, voorkomt overlappende imports
en dubbele posts, en bewaart lokale namen, locaties, zichtbaarheid en prullenbak.
Inactieve/verdwenen afnemers gaan terug naar concept en blijven bewaard; terugkeer
vraagt opnieuw publicatie. De inactiviteitsdatum is de Amsterdamse `assessedOn`
van de eerste geldige volledige lijst waarin een eerder actieve afnemer ontbreekt.
Een fout, een onvolledige lijst of verlopen gegevens zetten deze datum niet.
De eerste datum blijft staan zolang de afnemer inactief is en wordt bij terugkeer
leeggemaakt. De datum is dus de eerste bevestiging, geen achteraf geraden
historische besteldatum. Onbekende inactieve klanten uit een oudere feed worden
niet meer aangemaakt. Bestaande prullenbakposten worden niet hersteld of gedupliceerd. Een mislukte import publiceert geen onvolledige generatie.

De Pro-provider `bop-afnemers` levert uitsluitend toegestane afnemers, met
`postId`, ontsmette `name`/`address`-tekst en numerieke `latitude`/`longitude`.
De pagina bepaalt de opmaak en Maps-link. Een bron ouder dan 48 uur levert geen
markers; deze bestaande ontwerpkeuze is niet gewijzigd. Postkantoor hoeft niet
bij ieder websitebezoek te worden bevraagd.

Meldingen gaan alleen naar de expliciet gekozen gebruikers. Actuele adressen
worden bij verzending opgehaald en ontdubbeld. Een melding wordt vóór overdracht
vastgelegd om herhaling na onderbreking te voorkomen. Er is geen eigen
mailtransport of wachtrij. Een maillink opent de beveiligde native editor en
publiceert niets. Mislukte overdracht staat in de importstatus/Gravity SMTP.

De twee BOP-acties blijven kleine adapters: **Verbinding testen** leest alleen;
**Nu importeren** voert de synchronisatie uit. Ze gebruiken de opgeslagen
instellingen en een afzonderlijk nonce-beveiligd POST-formulier. Automatische
import gebruikt de bestaande WP-Cron-taak om de vijftien minuten.

## Configuratie en release

Native configuratie staat onder `tools/` als normale ACF-importbestanden:

1. Importeer `acf-settings-native-0.7.0.json` via ACF Tools en sla de bestaande
   profiel-/ontvanger-/importkeuzes en de kaartpagina op. Instellingen zijn niet
   langer afkomstig uit de oude `bzv_native_settings`-optie.
2. Configureer de kaart-URL bij WP Rocket Never Cache URL(s) en Always Purge URL(s).
3. Pas het bestaande Pro-document met `prepare-pro-map.py` aan. Dit behoudt andere
   paginaonderdelen en gebruikt eerst velden die ook 0.6.6 al aanbiedt.
4. Importeer eerst `acf-activity-date-0.7.1.json` via ACF Tools. Dit voegt uitsluitend
   het standaard datumveld toe; bestaande klantwaarden blijven ongemoeid.
   Installeer daarna de update met mapnaam `bwlst-zuivel-verkooplocaties`.
5. Importeer `acf-customer-native-0.7.1.json`: legacyvelddefinities gaan eerst naar
   een inactieve groep; de actieve groep houdt de Maps-locatie en kaartschakelaar.
   De nieuwe statusgroep bevat alleen Message-velden. Geen klantwaarden wijzigen.
6. Gebruik `admin-columns-native-0.7.1.json` voor de native Maps-adreskolom en
   de alleen-lezen datumkolom. De ACP-import maakt nieuwe weergaven; vervang oude
   weergaven alleen met toestemming en behoud de native export voor herstel.
7. Controleer de echte ACF-pagina, een bestaande editor, de publicatiekaart en
   verbinding. Voer geen publicatie of testmail uit namens een beheerder.

`prepare-native-config.php` bouwt de ACF-configuratie uit de gecontroleerde lokale
back-up van deze site. `acf-customer-fields-backup.json` is de veldconfiguratie voor
herstel; klantdata worden niet in deze bestanden opgenomen. Bewaar voor rollback
ook de eerdere plugin, instellingen, ACP-export en het Pro-document.

Controles: `php tests/native-workflow.php` en `python3 tests/pro-map.py`.
De PHP-suite gebruikt uitsluitend lokale HTTP-/maildubbels. Zij controleert
onder meer identiteit, archivering, publicatie, ontbrekende kaartkeuze, ACF-
instellingen, ontvangers, cache en ontsmette Pro-data.

De eenmalige opschoning van 4 november 2026 verplaatste 24 bestaande inactieve
concepten naar de WordPress-prullenbak. Er is geen periodieke verwijdering en
geen permanente verwijdering ingebouwd. Voor deze historische records wordt
geen inactiviteitsdatum verzonnen. Een later opnieuw actieve prullenbakpost
blijft in de prullenbak totdat een beheerder deze zelf herstelt.

## Bestaande vertalingen

De bestaande Nederlandse gettextcatalogus voor `gp-nested-forms` blijft behouden.
Geen huidige geïnstalleerde plugin is geverifieerd als standaardvervanging voor
precies deze vertalingen; verplaatsen naar Code Snippets zou nog steeds maatwerk
zijn. Dit is een afzonderlijk bestaand onderdeel, geen nieuwe BOP-functionaliteit.
