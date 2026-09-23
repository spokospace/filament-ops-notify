<?php

namespace Spokospace\OpsNotify\Support;

/** The locales the package ships translations for, named in their own language. */
final class Locales
{
    private const NATIVE_NAMES = [
        'bg' => 'Български',
        'cs' => 'Čeština',
        'da' => 'Dansk',
        'de' => 'Deutsch',
        'el' => 'Ελληνικά',
        'en' => 'English',
        'es' => 'Español',
        'et' => 'Eesti',
        'fi' => 'Suomi',
        'fr' => 'Français',
        'hr' => 'Hrvatski',
        'hu' => 'Magyar',
        'it' => 'Italiano',
        'lt' => 'Lietuvių',
        'lv' => 'Latviešu',
        'nb' => 'Norsk bokmål',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt_BR' => 'Português (Brasil)',
        'ro' => 'Română',
        'sk' => 'Slovenčina',
        'sl' => 'Slovenščina',
        'sv' => 'Svenska',
        'tr' => 'Türkçe',
        'uk' => 'Українська',
    ];

    /** @return array<string, string> locale => native name */
    public static function options(): array
    {
        $available = array_map('basename', glob(__DIR__.'/../../resources/lang/*', GLOB_ONLYDIR) ?: []);

        return collect($available)
            ->mapWithKeys(fn (string $locale): array => [$locale => self::NATIVE_NAMES[$locale] ?? $locale])
            ->sort()
            ->all();
    }
}
