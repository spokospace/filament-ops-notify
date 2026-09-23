<?php

namespace Spokospace\OpsNotify\Support;

/** The locales the package ships translations for, named in their own language. */
final class Locales
{
    private const NATIVE_NAMES = [
        'de' => 'Deutsch',
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'it' => 'Italiano',
        'nl' => 'Nederlands',
        'pl' => 'Polski',
        'pt_BR' => 'Português (Brasil)',
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
