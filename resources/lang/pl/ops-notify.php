<?php

return [

    'page' => [
        'title' => 'Powiadomienia ops',
        'status' => 'Status',
        'service' => 'Serwis',
        'enabled' => 'Włączone',
        'disabled' => 'Wyłączone',
        'channel' => 'Kanał',
        'connection' => 'Połączenie',
        'configured' => 'Skonfigurowany',
        'not_configured' => 'Nieskonfigurowany',
        'connected_as' => 'Połączono jako @:username',
    ],

    'actions' => [
        'settings' => 'Ustawienia',
        'settings_saved' => 'Ustawienia zapisane',
        'save' => 'Zapisz',
        'send_test' => 'Wyślij test',
        'send' => 'Wyślij',
        'message' => 'Wiadomość',
        'test_default_text' => 'Jeśli to czytasz, powiadomienia działają.',
        'resend' => 'Wyślij ponownie',
        'sent' => 'Wysłano',
        'not_sent' => 'Nie wysłano',
    ],

    'table' => [
        'when' => 'Kiedy',
        'event' => 'Zdarzenie',
        'level' => 'Poziom',
        'title' => 'Tytuł',
        'status' => 'Status',
        'channel_topic' => 'Kanał / temat',
        'attempts' => 'Próby',
        'empty' => 'Nie wysłano jeszcze żadnych powiadomień',
        'resent_as' => 'Wysłano ponownie jako #:id',
    ],

    'level' => [
        'info' => 'Informacja',
        'success' => 'Sukces',
        'warning' => 'Ostrzeżenie',
        'error' => 'Błąd',
        'critical' => 'Krytyczny',
    ],

    'status' => [
        'queued' => 'W kolejce',
        'sent' => 'Wysłano',
        'failed' => 'Błąd',
        'resent' => 'Wysłano ponownie',
    ],

    'settings' => [
        'locked' => 'Ustawione w .env lub config/ops-notify.php, zmień tam.',

        'telegram' => 'Telegram',
        'service' => 'Nazwa serwisu',
        'service_help' => 'Poprzedza każdą wiadomość, np. [shop.example]. Puste = nazwa aplikacji.',
        'locale' => 'Język wiadomości',
        'locale_help' => 'Język tekstów, które pakiet dodaje do wiadomości w Telegramie. Czat czyta cały zespół, więc jest jeden dla wszystkich.',
        'locale_default' => 'Język aplikacji (:locale)',
        'bot_token' => 'Token bota',
        'bot_token_saved' => 'Zapisany; zostaw puste, aby go zachować',
        'bot_token_unreadable' => 'Nie da się odszyfrować zapisanego tokena (zmienił się APP_KEY). Wpisz go ponownie.',
        'chat_id' => 'ID czatu',
        'chat_id_help' => 'Pokaże je php artisan ops-notify:telegram-chats.',
        'default_topic' => 'Domyślny temat',
        'default_topic_help' => 'Dla zdarzeń bez własnego tematu. Puste = General.',
        'general' => 'General',
        'enabled' => 'Powiadomienia włączone',

        'topics' => 'Tematy',
        'topics_description' => 'Tematy forum w czacie. „Utwórz temat” tworzy go w Telegramie (bot potrzebuje uprawnienia „Zarządzanie tematami”; najpierw zapisz token i ID czatu). Usunięcie tematu tutaj nie usuwa go w Telegramie.',
        'topic_name' => 'Nazwa',
        'topic_id' => 'ID tematu',
        'topic' => 'Temat',
        'add_existing_topic' => 'Dodaj istniejący temat',
        'topic_name_placeholder' => 'Komentarze',
        'create_topic' => 'Utwórz temat',
        'icon_colour' => 'Kolor ikony',
        'create_in_telegram' => 'Utwórz w Telegramie',
        'topic_created' => 'Utworzono temat „:name” (#:id)',
        'topic_not_created' => 'Nie utworzono tematu',
        'save_to_keep_it' => 'Zapisz ustawienia, aby zachować go na liście.',
        'import_topics' => 'Importuj z Telegrama',
        'imported_topics' => 'Zaimportowano :count temat|Zaimportowano :count tematy|Zaimportowano :count tematów',
        'save_to_keep_them' => 'Zapisz ustawienia, aby je zachować.',
        'import_failed' => 'Import nie powiódł się',
        'no_new_topics' => 'Brak nowych tematów',
        'no_new_topics_help' => 'Wyślij /ping@twoj_bot w każdym temacie i zaimportuj ponownie.',

        'routing' => 'Kierowanie zdarzeń',
        'routing_description' => 'Wygrywa pierwszy pasujący wzorzec, np. inquiry.* lub build.failed.',
        'pattern' => 'Wzorzec',
        'add_rule' => 'Dodaj regułę',

        'forwarding' => 'Powiadomienia Filamenta',
        'forwarding_description' => 'Powiadomienia z dzwonka przekazywane do kanału ops. Reguły dopasowują tytuł; wygrywa pierwsza pasująca.',
        'forward_enabled' => 'Przekazuj powiadomienia z dzwonka',
        'forward_title_placeholder' => 'Nowe zapytanie*',
        'forward' => 'Przekazuj',
    ],

    'colors' => [
        'blue' => 'Niebieski',
        'yellow' => 'Żółty',
        'violet' => 'Fioletowy',
        'green' => 'Zielony',
        'pink' => 'Różowy',
        'red' => 'Czerwony',
    ],

    'message' => [
        'test_title' => 'Powiadomienie testowe',
        'sent_by' => 'Wysłał',
        'environment' => 'Środowisko',
        'host' => 'Host',
        'open' => 'Otwórz',
        'more_fields' => 'i jeszcze :count pole|i jeszcze :count pola|i jeszcze :count pól',
    ],

];
