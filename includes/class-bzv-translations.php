<?php

if (!defined('ABSPATH')) {
    exit;
}

final class BZV_Translations
{
    private const NESTED_FORMS_TEXT_DOMAIN = 'gp-nested-forms';

    public static function init(): void
    {
        // WordPress 6.7+ expects custom translations to be registered at init or later.
        add_action('init', [self::class, 'load_nested_forms_dutch_translations'], 0);
    }

    public static function load_nested_forms_dutch_translations(): void
    {
        $locale = determine_locale();

        if (!in_array($locale, ['nl_NL', 'nl_BE'], true)) {
            return;
        }

        $translation_file = BZV_PLUGIN_DIR . 'languages/gp-nested-forms-nl_NL.mo';

        if (!is_readable($translation_file)) {
            return;
        }

        load_textdomain(self::NESTED_FORMS_TEXT_DOMAIN, $translation_file, $locale);
    }
}
