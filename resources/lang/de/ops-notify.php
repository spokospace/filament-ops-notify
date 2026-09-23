<?php

return [

    'page' => [
        'title' => 'Ops-Benachrichtigungen',
        'status' => 'Status',
        'service' => 'Dienst',
        'enabled' => 'Aktiv',
        'disabled' => 'Inaktiv',
        'channel' => 'Kanal',
        'connection' => 'Verbindung',
        'configured' => 'Konfiguriert',
        'not_configured' => 'Nicht konfiguriert',
        'connected_as' => 'Verbunden als @:username',
    ],

    'actions' => [
        'settings' => 'Einstellungen',
        'settings_saved' => 'Einstellungen gespeichert',
        'save' => 'Speichern',
        'send_test' => 'Test senden',
        'send' => 'Senden',
        'message' => 'Nachricht',
        'test_default_text' => 'Wenn du das lesen kannst, funktionieren die Benachrichtigungen.',
        'resend' => 'Erneut senden',
        'sent' => 'Gesendet',
        'not_sent' => 'Nicht gesendet',
    ],

    'table' => [
        'when' => 'Wann',
        'event' => 'Ereignis',
        'level' => 'Stufe',
        'title' => 'Titel',
        'status' => 'Status',
        'channel_topic' => 'Kanal / Thema',
        'attempts' => 'Versuche',
        'empty' => 'Noch keine Benachrichtigungen gesendet',
        'resent_as' => 'Erneut gesendet als #:id',
    ],

    'level' => [
        'info' => 'Info',
        'success' => 'Erfolg',
        'warning' => 'Warnung',
        'error' => 'Fehler',
        'critical' => 'Kritisch',
    ],

    'status' => [
        'queued' => 'In Warteschlange',
        'sent' => 'Gesendet',
        'failed' => 'Fehlgeschlagen',
        'resent' => 'Erneut gesendet',
    ],

    'settings' => [
        'locked' => 'In .env oder config/ops-notify.php gesetzt – dort ändern.',

        'telegram' => 'Telegram',
        'service' => 'Dienstname',
        'service_help' => 'Wird jeder Nachricht vorangestellt, z. B. [shop.example]. Leer = App-Name.',
        'locale' => 'Nachrichtensprache',
        'locale_help' => 'Sprache der Texte, die das Paket den Telegram-Nachrichten hinzufügt. Den Chat liest das ganze Team, daher gilt sie für alle.',
        'locale_default' => 'App-Sprache (:locale)',
        'bot_token' => 'Bot-Token',
        'bot_token_saved' => 'Gespeichert; leer lassen, um ihn zu behalten',
        'bot_token_unreadable' => 'Der gespeicherte Token kann nicht entschlüsselt werden (APP_KEY geändert). Bitte erneut eingeben.',
        'chat_id' => 'Chat-ID',
        'chat_id_help' => 'php artisan ops-notify:telegram-chats zeigt sie an.',
        'default_topic' => 'Standardthema',
        'default_topic_help' => 'Für Ereignisse ohne eigenes Thema. Leer = General.',
        'general' => 'General',
        'enabled' => 'Benachrichtigungen aktiv',

        'topics' => 'Themen',
        'topics_description' => 'Forumthemen des Chats. „Thema erstellen“ legt es in Telegram an (der Bot braucht das Admin-Recht „Themen verwalten“; zuerst Token und Chat-ID speichern). Entfernen hier löscht das Thema nicht in Telegram.',
        'topic_name' => 'Name',
        'topic_id' => 'Themen-ID',
        'topic' => 'Thema',
        'add_existing_topic' => 'Vorhandenes Thema hinzufügen',
        'topic_name_placeholder' => 'Kommentare',
        'create_topic' => 'Thema erstellen',
        'icon_colour' => 'Symbolfarbe',
        'create_in_telegram' => 'In Telegram erstellen',
        'topic_created' => 'Thema „:name“ erstellt (#:id)',
        'topic_not_created' => 'Thema nicht erstellt',
        'save_to_keep_it' => 'Speichere die Einstellungen, um es in der Liste zu behalten.',
        'import_topics' => 'Aus Telegram importieren',
        'imported_topics' => ':count Thema importiert|:count Themen importiert',
        'save_to_keep_them' => 'Speichere die Einstellungen, um sie zu behalten.',
        'import_failed' => 'Import fehlgeschlagen',
        'no_new_topics' => 'Keine neuen Themen gefunden',
        'no_new_topics_help' => 'Sende /ping@dein_bot in jedem Thema und importiere erneut.',

        'routing' => 'Ereignis-Routing',
        'routing_description' => 'Das erste passende Muster gewinnt, z. B. inquiry.* oder build.failed.',
        'pattern' => 'Muster',
        'add_rule' => 'Regel hinzufügen',

        'forwarding' => 'Filament-Benachrichtigungen',
        'forwarding_description' => 'Glocken-Benachrichtigungen, die an den Ops-Kanal weitergeleitet werden. Regeln prüfen den Titel; die erste passende gewinnt.',
        'forward_enabled' => 'Glocken-Benachrichtigungen weiterleiten',
        'forward_title_placeholder' => 'Neue Anfrage*',
        'forward' => 'Weiterleiten',
    ],

    'colors' => [
        'blue' => 'Blau',
        'yellow' => 'Gelb',
        'violet' => 'Violett',
        'green' => 'Grün',
        'pink' => 'Rosa',
        'red' => 'Rot',
    ],

    'message' => [
        'test_title' => 'Testbenachrichtigung',
        'sent_by' => 'Gesendet von',
        'environment' => 'Umgebung',
        'host' => 'Host',
        'open' => 'Öffnen',
        'more_fields' => 'und :count weiteres Feld|und :count weitere Felder',
    ],

];
