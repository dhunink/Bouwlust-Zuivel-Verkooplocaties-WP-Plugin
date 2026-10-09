#!/usr/bin/env python3
"""Prepare a native Pro document-update payload. Never sends it or changes WordPress.
Input: GET /themeco/data/document/11088 (decoded document or compressed {data:...}).
Output: POST /themeco/data/document-update payload. Review and re-read source before applying.
Keeps the existing admin-only condition and the permanent Hoeve Bouwlust marker.
"""
import argparse
import base64
import copy
import json
from pathlib import Path
import zlib


def nodes(value):
    if isinstance(value, dict):
        yield value
        for child in value.values():
            yield from nodes(child)
    elif isinstance(value, list):
        for child in value:
            yield from nodes(child)


def prepare(document):
    data = copy.deepcopy(document)
    if isinstance(data.get('data'), str):
        data = json.loads(zlib.decompress(base64.b64decode(data['data'])))
    if data.get('id') != 11088 or data.get('type') != 'content':
        raise ValueError('Expected the existing Zuivel page (11088).')
    sections = [item for item in nodes(data['data']['elements']) if item.get('id') == 'verkooppunten']
    if len(sections) != 1:
        raise ValueError('Expected exactly one existing afnemers section.')
    section = sections[0]
    maps = [item for item in nodes(section) if item.get('_type') == 'map']
    if len(maps) != 1:
        raise ValueError('Expected exactly one existing Pro Map.')
    old_intro = 'Je koopt onze zuivel in de streekwinkel op Hoeve Bouwlust. In samenwerking met Heerlijk van Dichtbij vind je onze producten ook bij winkels in de regio. Bekijk de verkooppunten op de kaart.'
    new_intro = 'Je vindt onze zuivel bij Hoeve Bouwlust en bij afnemers in de regio. Bekijk hun locaties op de kaart of in de adreslijst hieronder.'
    for item in nodes(section):
        if item.get('text_content') == old_intro:
            item['text_content'] = new_intro
    target = maps[0]
    if target.get('looper_provider_type') not in ('query-builder', 'bop-afnemers'):
        raise ValueError('Map already uses an unexpected source; review manually.')
    markers = [item for item in target['_modules'] if item.get('looper_consumer')]
    if len(markers) != 1:
        raise ValueError('Expected exactly one repeated marker template.')
    for key in list(target):
        if key.startswith('looper_provider_query_'):
            del target[key]
    target['looper_provider_type'] = 'bop-afnemers'
    target['looper_provider'] = True
    marker = markers[0]
    marker['_label'] = 'Afnemer uit BOP'
    marker['looper_consumer'] = True
    marker['looper_consumer_repeat'] = 'all'
    marker['map_marker_lat'] = '{{dc:looper:field key="latitude"}}'
    marker['map_marker_lng'] = '{{dc:looper:field key="longitude"}}'
    marker['map_marker_content'] = '{{dc:looper:field key="popupHtml"}}'
    marker['map_marker_content_start'] = 'closed'
    # Append the accessible address list to the same column, not inside the map.
    parents = [item for item in nodes(section) if any(child is target for child in item.get('_modules', []))]
    if len(parents) != 1:
        raise ValueError('Cannot identify map column.')
    parent = parents[0]
    if not any(item.get('_label') == 'BOP afnemers adreslijst' for item in parent['_modules']):
        parent['_modules'].append({'_type': 'text', '_bp_base': '4_4', '_label': 'BOP afnemers adreslijst',
            'text_content': '[bop_afnemers]', 'class': 'bz-text bop-afnemers-addresses', 'text_margin': '24px 0 0', '_modules': []})
    # Native Content::update expects the modules array, not the serialized document wrapper.
    regions = data['data']['elements']['_modules']
    if len(regions) != 1 or regions[0].get('_type') != 'region':
        raise ValueError('Unexpected Pro content region; inspect before saving.')
    return {'id': data['id'], 'elements': regions[0]['_modules']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('source', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    result = prepare(json.loads(args.source.read_text()))
    # Treat the output as private because the original page may include browser API configuration.
    args.output.touch(mode=0o600, exist_ok=False)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + '\n')
    print('Prepared existing Pro map with BOP provider, repeated marker and address list. Visibility preserved.')

if __name__ == '__main__':
    main()
