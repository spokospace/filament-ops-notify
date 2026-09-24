# Drivers

Telegram is the built-in driver. A driver is a class that delivers an `OpsMessage` somewhere
else, for example to your own webhook; apps that send messages do not change.

## Writing a driver

A driver implements `Spokospace\OpsNotify\Contracts\Channel`:

```php
use Spokospace\OpsNotify\Contracts\Channel;
use Spokospace\OpsNotify\Exceptions\ChannelException;
use Spokospace\OpsNotify\OpsMessage;
use Spokospace\OpsNotify\Support\Destination;

class WebhookChannel implements Channel
{
    public function __construct(private array $config) {}

    public function send(OpsMessage $message, Destination $destination): string
    {
        // Deliver, and return the provider's message id.
        // Throw ChannelException on failure:
        //   retryAfter: seconds the provider asked to wait (rate limit)
        //   permanent:  true when retrying cannot help (bad URL, rejected payload)
    }

    public function isConfigured(): bool
    {
        return filled($this->config['url'] ?? null);
    }
}
```

`$destination->topic` is an opaque sub-target that the driver interprets. For Telegram it is a
forum thread id.

Register the driver and point a channel at it:

```php
// In a service provider's boot()
app(\Spokospace\OpsNotify\ChannelManager::class)
    ->extend('webhook', fn ($app, array $config) => new WebhookChannel($config));
```

```php
// config/ops-notify.php
'channels' => [
    'telegram' => [/* … */],
    'webhook' => ['driver' => 'webhook', 'url' => env('OPS_WEBHOOK_URL')],
],
'events' => [
    'error.*' => ['channel' => 'webhook'],
],
```

The creator receives the channel config plus `name` and `service` (the message prefix).

## Optional contracts

| Contract | Used for |
|---|---|
| `Contracts\ReportsStatus::status(): string` | The *Connection* line on the page. It must not throw, and should cache |
| `Contracts\LabelsTopics::topicLabel(string $id): string` | Topic names in the history table |
