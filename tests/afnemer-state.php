<?php
const ABSPATH = __DIR__;
require __DIR__ . '/../includes/class-bzv-afnemer-state.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function rejects(callable $callback, string $message) { try { $callback(); } catch (RuntimeException $e) { return; } throw new RuntimeException($message); }
$now = strtotime('2026-11-03T12:00:00Z'); $nextId = 0;
$uuid = static function () use (&$nextId) { return sprintf('10000000-0000-0000-0000-%012d', ++$nextId); };
$sourceId = 'source:' . hash('sha256', 'Nieuw bedrijf');
$locationId = 'location:20000000-0000-0000-0000-000000000001';
function directory($customers, $generation = '639293040000000001', $age = 0) {
    $at = $GLOBALS['now'] - $age;
    $body = ['generation' => $generation, 'publishedAt' => gmdate('c', $at), 'assessedOn' => gmdate('Y-m-d', $at),
        'eTag' => '"' . hash('sha256', json_encode($customers) . $generation) . '"', 'customers' => $customers];
    return BZV_Afnemer_State::validate(json_encode($body), $GLOBALS['now']);
}
$customer = ['customerId' => $sourceId, 'aliases' => [], 'name' => 'Nieuw bedrijf', 'active' => true, 'identityConfirmed' => false,
    'address' => '', 'postalCode' => '', 'city' => '', 'countryCode' => 'NL'];
$state = BZV_Afnemer_State::merge(BZV_Afnemer_State::empty(), directory([$customer]), $uuid);
check(count($state['records']) === 1, 'Qualified unlinked candidate becomes one private review.');
check(BZV_Afnemer_State::public_locations($state, $now) === [], 'Unreviewed customers never reach the public map.');
$id = array_key_first($state['records']);
$notified = BZV_Afnemer_State::queue_notices($state, ['a@example.test', 'b@example.test'], $now, $uuid);
check(count($notified['jobs']) === 2, 'One initial mail per administrator.');
$again = BZV_Afnemer_State::queue_notices($notified, ['a@example.test', 'b@example.test'], $now + 60, $uuid);
check(count($again['jobs']) === 2, 'Repeated synchronization queues no duplicate notice.');
$fields = ['name' => 'Mijn publieke naam', 'address' => 'Dorpsstraat 1', 'postalCode' => '1234 AB', 'city' => 'Testdorp', 'countryCode' => 'NL'];
$point = ['latitude' => 52.0, 'longitude' => 4.0, 'placeId' => 'example-place', 'coordinateSource' => 'google', 'locatedAt' => $now];
$state = BZV_Afnemer_State::approve($notified, $id, $notified['records'][$id]['revision'], $fields, $point, 17, $now, true);
check(count(BZV_Afnemer_State::public_locations($state, $now)) === 1, 'Explicit review publishes one active location.');
rejects(fn() => BZV_Afnemer_State::approve($state, $id, 1, $fields, $point, 17, $now, true), 'Stale form must fail.');
$linked = array_replace($customer, ['customerId' => $locationId, 'aliases' => [$sourceId], 'identityConfirmed' => true,
    'name' => 'Changed upstream name', 'address' => 'Upstream correction']);
$state = BZV_Afnemer_State::merge($state, directory([$linked], '639293040000000002'), $uuid);
check(count($state['records']) === 1 && array_key_first($state['records']) === $id, 'Linking preserves the stable review URL.');
check($state['records'][$id]['name'] === 'Mijn publieke naam' && $state['records'][$id]['address'] === 'Dorpsstraat 1', 'BOP must never overwrite reviewed public fields.');
check($state['records'][$id]['approvedAt'] === $now, 'Linking keeps the same approval.');
check(count(BZV_Afnemer_State::queue_notices($state, ['a@example.test'], $now, $uuid)['jobs']) === 2, 'Linking cannot queue another review.');
$inactive = array_replace($linked, ['active' => false]);
$state = BZV_Afnemer_State::merge($state, directory([$inactive], '639293040000000003'), $uuid);
check(BZV_Afnemer_State::public_locations($state, $now) === [] && $state['records'][$id]['approvedAt'] === $now, 'Archiving hides without erasing approval.');
rejects(fn() => BZV_Afnemer_State::approve($state, $id, $state['records'][$id]['revision'], $fields, $point, 17, $now, true), 'Old mail must not approve archived customer.');
$state = BZV_Afnemer_State::merge($state, directory([$linked], '639293040000000004'), $uuid);
check(count(BZV_Afnemer_State::public_locations($state, $now)) === 1, 'Returning activity reuses reviewed location.');
check(BZV_Afnemer_State::public_locations($state, $now + 48 * 3600) === [], 'Expired status never leaves stale public markers.');
$expired = BZV_Afnemer_State::expire($state, $now + 28 * 86400);
check($expired['records'][$id]['latitude'] === null && $expired['records'][$id]['approvedAt'] === null, 'Expired Google coordinates are physically deleted.');
check($expired['records'][$id]['placeId'] === 'example-place', 'Place ID remains available for refresh.');
$empty = BZV_Afnemer_State::merge($state, directory([], '639293040000000005'), $uuid);
check(BZV_Afnemer_State::public_locations($empty, $now) === [], 'A valid empty upstream snapshot hides all records.');
rejects(fn() => directory([$customer, $customer]), 'Duplicate identity must reject whole snapshot.');
rejects(fn() => directory([array_replace($customer, ['active' => 'true'])]), 'Boolean strings must not be accepted.');
rejects(fn() => directory([array_replace($linked, ['aliases' => [$sourceId, $sourceId]])]), 'Duplicate aliases must fail.');
rejects(fn() => directory([$customer], '639293040000000006', 48 * 3600), 'Expired snapshots must fail validation.');
rejects(fn() => BZV_Afnemer_State::merge($state, directory([$customer], '639293040000000001'), $uuid), 'Old generations must not roll activity back.');
// Two previously separate reviews are never silently merged into an approved marker.
$two = array_replace($customer, ['customerId' => 'source:' . hash('sha256', 'Other'), 'name' => 'Other']);
$base = BZV_Afnemer_State::merge(BZV_Afnemer_State::empty(), directory([$customer, $two]), $uuid);
$merged = array_replace($linked, ['aliases' => [$sourceId, $two['customerId']]]);
$conflict = BZV_Afnemer_State::merge($base, directory([$merged], '639293040000000002'), $uuid);
check(count(array_filter($conflict['records'], fn($r) => $r['active'] && $r['identityConflict'])) === 1, 'Merging separate identities requires deliberate review.');
// Bulk onboarding is bundled, not one mail for each of the 32 existing customers.
$many = [];
for ($i = 0; $i < 32; $i++) $many[] = array_replace($customer, ['customerId' => 'source:' . hash('sha256', (string) $i), 'name' => 'Klant ' . $i]);
$bulk = BZV_Afnemer_State::merge(BZV_Afnemer_State::empty(), directory($many), $uuid);
$bulk = BZV_Afnemer_State::queue_notices($bulk, ['a@example.test', 'b@example.test'], $now, $uuid);
check(count($bulk['jobs']) === 2 && count(reset($bulk['jobs'])['items']) === 32, 'Initial backlog is one compact mail per recipient.');
echo "Afnemer state tests passed: full snapshots, review ownership, identity linking, archive/return, expiry, revision checks, privacy and mail deduplication.\n";
