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
        ->toContain('<b>&lt;b&gt;:</b> &quot;quoted&quot;')
        ->not->toContain('<script>');
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
