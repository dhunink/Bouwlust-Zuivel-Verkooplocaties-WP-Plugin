<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_ACF_Bridge {
    private const CPT = 'zuivel_verkooppunt';
    private const MANAGED_META = '_zuivel_import_managed';

    private static bool $syncing = false;
    private static array $field_cache = [];

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'repair_managed_records'], 30);
        add_action('added_post_meta', [__CLASS__, 'mirror_source_meta'], 10, 4);
        add_action('updated_post_meta', [__CLASS__, 'mirror_source_meta'], 10, 4);
    }

    public static function repair_managed_records(): void {
        if (!function_exists('update_field')) {
            return;
        }

        $ids = get_posts([
            'post_type' => self::CPT,
            'post_status' => ['publish', 'draft'],
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => self::MANAGED_META,
            'meta_value' => '1',
        ]);

        foreach ($ids as $post_id) {
            self::sync_logical_field((int) $post_id, 'address');
            self::sync_logical_field((int) $post_id, 'postal_code');
        }
    }

    public static function mirror_source_meta($meta_id, $post_id, $meta_key, $_meta_value): void {
        if (self::$syncing || get_post_type((int) $post_id) !== self::CPT) {
            return;
        }

        if ($meta_key === 'address') {
            self::sync_logical_field((int) $post_id, 'address');
        } elseif ($meta_key === 'postal_code') {
            self::sync_logical_field((int) $post_id, 'postal_code');
        }
    }

    private static function sync_logical_field(int $post_id, string $logical): void {
        if (!function_exists('update_field')) {
            return;
        }

        $source_key = $logical;
        $value = get_post_meta($post_id, $source_key, true);
        if ($value === '' || $value === null) {
            return;
        }

        $field = self::resolve_field($logical);
        if (!$field || empty($field['key'])) {
            return;
        }

        $current = function_exists('get_field') ? get_field($field['key'], $post_id, false) : null;
        if ((string) $current === (string) $value) {
            return;
        }

        self::$syncing = true;
        try {
            update_field($field['key'], $value, $post_id);
        } finally {
            self::$syncing = false;
        }
    }

    private static function resolve_field(string $logical): ?array {
        if (array_key_exists($logical, self::$field_cache)) {
            return self::$field_cache[$logical];
        }

        if (!function_exists('acf_get_field_groups') || !function_exists('acf_get_fields')) {
            self::$field_cache[$logical] = null;
            return null;
        }

        $aliases = [
            'address' => ['address', 'adress'],
            'postal_code' => ['postal_code', '_postal_code', '__postal_code'],
        ];
        $labels = [
            'address' => ['straat en huisnummer', 'straat + huisnummer', 'straat + huisnr.'],
            'postal_code' => ['postcode'],
        ];

        $groups = acf_get_field_groups(['post_type' => self::CPT]);
        if (!$groups) {
            $groups = acf_get_field_groups();
        }

        foreach ($groups as $group) {
            $fields = acf_get_fields($group['key']);
            if (!$fields) {
                continue;
            }

            foreach ($fields as $field) {
                $name = (string) ($field['name'] ?? '');
                $label = self::normalize((string) ($field['label'] ?? ''));

                if (in_array($name, $aliases[$logical] ?? [], true)
                    || in_array($label, $labels[$logical] ?? [], true)) {
                    self::$field_cache[$logical] = $field;
                    return $field;
                }
            }
        }

        self::$field_cache[$logical] = null;
        return null;
    }

    private static function normalize(string $value): string {
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/\s+/u', ' ', $value);
        return $value;
    }
}
