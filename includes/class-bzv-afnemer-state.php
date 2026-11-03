<?php
/** Pure directory/review rules: no network, mail or implicit publication. */
if (!defined('ABSPATH')) { exit; }

final class BZV_Afnemer_State {
    public const MAX_AGE = 48 * 3600;
    public const GOOGLE_AGE = 28 * 86400;
    public static function empty(): array {
        return ['schema' => 1, 'directory' => null, 'records' => [], 'identities' => [], 'notices' => [], 'jobs' => []];
    }
    public static function id($id): bool {
        return is_string($id) && preg_match('/\A(?:location:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|source:[0-9a-f]{64})\z/', $id) === 1 &&
            $id !== 'location:00000000-0000-0000-0000-000000000000';
    }
    public static function text($text, int $maximum, bool $optional = false): bool {
        return is_string($text) && ($optional || trim($text) !== '') && trim($text) === $text &&
            !preg_match('/[\x00-\x1f\x7f]/', $text) && preg_match('//u', $text) === 1 && preg_match_all('/./us', $text) <= $maximum;
    }
    public static function generation(string $a, string $b): int { return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }
    public static function fresh(array $state, int $now): bool {
        return isset($state['directory']['publishedTimestamp']) && $state['directory']['publishedTimestamp'] > $now - self::MAX_AGE;
    }
    public static function validate(string $body, int $now): array {
        $d = json_decode($body, true, 32, JSON_BIGINT_AS_STRING);
        if (!is_array($d) || !isset($d['generation'], $d['publishedAt'], $d['assessedOn'], $d['customers'], $d['eTag']) ||
            !(is_string($d['generation']) || is_int($d['generation'])) ||
            !preg_match('/\A[1-9][0-9]{0,18}\z/', (string) $d['generation']) ||
            self::generation((string) $d['generation'], '9223372036854775807') > 0 ||
            !is_string($d['publishedAt']) || !preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?(?:Z|[+-]\d{2}:\d{2})\z/', $d['publishedAt']) ||
            !is_string($d['eTag']) || !preg_match('/\A"[a-f0-9]{64}"\z/', $d['eTag']) ||
            !is_array($d['customers']) || !array_is_list($d['customers']) || count($d['customers']) > 500) {
            throw new RuntimeException('Postkantoor gaf geen geldige afnemerslijst terug.');
        }
        $published = strtotime($d['publishedAt']);
        if ($published === false || $published <= $now - self::MAX_AGE || $published > $now + 600 ||
            $d['assessedOn'] !== (new DateTimeImmutable('@' . $published))->setTimezone(new DateTimeZone('Europe/Amsterdam'))->format('Y-m-d')) {
            throw new RuntimeException('De afnemerslijst is verouderd of heeft een ongeldige peildatum.');
        }
        $ids = []; $customers = [];
        foreach ($d['customers'] as $c) {
            if (!is_array($c) || !self::id($c['customerId'] ?? null) || isset($ids[$c['customerId']]) ||
                !is_bool($c['active'] ?? null) || !is_bool($c['identityConfirmed'] ?? null) ||
                $c['identityConfirmed'] !== str_starts_with($c['customerId'], 'location:') ||
                !is_array($c['aliases'] ?? null) || !array_is_list($c['aliases']) || count($c['aliases']) > 100) {
                throw new RuntimeException('De afnemerslijst bevat onduidelijke identiteiten.');
            }
            $ids[$c['customerId']] = true;
            foreach ($c['aliases'] as $alias) {
                if (!self::id($alias) || !str_starts_with($alias, 'source:') || isset($ids[$alias])) {
                    throw new RuntimeException('De afnemerslijst bevat dubbele koppelingen.');
                }
                $ids[$alias] = true;
            }
            foreach (['name' => 300, 'address' => 300, 'postalCode' => 20, 'city' => 160, 'countryCode' => 2] as $key => $max) {
                if (!self::text($c[$key] ?? null, $max, !in_array($key, ['name', 'countryCode'], true))) {
                    throw new RuntimeException('De afnemerslijst bevat ongeldige tekst.');
                }
            }
            if (!preg_match('/\A[A-Z]{2}\z/', $c['countryCode'])) { throw new RuntimeException('Ongeldige landcode.'); }
            $c = array_intersect_key($c, array_flip(['customerId', 'aliases', 'name', 'active', 'identityConfirmed', 'address', 'postalCode', 'city', 'countryCode']));
            sort($c['aliases'], SORT_STRING); $customers[] = $c;
        }
        usort($customers, static fn($a, $b) => strcmp($a['customerId'], $b['customerId']));
        return ['generation' => (string) $d['generation'], 'publishedAt' => $d['publishedAt'], 'publishedTimestamp' => $published,
            'assessedOn' => $d['assessedOn'], 'eTag' => $d['eTag'], 'customers' => $customers];
    }
    /** Whole snapshot replaces activity; local review fields never come from BOP. */
    public static function merge(array $state, array $directory, callable $uuid): array {
        $old = $state['directory'];
        if ($old) {
            if (self::generation($directory['generation'], $old['generation']) < 0) { throw new RuntimeException('Een oudere lijst is genegeerd.'); }
            if ($directory['generation'] === $old['generation'] && $directory !== $old) { throw new RuntimeException('Tegenstrijdige versies ontvangen.'); }
        }
        $previouslyActive = array_map(static fn($r) => $r['active'], $state['records']);
        foreach ($state['records'] as &$r) { $r['active'] = false; } unset($r);
        $claimed = []; $nextIdentities = [];
        foreach ($directory['customers'] as $c) {
            $keys = [$c['customerId'], ...$c['aliases']];
            $matches = array_values(array_unique(array_filter(array_map(static fn($key) => $state['identities'][$key] ?? null, $keys))));
            // If a source is split/reassigned, never apply one approval to two locations.
            $available = array_values(array_filter($matches, static fn($id) => !isset($claimed[$id])));
            $id = $available[0] ?? $uuid();
            $conflict = count($matches) > 1 || count($available) !== count($matches);
            if (!isset($state['records'][$id])) {
                $state['records'][$id] = ['id' => $id, 'name' => $c['name'], 'address' => $c['address'], 'postalCode' => $c['postalCode'],
                    'city' => $c['city'], 'countryCode' => $c['countryCode'], 'latitude' => null, 'longitude' => null, 'placeId' => '',
                    'coordinateSource' => '', 'locatedAt' => null, 'approvedAt' => null, 'approvedBy' => null,
                    'revision' => 1, 'reviewVersion' => 1, 'identityConflict' => false, 'active' => false, 'deferred' => false,
                    'source' => null, 'sourceHash' => '', 'history' => []];
            }
            $r = &$state['records'][$id];
            $sourceHash = hash('sha256', json_encode($c));
            if ($r['sourceHash'] !== $sourceHash) { $r['revision']++; }
            if ($conflict && !$r['identityConflict']) {
                $r['approvedAt'] = null; $r['approvedBy'] = null; $r['reviewVersion']++; $r['deferred'] = false;
            }
            $r['identityConflict'] = $conflict;
            $r['source'] = $c; $r['sourceHash'] = $sourceHash; $r['active'] = $c['active'];
            $claimed[$id] = true;
            foreach ($keys as $key) { $nextIdentities[$key] = $id; }
            unset($r);
        }
        foreach ($state['records'] as $id => &$r) {
            if (!isset($claimed[$id]) && !empty($previouslyActive[$id])) { $r['revision']++; }
        } unset($r);
        // Retain historical mappings only if they cannot steal an identity in the current complete snapshot.
        $state['identities'] = $nextIdentities + $state['identities'];
        $state['directory'] = $directory;
        return $state;
    }
    public static function coordinates(array $r, int $now): bool {
        return is_numeric($r['latitude']) && is_numeric($r['longitude']) && is_finite((float) $r['latitude']) &&
            is_finite((float) $r['longitude']) && abs((float) $r['latitude']) <= 90 && abs((float) $r['longitude']) <= 180 &&
            ($r['coordinateSource'] !== 'google' || (int) $r['locatedAt'] > $now - self::GOOGLE_AGE);
    }
    public static function public_locations(array $state, int $now): array {
        if (!self::fresh($state, $now)) { return []; }
        $items = [];
        foreach ($state['records'] as $r) {
            if (!$r['active'] || !$r['approvedAt'] || $r['identityConflict'] || !self::coordinates($r, $now)) { continue; }
            $items[] = ['locationId' => $r['id'], 'name' => $r['name'], 'address' => $r['address'], 'postalCode' => $r['postalCode'],
                'city' => $r['city'], 'countryCode' => $r['countryCode'], 'latitude' => $r['latitude'], 'longitude' => $r['longitude']];
        }
        usort($items, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $items;
    }
    public static function expire(array $state, int $now): array {
        foreach ($state['records'] as &$r) {
            if ($r['coordinateSource'] === 'google' && $r['locatedAt'] && $r['locatedAt'] <= $now - self::GOOGLE_AGE) {
                $r['latitude'] = $r['longitude'] = null; $r['locatedAt'] = null; $r['revision']++;
                if ($r['approvedAt']) { $r['reviewVersion']++; }
                $r['approvedAt'] = null; $r['approvedBy'] = null; $r['deferred'] = false;
            }
        } unset($r);
        return $state;
    }
    public static function approve(array $state, string $id, int $revision, array $fields, array $location, int $user, int $now, bool $approve): array {
        if (!isset($state['records'][$id]) || $state['records'][$id]['revision'] !== $revision) { throw new RuntimeException('Deze afnemer is ondertussen gewijzigd. Laad de actuele gegevens opnieuw.'); }
        if (!self::fresh($state, $now) || !$state['records'][$id]['active']) { throw new RuntimeException('Deze afnemer is niet meer actief of de BOP-lijst moet eerst worden vernieuwd.'); }
        foreach (['name' => 300, 'address' => 300, 'postalCode' => 20, 'city' => 160, 'countryCode' => 2] as $key => $max) {
            if (!self::text($fields[$key] ?? null, $max)) { throw new RuntimeException('Vul naam, straat, postcode, plaats en land volledig in.'); }
        }
        if (!preg_match('/\A[A-Z]{2}\z/', $fields['countryCode'])) { throw new RuntimeException('Gebruik een geldige landcode, bijvoorbeeld NL.'); }
        $r = &$state['records'][$id];
        $candidate = array_merge($r, $location);
        if (!self::coordinates($candidate, $now)) { throw new RuntimeException('Zoek eerst de kaartpositie op.'); }
        foreach (['name', 'address', 'postalCode', 'city', 'countryCode'] as $key) { $r[$key] = $fields[$key]; }
        foreach (['latitude', 'longitude', 'placeId', 'locatedAt', 'coordinateSource'] as $key) { $r[$key] = $candidate[$key]; }
        $r['approvedAt'] = $approve ? $now : null; $r['approvedBy'] = $approve ? $user : null;
        $r['deferred'] = !$approve; $r['identityConflict'] = false; $r['revision']++;
        $r['history'][] = ['at' => $now, 'user' => $user, 'action' => $approve ? 'approved' : 'saved-draft'];
        $r['history'] = array_slice($r['history'], -20);
        return $state;
    }
    public static function queue_notices(array $state, array $recipients, int $now, callable $uuid): array {
        if (!self::fresh($state, $now)) { return $state; }
        foreach (array_unique($recipients) as $recipient) {
            $items = [];
            foreach ($state['records'] as $r) {
                if (!$r['active'] || $r['approvedAt'] || $r['deferred']) { continue; }
                $key = hash('sha256', $r['id'] . ':' . $r['reviewVersion'] . ':' . strtolower($recipient));
                if (isset($state['notices'][$key])) { continue; }
                $items[] = ['id' => $r['id'], 'version' => $r['reviewVersion'], 'key' => $key];
                $state['notices'][$key] = ['at' => $now, 'state' => 'queued'];
            }
            if ($items) {
                $job = $uuid(); $state['jobs'][$job] = ['id' => $job, 'recipient' => $recipient, 'items' => $items,
                    'state' => 'queued', 'attempts' => 0, 'nextAttempt' => $now, 'createdAt' => $now];
            }
        }
        return $state;
    }
}
