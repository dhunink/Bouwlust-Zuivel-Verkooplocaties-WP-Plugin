<?php
if (!defined('ABSPATH')) { exit; }

/** Validate the private Postkantoor contract before touching any native posts. */
final class BZV_Directory {
    public const MAX_AGE = 48 * 3600;
    public static function id($id): bool {
        return is_string($id) && preg_match('/\A(?:location:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|source:[0-9a-f]{64})\z/', $id) === 1 &&
            $id !== 'location:00000000-0000-0000-0000-000000000000';
    }
    public static function text($text, int $maximum, bool $optional = false): bool {
        return is_string($text) && ($optional || trim($text) !== '') && trim($text) === $text &&
            !preg_match('/[\x00-\x1f\x7f]/', $text) && preg_match('//u', $text) === 1 && preg_match_all('/./us', $text) <= $maximum;
    }
    public static function generation(string $a, string $b): int { return strlen($a) <=> strlen($b) ?: strcmp($a, $b); }
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
}
