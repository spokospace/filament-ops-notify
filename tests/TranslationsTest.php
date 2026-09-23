<?php

use Filament\Facades\Filament;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Channels\Telegram\TelegramFormatter;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Locales;

function langFile(string $locale): array
{
    return require __DIR__."/../resources/lang/{$locale}/ops-notify.php";
}

function locales(): array
{
    return array_map('basename', glob(__DIR__.'/../resources/lang/*', GLOB_ONLYDIR));
}

it('ships the popular locales', function () {
    expect(locales())->toContain(
        'en', 'pl', 'de', 'fr', 'es', 'it', 'nl', 'pt_BR', 'uk',
        'cs', 'sk', 'hr', 'sl', 'bg', 'el', 'hu', 'ro', 'lt', 'lv', 'et', 'sv', 'da', 'fi', 'nb', 'tr',
    );
});

it('names every shipped locale in its own language', function () {
    $options = Locales::options();

    expect(array_keys($options))->toEqualCanonicalizing(locales());

    foreach ($options as $locale => $name) {
        expect($name)->not->toBe($locale, "{$locale} has no native name in Locales");
    }
});

it('has every English key in every locale, and no extra ones', function (string $locale) {
    $english = array_keys(Arr::dot(langFile('en')));
    $translated = array_keys(Arr::dot(langFile($locale)));

    expect(array_values(array_diff($english, $translated)))->toBe([], "{$locale} is missing keys")
        ->and(array_values(array_diff($translated, $english)))->toBe([], "{$locale} has unknown keys");
})->with(fn () => locales());

it('keeps every placeholder of the English string', function (string $locale) {
    $translated = Arr::dot(langFile($locale));

    foreach (Arr::dot(langFile('en')) as $key => $english) {
        preg_match_all('/:[a-z_]+/', $english, $placeholders);

        foreach (array_unique($placeholders[0]) as $placeholder) {
            // toContain() is variadic, so a failure message would be read as another needle.
            expect(str_contains($translated[$key], $placeholder))->toBeTrue("{$locale}.{$key} lost {$placeholder}");
        }

        expect(trim($translated[$key]))->not->toBe('', "{$locale}.{$key} is empty");
    }
})->with(fn () => locales());

it('renders the page in the app locale', function () {
    app()->setLocale('pl');
    Filament::setCurrentPanel('admin');
    $this->actingAs($this->admin());
    Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['username' => 'shop_bot']])]);

    // The page name is a product name and stays the same in every locale.
    Livewire::test(OpsNotifyPage::class)
        ->assertSee('Spoko DashBot')
        ->assertSee('Połączono jako @shop_bot')
        ->assertSee('Wyślij test')
        ->assertSee('Nie wysłano jeszcze żadnych powiadomień');
});

it('writes messages in the chosen message language, not the viewer\'s', function () {
    config(['ops-notify.locale' => 'de']);
    app()->setLocale('pl');
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $message = OpsMessage::make('x');
    foreach (range(1, 6) as $i) {
        $message->field("F{$i}", str_repeat('x', 400));
    }
    $message->sendNow();

    Http::assertSent(fn ($request) => str_contains($request['text'], 'und 3 weitere Felder'));
    expect(app()->getLocale())->toBe('pl');
});

it('builds the test message in the message language', function () {
    config(['ops-notify.locale' => 'pl']);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $this->artisan('ops-notify:test')->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request['text'], 'Powiadomienie testowe')
        && str_contains($request['text'], 'Jeśli to czytasz, powiadomienia działają.'));
});

it('uses the plural rules of the locale in messages', function (int $extra, string $expected) {
    app()->setLocale('pl');
    $message = OpsMessage::make('x');

    // Values are cut to 300 characters, so each field takes ~310 of the 1200-character fields
    // budget: three fit, the rest are summarised.
    foreach (range(1, 3 + $extra) as $i) {
        $message->field("Pole {$i}", str_repeat('x', 400));
    }

    expect((new TelegramFormatter)->format($message, null))->toContain($expected);
})->with([
    [1, 'i jeszcze 1 pole'],
    [3, 'i jeszcze 3 pola'],
    [5, 'i jeszcze 5 pól'],
]);
