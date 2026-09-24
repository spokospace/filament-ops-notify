<?php

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

it('gives a message sent without a template only the two switches of "*"', function () {
    $message = OpsMessage::make('x')->title('Plain')->withoutTemplate();
    $templates = ['x' => ['title' => 'Templated'], '*' => ['title' => 'All', 'body' => false, 'hashtag' => false]];

    expect(MessageTemplate::for(['x' => ['title' => 'Templated']], $message))->toBeNull()
        ->and(MessageTemplate::for($templates, $message)?->toArray())->toBe(['hashtag' => false])
        ->and(formatWith(['*' => ['title' => 'All', 'service' => false]], $message))->toBe("ℹ️ <b>Plain</b>\n\n#x")
        ->and(OpsMessage::fromArray($message->toArray())->templated)->toBeFalse()
        ->and(OpsMessage::fromArray(OpsMessage::make('x')->toArray())->templated)->toBeTrue();
});

it('checks "*" last, wherever it is stored', function () {
    $templates = ['*' => ['title' => 'All'], 'inquiry.*' => ['title' => 'Inquiry']];

    expect(MessageTemplate::for($templates, inquiry())?->title)->toBe('Inquiry')
        ->and(MessageTemplate::for($templates, OpsMessage::make('build.done'))?->title)->toBe('All')
        ->and(MessageTemplate::for(['build.*' => ['title' => 'Build']], inquiry()))->toBeNull();
});

it('reads :title and :body as the default parts, which store nothing', function () {
    expect(MessageTemplate::fromArray(['title' => ':title', 'body' => ':body'])->toArray())->toBe([])
        ->and(formatWith(['inquiry.*' => ['title' => ':title', 'body' => ':body']], inquiry()))->toBe((new TelegramFormatter)->format(inquiry(), 'panel'));
});

it('rebuilds a logged message whose payload lacks keys from the row itself', function () {
    $log = OpsNotifyLog::query()->create(['channel' => 'telegram', 'event' => 'x.y', 'title' => 'Row title', 'level' => 'info', 'payload' => ['lines' => ['Body']], 'status' => DeliveryStatus::Sent]);
    $message = $log->toMessage();

    expect($message->event)->toBe('x.y')
        ->and($message->title)->toBe('Row title')
        ->and($message->body())->toBe('Body');
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

it('saves the two toggles as parts of the "*" template and leaves the rest of the templates alone', function () {
    $store = app(SettingsStore::class);
    $store->save(['templates' => ['inquiry.*' => ['title' => 'From :field.Name'], '*' => ['body' => false, 'hashtag' => false]]]);

    $form = templateSettings();

    expect($form->get($form->instance()->mountedActionSchema0->getStatePath()))->toMatchArray(['show_service' => true, 'show_hashtag' => false]);

    $form->fillForm(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1', 'show_service' => false, 'show_hashtag' => true], 'mountedActionSchema0')
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($store->formValues()['templates'])->toBe(['inquiry.*' => ['title' => 'From :field.Name'], '*' => ['body' => false, 'service' => false]]);
});

it('drops the "*" template once both toggles are on and nothing else is under it', function () {
    $store = app(SettingsStore::class);
    $store->save(['telegram_bot_token' => '999:PANEL', 'telegram_chat_id' => '-1', 'templates' => ['*' => ['hashtag' => false]]]);

    templateSettings()->fillForm(['show_hashtag' => true], 'mountedActionSchema0')->callMountedAction()->assertHasNoActionErrors();

    expect($store->formValues()['templates'])->toBeNull();
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

it('sends test messages with only the two switches of a catch-all template, so it cannot hide them', function () {
    config(['ops-notify.templates' => ['*' => ['title' => 'Templated', 'body' => false, 'fields' => [], 'service' => false]]]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'bot']])]);
    Filament::setCurrentPanel('admin');
    test()->actingAs(test()->admin());

    test()->artisan('ops-notify:test', ['text' => 'ping'])->assertSuccessful();
    Livewire::test(OpsNotifyPage::class)->callAction('sendTest', data: ['text' => 'pong'])->assertHasNoActionErrors();

    // getMe requests carry no text.
    $plain = fn (string $word): Closure => fn (Request $request): bool => str_contains($text = (string) ($request->data()['text'] ?? ''), $word) && ! str_contains($text, 'Templated') && ! str_contains($text, '<b>[');

    Http::assertSent($plain('ping'));
    Http::assertSent($plain('pong'));
});

it('keeps the upper of two rows with the same key, the one the chat gets', function () {
    $settings = (new SettingsForm(app(SettingsStore::class)))->toSettings([
        'events' => [
            ['pattern' => 'inquiry.*', 'topic' => '1', 'enabled' => true],
            ['pattern' => ' inquiry.* ', 'topic' => '2', 'enabled' => true],
        ],
        'forward_map' => [
            ['title' => 'Backup', 'event' => 'backup.done', 'forward' => true],
            ['title' => 'Backup ', 'event' => 'other', 'forward' => true],
        ],
    ]);

    expect($settings)->toBe([
        'events' => ['inquiry.*' => ['topic' => '1', 'enabled' => true]],
        'forward_map' => ['Backup' => 'backup.done'],
    ]);
});
