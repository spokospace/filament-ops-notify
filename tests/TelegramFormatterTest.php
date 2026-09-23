<?php

use Spokospace\OpsNotify\Channels\Telegram\TelegramFormatter;
use Spokospace\OpsNotify\OpsMessage;

it('renders title, body, fields and an event hashtag', function () {
    $message = OpsMessage::make('inquiry.created')
        ->title('New inquiry')
        ->line('Hello')
        ->line('Second line')
        ->field('Email', 'a@b.pl')
        ->field('Skipped', null);

    expect((new TelegramFormatter)->format($message, 'panel'))->toBe(
        "ℹ️ <b>[panel] New inquiry</b>\n\nHello\nSecond line\n\n<b>Email:</b> a@b.pl\n\n#inquiry_created"
    );
});

it('escapes html in every user supplied part', function () {
    $message = OpsMessage::make('error.x')
        ->error()
        ->title('<script>')
        ->line('a < b & c')
        ->field('<b>', '"quoted"');

    $text = (new TelegramFormatter)->format($message, null);

    expect($text)
        ->toContain('❌ <b>&lt;script&gt;</b>')
        ->toContain('a &lt; b &amp; c')
        ->toContain('<b>&lt;b&gt;:</b> "quoted"')
        ->not->toContain('<script>');
});

it('uses only entities telegram understands', function () {
    $text = (new TelegramFormatter)->format(OpsMessage::make('x')->title("Can't connect")->line('&copy; 2026'), null);

    expect($text)
        ->toContain("Can't connect")          // no &apos; (shown literally by Telegram)
        ->toContain('&amp;copy; 2026')        // existing entities are escaped, not passed through
        ->not->toContain('&apos;');
});

it('keeps the whole message under telegram\'s limit however many fields it has', function () {
    $message = OpsMessage::make('error.x')->title(str_repeat('T', 500))->line(str_repeat('b', 5000));

    foreach (range(1, 40) as $i) {
        $message->field("Field {$i}", str_repeat('v', 400));
    }

    $text = (new TelegramFormatter)->format($message, 'panel.polo.blue');
    $visible = html_entity_decode(strip_tags($text));

    expect(mb_strlen($visible))->toBeLessThanOrEqual(4096)
        ->and($text)->toMatch('/and \d+ more fields/');
});

it('can render buttons as text links', function () {
    $text = (new TelegramFormatter)->format(OpsMessage::make('x')->button('Open', 'http://localhost/a?b=1&c=2'), null, linksAsText: true);

    expect($text)->toContain('<b>Open:</b> http://localhost/a?b=1&amp;c=2');
});

it('truncates the body before escaping so no entity is cut in half', function () {
    $message = OpsMessage::make('big')->line(str_repeat('&', 5000));

    $text = (new TelegramFormatter)->format($message, null);

    expect(mb_strlen($text))->toBeLessThan(20000)
        ->and($text)->toMatch('/(&amp;)+\.\.\./');
});

it('falls back to the event name when there is no title', function () {
    expect((new TelegramFormatter)->format(OpsMessage::make('build.finished')->success(), 'x'))
        ->toStartWith('✅ <b>[x] build.finished</b>');
});
