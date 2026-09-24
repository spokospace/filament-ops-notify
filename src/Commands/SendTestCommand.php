<?php

namespace Spokospace\OpsNotify\Commands;

use Illuminate\Console\Command;
use Spokospace\OpsNotify\Enums\DeliveryStatus;
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
            // A catch-all template must not hide what a connectivity test sends.
            ->withoutTemplate()
            ->title(Trans::get('message.test_title'))
            ->line((string) ($this->argument('text') ?? Trans::get('actions.test_default_text')))
            ->field(Trans::get('message.environment'), app()->environment())
            ->field(Trans::get('message.host'), gethostname() ?: null));

        if ($this->option('queue')) {
            $log = $notifier->send($message);

            if ($log === null) {
                $this->components->warn('Nothing was queued: the event is disabled or the send failed. Check the log on the Filament page.');

                return self::FAILURE;
            }

            if ($log->status === DeliveryStatus::Suppressed) {
                $this->components->warn('Held back by the burst guard; it will be counted in the digest when the window ends.');

                return self::SUCCESS;
            }

            $this->components->info('Queued; it changes to Sent once a worker picks it up. Check the log on the Filament page.');

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
