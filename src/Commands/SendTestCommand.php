<?php

namespace Spokospace\OpsNotify\Commands;

use Illuminate\Console\Command;
use Spokospace\OpsNotify\Exceptions\MessageSkipped;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\OpsNotifier;
use Spokospace\OpsNotify\Support\Trans;
use Throwable;

class SendTestCommand extends Command
{
    protected $signature = 'ops-notify:test
        {text? : Message body}
        {--event=ops.test : Event name, used for routing and the #hashtag}
        {--queue : Dispatch through the queue instead of sending right away}';

    protected $description = 'Send a test notification to check the channel configuration';

    public function handle(OpsNotifier $notifier): int
    {
        $message = $notifier->inMessageLocale(fn (): OpsMessage => OpsMessage::make((string) $this->option('event'))
            ->title(Trans::get('message.test_title'))
            ->line((string) ($this->argument('text') ?? Trans::get('actions.test_default_text')))
            ->field(Trans::get('message.environment'), app()->environment())
            ->field(Trans::get('message.host'), gethostname() ?: null));

        if ($this->option('queue')) {
            $notifier->send($message);
            $this->components->info('Queued, unless the event is disabled; check the log on the Filament page.');

            return self::SUCCESS;
        }

        try {
            $notifier->sendNow($message);
        } catch (MessageSkipped $e) {
            $this->components->warn('Nothing sent: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Sent.');

        return self::SUCCESS;
    }
}
