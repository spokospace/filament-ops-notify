<?php

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spokospace\OpsNotify\Channels\Telegram\TelegramFormatter;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
use Spokospace\OpsNotify\Filament\Pages\OpsNotifyPage;
use Spokospace\OpsNotify\Filament\SettingsForm;
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

/** inquiry() as the log keeps it, for a preview. */
function loggedInquiry(): OpsNotifyLog
{
    return OpsNotifyLog::query()->create([
        'channel' => 'telegram',
        'event' => 'inquiry.created',
        'title' => 'New inquiry',
        'level' => 'info',
        'payload' => inquiry()->toArray(),
        'status' => DeliveryStatus::Sent,
    ]);
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

    return settingsForm();
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
    loggedInquiry();

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

it('rejects two template rows with the same pattern, since only the upper one could apply', function () {
    templateSettings()
        ->fillForm([
            'telegram_bot_token' => '999:PANEL',
            'telegram_chat_id' => '-1',
            'templates' => [
                'a' => ['pattern' => 'inquiry.*', 'title' => 'Upper'],
                'b' => ['pattern' => 'inquiry.*', 'title' => 'Lower'],
            ],
        ], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasActionErrors(['templates.a.pattern', 'templates.b.pattern']);
});

it('reads a hyphen after a bare field label as text, and one inside it as part of the label', function () {
    $message = inquiry()->field('E-mail', 'x@y.pl');

    expect(formatWith(['inquiry.*' => ['title' => ':field.Name-:field.Email (:field.E-mail)']], $message))
        ->toContain('<b>[panel] Anna-a@b.pl (x@y.pl)</b>');
});

it('cuts the default title and body to the limit, like a template does', function () {
    $message = OpsMessage::make('x')->title(str_repeat('T', 500))->line(str_repeat('b', 5000));
    $default = new MessageTemplate;

    expect($default->title($message, null, 200))->toHaveLength(200)->toEndWith('...')
        ->and($default->body($message, null, 100))->toHaveLength(100)->toEndWith('...');
});

it('gives the budget a short title leaves to the body next to it', function () {
    $message = OpsMessage::make('x')->title('OK')->line(str_repeat('b', 500));

    expect((new MessageTemplate(title: ':title / :body'))->title($message, null, 200))
        ->toHaveLength(200)
        ->toStartWith('OK / '.str_repeat('b', 150));
});

it('shows every field when the fields list is a map, not a list', function () {
    expect(formatWith(['inquiry.*' => ['fields' => ['Name' => true, 'Email' => 'x']]], inquiry()))
        ->toBe((new TelegramFormatter)->format(inquiry(), 'panel'));
});

it('previews with the service name typed in the form, before it is saved', function () {
    loggedInquiry();

    templateSettings()
        ->fillForm(['service' => 'Typed', 'templates' => ['a' => ['pattern' => 'inquiry.*', 'title' => ':title']]], 'mountedActionSchema0')
        ->mountAction(TestAction::make('previewTemplate')->schemaComponent('templates.templates', 'mountedActionSchema0')->arguments(['item' => 'a']))
        ->assertMountedActionModalSee('[Typed] New inquiry');
});

it('sends test messages without a template, so a catch-all cannot hide them', function () {
    config(['ops-notify.templates' => ['*' => ['title' => 'Templated', 'body' => false, 'fields' => []]]]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'bot']])]);
    Filament::setCurrentPanel('admin');
    test()->actingAs(test()->admin());

    test()->artisan('ops-notify:test', ['text' => 'ping'])->assertSuccessful();
    Livewire::test(OpsNotifyPage::class)->callAction('sendTest', data: ['text' => 'pong'])->assertHasNoActionErrors();

    // getMe requests carry no text.
    $plain = fn (string $word): Closure => fn (Request $request): bool => str_contains($text = (string) ($request->data()['text'] ?? ''), $word) && ! str_contains($text, 'Templated');

    Http::assertSent($plain('ping'));
    Http::assertSent($plain('pong'));
});

it('keeps the upper of two rows with the same key, the one the chat gets', function () {
    $settings = (new SettingsForm(app(SettingsStore::class)))->toSettings([
        'events' => [
            ['pattern' => 'inquiry.*', 'topic' => '1', 'enabled' => true],
            ['pattern' => ' inquiry.* ', 'topic' => '2', 'enabled' => true],
        ],
        'templates' => [
            ['pattern' => 'inquiry.*', 'title' => 'Upper'],
            ['pattern' => ' inquiry.* ', 'title' => 'Lower'],
        ],
        'forward_map' => [
            ['title' => 'Backup', 'event' => 'backup.done', 'forward' => true],
            ['title' => 'Backup ', 'event' => 'other', 'forward' => true],
        ],
    ]);

    expect($settings)->toBe([
        'events' => ['inquiry.*' => ['topic' => '1', 'enabled' => true]],
        'templates' => ['inquiry.*' => ['title' => 'Upper']],
        'forward_map' => ['Backup' => 'backup.done'],
    ]);
});

it('starts a new template row as the default layout spelled out, and saves that as no change', function () {
    $form = templateSettings()
        ->fillForm(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1'], 'mountedActionSchema0')
        ->callFormComponentAction('templates.templates', 'add', formName: 'mountedActionSchema0');

    $path = $form->instance()->mountedActionSchema0->getStatePath().'.templates';
    $rows = $form->get($path);
    $key = array_key_first($rows);

    expect($rows[$key])->toMatchArray(['title' => ':title', 'body' => ':body', 'show_body' => true, 'show_fields' => true]);

    $form->set("{$path}.{$key}.pattern", 'inquiry.*')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $store = app(SettingsStore::class);

    expect($store->formValues()['templates'])->toBe(['inquiry.*' => []])
        // Back in the form, the default parts are spelled out again.
        ->and(array_values((new SettingsForm($store))->fill()['templates'])[0])->toMatchArray(['pattern' => 'inquiry.*', 'title' => ':title', 'body' => ':body'])
        ->and(formatWith(['inquiry.*' => ['title' => ':title', 'body' => ':body']], inquiry()))->toBe((new TelegramFormatter)->format(inquiry(), 'panel'));
});
