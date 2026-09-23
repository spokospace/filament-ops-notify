<?php

use Illuminate\Support\Facades\Queue;
use Spokospace\OpsNotify\Jobs\SendOpsMessage;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Queue::fake();
});

it('drops an identical message sent again from app code', function () {
    $first = OpsMessage::make('inquiry.created')->title('New inquiry #12')->send();
    $second = OpsMessage::make('inquiry.created')->title('New inquiry #12')->send();

    expect($first)->not->toBeNull()->and($second)->toBeNull();
    Queue::assertPushed(SendOpsMessage::class, 1);
});

it('sends messages that differ', function () {
    OpsMessage::make('inquiry.created')->title('New inquiry #12')->send();
    OpsMessage::make('inquiry.created')->title('New inquiry #13')->send();

    Queue::assertPushed(SendOpsMessage::class, 2);
});

it('can be turned off', function () {
    config(['ops-notify.dedupe_seconds' => 0]);

    OpsMessage::make('inquiry.created')->title('New inquiry #12')->send();
    OpsMessage::make('inquiry.created')->title('New inquiry #12')->send();

    Queue::assertPushed(SendOpsMessage::class, 2);
});

it('does not dedupe the notifier itself, so Send test and Resend always go out', function () {
    $notifier = app(OpsNotifier::class);

    $notifier->send(OpsMessage::make('ops.test')->title('Test'));
    $notifier->send(OpsMessage::make('ops.test')->title('Test'));

    Queue::assertPushed(SendOpsMessage::class, 2);
});
