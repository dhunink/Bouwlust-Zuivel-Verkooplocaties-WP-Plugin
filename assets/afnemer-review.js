/* global BZVReview, google */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  $('bzv-filter')?.addEventListener('input', (event) => {
    const needle = event.target.value.toLocaleLowerCase('nl');
    document.querySelectorAll('.bzv-row').forEach((row) => { row.hidden = !row.dataset.filter.includes(needle); });
  });
  async function post(action, body) {
    const response = await fetch(BZVReview.rest + action, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': BZVReview.nonce }, body: JSON.stringify(body),
    });
    const result = await response.json();
    if (!response.ok) throw new Error(result.message || 'Opslaan is niet gelukt. Je invoer blijft staan.');
    return result;
  }
  document.querySelectorAll('.bzv-retry-mail').forEach((button) => {
    button.addEventListener('click', async () => {
      button.disabled = true;
      try { await post('retry-mail', { id: button.dataset.job }); button.textContent = 'Klaar voor de volgende verzending'; }
      catch (error) { button.disabled = false; button.textContent = error.message; }
    });
  });
  if (!$('bzv-record')) return;
  const record = JSON.parse($('bzv-record').textContent);
  let map, marker, token = '', lookupSequence = 0, pending = false, dirty = false;
  let selected = Number.isFinite(record.latitude) && Number.isFinite(record.longitude) ? record : null;
  const fields = () => Object.fromEntries(['name', 'address', 'postalCode', 'city', 'countryCode'].map((key) => [key, $('bzv-' + key).value.trim()]));
  const message = (target, text, error = false) => { $(target).textContent = text; $(target).dataset.error = String(error); };
  function show_position(position) {
    selected = position;
    const point = { lat: position.latitude, lng: position.longitude };
    if (map) {
      map.setCenter(point); map.setZoom(17);
      if (!marker) marker = new google.maps.marker.AdvancedMarkerElement({ map, position: point, title: record.name });
      else marker.position = point;
    }
    $('bzv-coordinates').textContent = 'Kaartpositie: ' + position.latitude.toFixed(6) + ', ' + position.longitude.toFixed(6);
  }
  for (const key of ['name', 'address', 'postalCode', 'city', 'countryCode']) {
    $('bzv-' + key).addEventListener('input', () => {
      dirty = true; $('bzv-confirm').checked = false;
      if (key !== 'name') { token = ''; selected = null; lookupSequence++; pending = false; message('bzv-lookup-status', 'Adres gewijzigd. Zoek de kaartpositie opnieuw op.'); }
    });
  }
  function use_result(item, fromPlace = false) {
    token = item.token; show_position(item); $('bzv-confirm').checked = false;
    const choices = $('bzv-address-choices'); choices.replaceChildren();
    if (item.addresses?.length) {
      const label = document.createElement('label'); label.htmlFor = 'bzv-address-select'; label.textContent = 'Adresvoorstellen bij deze positie';
      const select = document.createElement('select'); select.id = 'bzv-address-select';
      select.add(new Option('Kies een adres of behoud je eigen invoer', ''));
      item.addresses.forEach((a, index) => select.add(new Option(a.address + ', ' + a.postalCode + ' ' + a.city, String(index))));
      const fill = (index) => {
        const a = item.addresses[index]; if (!a) return;
        for (const key of ['address', 'postalCode', 'city', 'countryCode']) $('bzv-' + key).value = a[key];
        $('bzv-confirm').checked = false; dirty = true;
      };
      select.addEventListener('change', () => { if (select.value !== '') fill(Number(select.value)); });
      choices.append(label, select);
      const normalize = (text) => text.toLocaleLowerCase('nl').replace(/\s+/g, '');
      const match = item.addresses.findIndex((a) => normalize(a.address) === normalize($('bzv-address').value) && normalize(a.postalCode) === normalize($('bzv-postalCode').value));
      if (match >= 0) { select.value = String(match); }
      else if (fromPlace || !$('bzv-address').value.trim() || !$('bzv-postalCode').value.trim() || !$('bzv-city').value.trim()) { select.value = '0'; fill(0); }
    }
    message('bzv-lookup-status', 'Voorstel gevonden. Controleer de vestiging, het huisnummer en de marker op de kaart.' + (item.approximate ? ' Google geeft een benaderde positie.' : ''));
  }
  async function lookup(placeId = '') {
    const sequence = ++lookupSequence; pending = true; token = ''; selected = null; $('bzv-confirm').checked = false;
    message('bzv-lookup-status', 'Kaartpositie en adresvoorstellen opzoeken…');
    try {
      const result = await post('lookup', { id: record.id, revision: record.revision, placeId, fields: fields() });
      if (sequence !== lookupSequence) return;
      use_result(result.items[0], placeId !== '');
      if (result.items.length > 1) {
        const list = document.createElement('div');
        result.items.forEach((item, index) => {
          const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'Kaartpositie ' + (index + 1);
          button.addEventListener('click', () => use_result(item, placeId !== '')); list.append(button);
        });
        $('bzv-address-choices').prepend(list);
      }
    } catch (error) { if (sequence === lookupSequence) message('bzv-lookup-status', error.message, true); }
    finally { if (sequence === lookupSequence) pending = false; }
  }
  $('bzv-geocode').addEventListener('click', () => lookup());
  async function save(approve) {
    if (!$('bzv-review-form').reportValidity()) return;
    if (pending || !selected) { message('bzv-save-status', 'Wacht op een locatievoorstel of zoek eerst de kaartpositie op.', true); return; }
    if (approve && !$('bzv-confirm').checked) { message('bzv-save-status', 'Bevestig eerst dat vestiging, adres en kaartpositie kloppen.', true); $('bzv-confirm').focus(); return; }
    const buttons = [...document.querySelectorAll('.bzv-actions button')]; buttons.forEach((b) => { b.disabled = true; });
    message('bzv-save-status', 'Opslaan…');
    try {
      const result = await post('save', { id: record.id, revision: record.revision, fields: fields(), token, approve, confirmed: $('bzv-confirm').checked });
      dirty = false; location.assign(result.url);
    } catch (error) { message('bzv-save-status', error.message, true); buttons.forEach((b) => { b.disabled = false; }); }
  }
  $('bzv-review-form').addEventListener('submit', (event) => { event.preventDefault(); save(true); });
  $('bzv-draft').addEventListener('click', () => save(false));
  $('bzv-withdraw')?.addEventListener('click', async () => {
    try { const result = await post('withdraw', { id: record.id, revision: record.revision }); dirty = false; location.assign(result.url); }
    catch (error) { message('bzv-save-status', error.message, true); }
  });
  window.addEventListener('beforeunload', (event) => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
  async function init_google() {
    if (!BZVReview.googleReady) { message('bzv-lookup-status', 'Vul eerst de Google-instellingen in bij het afnemersoverzicht.', true); return; }
    if (!window.google?.maps?.importLibrary) {
      await new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const callback = 'bzvGoogleReady'; window[callback] = resolve;
        const params = new URLSearchParams({ key: BZVReview.browserKey, v: 'quarterly', loading: 'async', language: 'nl', region: 'NL', callback });
        script.src = 'https://maps.googleapis.com/maps/api/js?' + params;
        script.onerror = () => reject(new Error('Google Maps kon niet worden geladen. Je invoer blijft staan.'));
        document.head.append(script);
      });
    }
    const [{ Map }] = await Promise.all([google.maps.importLibrary('maps'), google.maps.importLibrary('marker'), google.maps.importLibrary('places')]);
    map = new Map($('bzv-map'), { center: { lat: 51.955, lng: 4.288 }, zoom: 10, mapId: BZVReview.mapId, streetViewControl: false, mapTypeControl: false, clickableIcons: false });
    if (selected) show_position(selected);
    const search = document.createElement('gmp-place-search'); search.setAttribute('selectable', '');
    const request = document.createElement('gmp-place-text-search-request'); request.setAttribute('max-result-count', '3');
    search.append(document.createElement('gmp-place-all-content'), request); $('bzv-suggestions').append(search);
    search.addEventListener('gmp-select', (event) => { if (event.place?.id) lookup(event.place.id); });
    search.addEventListener('gmp-load', () => {
      const places = search.places || [];
      if (!places.length) { message('bzv-lookup-status', 'Geen bedrijf gevonden. Verfijn de zoeknaam of vul het adres in.'); return; }
      if (!selected && !pending) lookup(places[0].id);
      else message('bzv-lookup-status', 'Controleer de bewaarde locatie of kies een ander voorstel.');
    });
    search.addEventListener('gmp-error', () => message('bzv-lookup-status', 'De locatiesuggesties konden niet worden geladen. Probeer opnieuw of zoek op het adres.', true));
    const run_search = () => {
      if (!$('bzv-search').value.trim()) return;
      request.locationBias = { center: { lat: 51.955, lng: 4.288 }, radius: 50000 };
      request.textQuery = $('bzv-search').value.trim();
    };
    $('bzv-search-button').addEventListener('click', run_search);
    $('bzv-search').addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run_search(); } });
    run_search();
  }
  init_google().catch((error) => message('bzv-lookup-status', error.message, true));
})();
