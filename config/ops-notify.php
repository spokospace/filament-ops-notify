<?php

return [

    /*
    | Master switch. When false, nothing is sent or logged.
    */
    'enabled' => env('OPS_NOTIFY_ENABLED', true),

    /*
    | Label that prefixes every message, so one chat can collect several services.
    */
    'service' => env('OPS_NOTIFY_SERVICE', env('APP_NAME', 'Laravel')),

    'default_channel' => env('OPS_NOTIFY_CHANNEL', 'telegram'),

    'channels' => [
        'telegram' => [
            'driver' => 'telegram',
            'bot_token' => env('OPS_NOTIFY_TELEGRAM_BOT_TOKEN'),
            'chat_id' => env('OPS_NOTIFY_TELEGRAM_CHAT_ID'),
            // Forum topic (message_thread_id) used when an event has no topic of its own.
            'topic' => env('OPS_NOTIFY_TELEGRAM_TOPIC'),
            'api_url' => env('OPS_NOTIFY_TELEGRAM_API_URL', 'https://api.telegram.org'),
            'timeout' => 10,
        ],
    ],

    /*
    | Per-event routing. Keys are patterns matched with Str::is(), first match wins.
    | Options: enabled (bool), channel (name from `channels`), topic (forum topic id).
    | Events that match nothing are sent to the default channel and topic.
    */
    'events' => [
        // 'inquiry.*' => ['topic' => env('OPS_NOTIFY_TOPIC_INQUIRIES')],
        // 'build.*' => ['topic' => env('OPS_NOTIFY_TOPIC_BUILDS')],
        // 'error.*' => ['topic' => env('OPS_NOTIFY_TOPIC_ERRORS')],
        // 'noisy.event' => ['enabled' => false],
    ],

    /*
    | Mirror Filament bell notifications (Notification::make()->sendToDatabase($users)).
    | One notification sent to N users produces one message; identical payloads within
    | `dedupe_seconds` are collapsed.
    |
    | `map` matches the notification TITLE with Str::is(), first match wins:
    |   a string = event name (route it with `events` above), false = don't forward.
    | Unmatched titles use `default_event`; set it to null to forward only mapped titles.
    */
    'forward_database_notifications' => [
        'enabled' => env('OPS_NOTIFY_FORWARD_DATABASE', true),
        'default_event' => 'filament.notification',
        'dedupe_seconds' => 60,
        'map' => [
            // 'Nowe zapytanie*' => 'inquiry.created',
            // 'Export completed*' => false,
        ],
    ],

    'queue' => [
        'connection' => env('OPS_NOTIFY_QUEUE_CONNECTION'),
        'name' => env('OPS_NOTIFY_QUEUE'),
    ],

    'log' => [
        // Store every sent message in ops_notify_logs (shown on the Filament page).
        'enabled' => env('OPS_NOTIFY_LOG_ENABLED', true),
        // Rows older than this are removed by `php artisan model:prune`.
        'prune_after_days' => 30,
    ],

];
