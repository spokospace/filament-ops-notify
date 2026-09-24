<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Channels\Telegram\TelegramFormatter;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Models\OpsNotifyLog;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Settings\SettingsStore;
use Spokospace\OpsNotify\Support\MessageTemplate;

function inquiry(): OpsMessage
{
    return OpsMessage::make('inquiry.created')
        ->title('New inquiry')
        ->line('Hello')
        ->field('Name', 'Anna')
        ->field('Email', 'a@b.pl')
        ->field('Phone', '123');
}

function formatWith(array $templates, OpsMessage $message, ?string $service = 'panel'): string
{
    return (new TelegramFormatter)->format($message, $service, template: MessageTemplate::for($templates, $message));
}

it('keeps the default layout for events no template matches', function () {
    $default = (new TelegramFormatter)->format(inquiry(), 'panel');

    expect(formatWith(['build.*' => ['title' => 'Build']], inquiry()))->toBe($default)
        ->and(formatWith([], inquiry()))->toBe($default)
        ->and($default)->toBe("ℹ️ <b>[panel] New inquiry</b>\n\nHello\n\n<b>Name:</b> Anna\n<b>Email:</b> a@b.pl\n<b>Phone:</b> 123\n\n#inquiry_created");
});

it('renders the parts of the first matching template', function () {
    $text = formatWith([
        'inquiry.*' => [
            'title' => 'New inquiry from :field.Name',
            'body' => ":body\n\nCall back within 1h",
            'fields' => ['Email', 'Name'],
            'hashtag' => false,
            'service' => false,
        ],
        'inquiry.created' => ['title' => 'Never used'],
    ], inquiry());

    expect($text)->toBe("ℹ️ <b>New inquiry from Anna</b>\n\nHello\n\nCall back within 1h\n\n<b>Email:</b> a@b.pl\n<b>Name:</b> Anna");
});

it('can drop the body and every field', function () {
    expect(formatWith(['inquiry.*' => ['body' => false, 'fields' => []]], inquiry()))
        ->toBe("ℹ️ <b>[panel] New inquiry</b>\n\n#inquiry_created");
});

it('fills every placeholder, a missing field as empty text', function () {
    $text = formatWith(['inquiry.*' => [
        'title' => ':title (:level) :field.Missing',
        'body' => ':event on :service for :field.{Name} :field.email :titles',
    ]], inquiry()->warning());

    expect($text)
        ->toContain('<b>[panel] New inquiry (warning)</b>')
        // Labels match ignoring case; ":titles" is text, not a placeholder.
        ->toContain('inquiry.created on panel for Anna a@b.pl :titles');
});

it('falls back to the message title when a template title comes out empty', function () {
    expect(formatWith(['inquiry.*' => ['title' => ':field.Missing']], inquiry()))->toContain('<b>[panel] New inquiry</b>');
});

it('does not fill in placeholders found in values', function () {
    $message = OpsMessage::make('x')->title(':body')->line('secret')->field('Name', ':title');

    expect(formatWith(['x' => ['title' => ':field.Name / :title']], $message))->toContain('<b>[panel] :title / :body</b>');
});

it('escapes html in template text and in placeholder values', function () {
    $message = OpsMessage::make('x')->title('<script>')->line('a & b')->field('Name', '<i>Anna</i>');

    $text = formatWith(['x' => [
        'title' => '<b>:title</b> &amp; :field.Name',
        'body' => '<code>:body</code>',
    ]], $message);

    expect($text)
        ->toContain('<b>[panel] &lt;b&gt;&lt;script&gt;&lt;/b&gt; &amp;amp; &lt;i&gt;Anna&lt;/i&gt;</b>')
        ->toContain('&lt;code&gt;a &amp; b&lt;/code&gt;')
        ->not->toContain('<script>')
        ->not->toContain('<i>')
        ->not->toContain('<code>');
});

it('renders a template into the formatter\'s length budgets', function () {
    $message = OpsMessage::make('x')->title(str_repeat('T', 500))->line(str_repeat('b', 5000));

    foreach (range(1, 20) as $i) {
        $message->field("Field {$i}", str_repeat('v', 400));
    }

    $text = formatWith(['x' => [
        'title' => 'Alert: :title :title',
        'body' => str_repeat('fixed ', 100).":body\n:body\n:field.{Field 1}",
    ]], $message);

    $visible = html_entity_decode(strip_tags($text));
    preg_match('/<b>(.*?)<\/b>/', $text, $title);

    expect(mb_strlen($visible))->toBeLessThanOrEqual(4096)
        ->and(mb_strlen(html_entity_decode($title[1])))->toBeLessThanOrEqual(200 + mb_strlen('[panel] '))
        ->and($text)->toContain('Alert: ')
        ->and($text)->toContain(trim(str_repeat('fixed ', 100)))
        ->and($text)->toMatch('/and \d+ more fields/');
});

it('writes package strings in the message locale', function () {
    config(['ops-notify.locale' => 'pl']);
    $message = OpsMessage::make('x')->line(str_repeat('b', 3000));

    foreach (range(1, 40) as $i) {
        $message->field("Field {$i}", str_repeat('v', 400));
    }

    $text = app(OpsNotifier::class)->inMessageLocale(fn () => formatWith(['x' => ['title' => 'Alert']], $message, null));

    expect($text)->toMatch('/i jeszcze \d+ p/')->toContain('<b>Alert</b>');
});

it('ignores a template of the wrong shape', function () {
    $default = (new TelegramFormatter)->format(inquiry(), 'panel');

    expect(formatWith(['inquiry.*' => 'nonsense'], inquiry()))->toBe($default)
        ->and(formatWith(['inquiry.*' => ['title' => 5, 'body' => [], 'fields' => 'Name']], inquiry()))->toBe($default);
});

it('never applies an event template to a message sent without one', function () {
    config(['ops-notify.templates' => ['x' => ['title' => 'Templated']]]);
    $message = OpsMessage::make('x')->title('Plain')->withoutTemplate();

    expect(MessageTemplate::for(config('ops-notify.templates'), $message))->toBeNull()
        ->and(OpsMessage::fromArray($message->toArray())->templated)->toBeFalse()
        ->and(OpsMessage::fromArray(OpsMessage::make('x')->toArray())->templated)->toBeTrue();
});

it('applies templates saved in the panel, and a config value locks them', function () {
    config(['ops-notify.channels.telegram.bot_token' => null, 'ops-notify.channels.telegram.chat_id' => null]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    $store = new SettingsStore;
    app()->instance(SettingsStore::class, $store);
    $store->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1', 'templates' => ['x' => ['title' => 'From panel']]]);

    OpsMessage::make('x')->title('Hello')->sendNow();
    Http::assertSent(fn (Request $request) => str_contains($request['text'], '<b>[') && str_contains($request['text'], 'From panel</b>'));

    config(['ops-notify.templates' => ['x' => ['title' => 'From config']]]);
    $locked = new SettingsStore;

    expect($locked->isLocked('templates'))->toBeTrue()
        ->and($locked->isLocked('events'))->toBeFalse();
});

/** The Settings slide-over with the Telegram connection set in config. */
function templateSettings(): mixed
{
    config(['ops-notify.channels.telegram.bot_token' => null, 'ops-notify.channels.telegram.chat_id' => null]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['status' => 'creator', 'username' => 'bot']])]);
    Filament::setCurrentPanel('admin');
    test()->actingAs(test()->admin());

    return Livewire::test(OpsNotifyPage::class)->mountAction('settings');
}

it('saves templates from the settings page in config shape', function () {
    templateSettings()
        ->fillForm([
            'telegram_bot_token' => '999:PANEL',
            'telegram_chat_id' => '-1',
            'templates' => [
                'a' => ['pattern' => ' inquiry.* ', 'title' => 'From :field.Name', 'body' => "Line\r\nTwo", 'show_body' => true, 'fields' => ['Name', ' '], 'show_fields' => true, 'hashtag' => false, 'service' => true],
                'b' => ['pattern' => 'debug.*', 'title' => '', 'body' => 'ignored', 'show_body' => false, 'fields' => ['Name'], 'show_fields' => false, 'hashtag' => true, 'service' => false],
            ],
        ], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(app(SettingsStore::class)->formValues()['templates'])->toBe([
        'inquiry.*' => ['title' => 'From :field.Name', 'body' => "Line\nTwo", 'fields' => ['Name'], 'hashtag' => false],
        'debug.*' => ['body' => false, 'fields' => [], 'service' => false],
    ]);
});

it('previews a template against the latest message of a matching event', function () {
    OpsNotifyLog::query()->create([
        'channel' => 'telegram',
        'event' => 'inquiry.created',
        'title' => 'New inquiry',
        'level' => 'info',
        'payload' => inquiry()->toArray(),
        'status' => DeliveryStatus::Sent,
    ]);

    $form = templateSettings()->fillForm(['templates' => [
        'a' => ['pattern' => 'inquiry.*', 'title' => 'First'],
        'b' => ['pattern' => 'inquiry.created', 'title' => '<b>From :field.Name</b>', 'show_body' => false, 'fields' => [], 'show_fields' => true, 'hashtag' => true, 'service' => true],
        'c' => ['pattern' => 'nothing.*'],
    ]], 'mountedActionSchema0');

    $form->mountAction(TestAction::make('previewTemplate')->schemaComponent('templates.templates', 'mountedActionSchema0')->arguments(['item' => 'b']))
        ->assertMountedActionModalSeeHtml('&lt;b&gt;From Anna&lt;/b&gt;')
        // The first row matches inquiry.created too, so the chat gets that one.
        ->assertMountedActionModalSee('The template inquiry.* above matches inquiry.created first')
        ->assertMountedActionModalDontSee('Hello');
});
