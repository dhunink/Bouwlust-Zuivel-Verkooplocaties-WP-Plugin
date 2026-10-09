import copy
import importlib.util
from pathlib import Path
spec = importlib.util.spec_from_file_location('prepare', Path(__file__).parents[1] / 'tools/prepare-pro-map.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
marker = {'_type': 'map-marker', 'looper_consumer': True, 'map_marker_lat': 'old'}
farm = {'_type': 'map-marker', 'map_marker_lat': '51.9', 'map_marker_lng': '4.2', 'map_marker_content': 'Farm'}
map_element = {'_type': 'map', 'looper_provider_type': 'query-builder', 'looper_provider_query_count': '100', '_modules': [marker, farm]}
section = {'_type': 'section', 'id': 'verkooppunten', 'show_condition': [{'value':'administrator'}], '_modules': [{'_type': 'layout-column', '_modules': [map_element]}]}
other = {'_type': 'section', 'id': 'unrelated', '_modules': []}
doc = {'id':11088, 'type':'content', 'data':{'elements':{'_type':'root', '_modules':[{'_type':'region', '_modules':[other,section]}]}}}
before = copy.deepcopy(doc)
result = module.prepare(doc)
assert doc == before, 'Do not mutate input/backup'
assert result['elements'][0] == other, 'Do not modify other sections'
updated = result['elements'][1]
assert updated['show_condition'] == section['show_condition'], 'Keep current visibility'
updated_map = updated['_modules'][0]['_modules'][0]
assert updated_map['_modules'][1] == farm, 'Keep static farm marker'
assert updated_map['looper_provider_type'] == 'bop-afnemers'
assert 'looper_provider_query_count' not in updated_map
assert updated_map['_modules'][0]['map_marker_lat'] == '{{dc:looper:field key="latitude"}}'
assert updated['_modules'][0]['_modules'][1]['text_content'] == '[bop_afnemers]'
doc['data']['elements']['_modules'][0]['_modules'] = result['elements']
assert module.prepare(doc) == result, 'Preparation must be idempotent'
print('Pro document preparation passed: structure, visibility, static marker, other content and repeatability.')
