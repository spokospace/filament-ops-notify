<?php

return [

    'page' => [
        'title' => 'Ops-meldingen',
        'status' => 'Status',
        'service' => 'Dienst',
        'enabled' => 'Ingeschakeld',
        'disabled' => 'Uitgeschakeld',
        'channel' => 'Kanaal',
        'connection' => 'Verbinding',
        'configured' => 'Geconfigureerd',
        'not_configured' => 'Niet geconfigureerd',
        'connected_as' => 'Verbonden als @:username',
    ],

    'actions' => [
        'settings' => 'Instellingen',
        'settings_saved' => 'Instellingen opgeslagen',
        'save' => 'Opslaan',
        'send_test' => 'Test versturen',
        'send' => 'Versturen',
        'message' => 'Bericht',
        'test_default_text' => 'Als je dit kunt lezen, werken de meldingen.',
        'resend' => 'Opnieuw versturen',
        'sent' => 'Verstuurd',
        'not_sent' => 'Niet verstuurd',
    ],

    'table' => [
        'when' => 'Wanneer',
        'event' => 'Gebeurtenis',
        'level' => 'Niveau',
        'title' => 'Titel',
        'status' => 'Status',
        'channel_topic' => 'Kanaal / onderwerp',
        'attempts' => 'Pogingen',
        'empty' => 'Nog geen meldingen verstuurd',
        'resent_as' => 'Opnieuw verstuurd als #:id',
    ],

    'level' => [
        'info' => 'Info',
        'success' => 'Succes',
        'warning' => 'Waarschuwing',
        'error' => 'Fout',
        'critical' => 'Kritiek',
    ],

    'status' => [
        'queued' => 'In wachtrij',
        'sent' => 'Verstuurd',
        'failed' => 'Mislukt',
        'resent' => 'Opnieuw verstuurd',
    ],

    'settings' => [
        'locked' => 'Ingesteld in .env of config/ops-notify.php; wijzig het daar.',

        'telegram' => 'Telegram',
        'service' => 'Dienstnaam',
        'service_help' => 'Staat voor elk bericht, bijv. [shop.example]. Leeg = de appnaam.',
        'locale' => 'Taal van berichten',
        'locale_help' => 'Taal van de teksten die het pakket aan Telegram-berichten toevoegt. De chat wordt door het hele team gelezen, dus het is voor iedereen dezelfde.',
        'locale_default' => 'Taal van de app (:locale)',
        'bot_token' => 'Bottoken',
        'bot_token_saved' => 'Opgeslagen; laat leeg om te behouden',
        'bot_token_unreadable' => 'Het opgeslagen token kan niet worden ontsleuteld (APP_KEY gewijzigd). Voer het opnieuw in.',
        'chat_id' => 'Chat-ID',
        'chat_id_help' => 'php artisan ops-notify:telegram-chats toont het.',
        'default_topic' => 'Standaardonderwerp',
        'default_topic_help' => 'Voor gebeurtenissen zonder eigen onderwerp. Leeg = General.',
        'general' => 'General',
        'enabled' => 'Meldingen ingeschakeld',

        'topics' => 'Onderwerpen',
        'topics_description' => 'Forumonderwerpen van de chat. "Onderwerp maken" maakt het aan in Telegram (de bot heeft het beheerdersrecht "Onderwerpen beheren" nodig; sla eerst het token en de chat-ID op). Een onderwerp hier verwijderen verwijdert het niet in Telegram.',
        'topic_name' => 'Naam',
        'topic_id' => 'Onderwerp-ID',
        'topic' => 'Onderwerp',
        'add_existing_topic' => 'Bestaand onderwerp toevoegen',
        'topic_name_placeholder' => 'Reacties',
        'create_topic' => 'Onderwerp maken',
        'icon_colour' => 'Pictogramkleur',
        'create_in_telegram' => 'Maken in Telegram',
        'topic_created' => 'Onderwerp ":name" gemaakt (#:id)',
        'topic_not_created' => 'Onderwerp niet gemaakt',
        'save_to_keep_it' => 'Sla de instellingen op om het in de lijst te houden.',
        'import_topics' => 'Importeren uit Telegram',
        'imported_topics' => ':count onderwerp geïmporteerd|:count onderwerpen geïmporteerd',
        'save_to_keep_them' => 'Sla de instellingen op om ze te behouden.',
        'import_failed' => 'Importeren mislukt',
        'no_new_topics' => 'Geen nieuwe onderwerpen gevonden',
        'no_new_topics_help' => 'Stuur /ping@jouw_bot in elk onderwerp en importeer opnieuw.',

        'routing' => 'Routering van gebeurtenissen',
        'routing_description' => 'Het eerste overeenkomende patroon wint, bijv. inquiry.* of build.failed.',
        'pattern' => 'Patroon',
        'add_rule' => 'Regel toevoegen',

        'forwarding' => 'Filament-meldingen',
        'forwarding_description' => 'Belmeldingen die naar het ops-kanaal worden doorgestuurd. Regels vergelijken de titel; de eerste overeenkomende wint.',
        'forward_enabled' => 'Belmeldingen doorsturen',
        'forward_title_placeholder' => 'Nieuwe aanvraag*',
        'forward' => 'Doorsturen',
    ],

    'colors' => [
        'blue' => 'Blauw',
        'yellow' => 'Geel',
        'violet' => 'Violet',
        'green' => 'Groen',
        'pink' => 'Roze',
        'red' => 'Rood',
    ],

    'message' => [
        'test_title' => 'Testmelding',
        'sent_by' => 'Verstuurd door',
        'environment' => 'Omgeving',
        'host' => 'Host',
        'open' => 'Openen',
        'more_fields' => 'en nog :count veld|en nog :count velden',
    ],

];
