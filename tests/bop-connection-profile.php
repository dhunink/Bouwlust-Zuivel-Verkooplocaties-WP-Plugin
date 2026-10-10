<?php
namespace GC_API_Alchemist\Connection_Profiles {
    final class Connection_Profile {
        public static array $profiles = [];
        public function __construct(public string $url, public string $token, public string $status = 'private', public string $auth = 'bearer') {}
        public static function get(int $id): ?self { return self::$profiles[$id] ?? null; }
        public function get_status(): string { return $this->status; }
        public function get_auth_type(): string { return $this->auth; }
        public function get_base_url(): string { return $this->url; }
        public function get_auth_config_value($key, $default = '') { return $this->token; }
    }
}
namespace {
    const ABSPATH = __DIR__;
    const HOUR_IN_SECONDS = 3600;
    function absint($value) { return abs((int) $value); }
    function get_option($key, $default = false) { return $GLOBALS['profile_id'] ?? $default; }
    function wp_parse_url($url) { return parse_url($url); }
    require __DIR__ . '/../includes/class-bzv-bop-locations.php';
    function check($ok, $message) { if (!$ok) { throw new \RuntimeException($message); } }
    $method = new \ReflectionMethod(\BZV_Bop_Locations::class, 'config');
    $profile_id = 0;
    check($method->invoke(null) === ['', ''], 'No implicit connection selection.');
    $profile_id = 42;
    $token = 'bop_post_v1.wordpress-staging.' . str_repeat('a', 64);
    $profile = new \GC_API_Alchemist\Connection_Profiles\Connection_Profile('https://postoffice.example.test/', $token);
    \GC_API_Alchemist\Connection_Profiles\Connection_Profile::$profiles[42] = $profile;
    check($method->invoke(null) === ['https://postoffice.example.test', $token], 'Selected existing profile supplies server-side credentials.');
    foreach (['http://example.test', 'https://user:password@example.test', 'https://example.test?key=x', 'https://example.test#fragment'] as $url) {
        $profile->url = $url;
        check($method->invoke(null) === ['', ''], 'Refuse an unsafe endpoint.');
    }
    $profile->url = 'https://postoffice.example.test';
    $profile->status = 'trash';
    check($method->invoke(null) === ['', ''], 'Trashed profile must stop authentication.');
    $profile->status = 'private'; $profile->auth = 'oauth2';
    check($method->invoke(null) === ['', ''], 'Unrelated credentials must not be reused.');
    $profile->auth = 'bearer'; $profile->token = "bad\r\nheader";
    check($method->invoke(null) === ['', ''], 'Refuse malformed or unrelated bearer tokens.');
    define('BOP_CUSTOMER_LOCATIONS_URL', 'https://explicit.example.test');
    check($method->invoke(null) === ['https://explicit.example.test', ''], 'Partial explicit configuration must not mix in another credential.');
    echo "BOP connection-profile tests passed.\n";
}
