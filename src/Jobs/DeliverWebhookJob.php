<?php

declare(strict_types=1);

namespace Argws\LaravelUpdater\Jobs;

use Argws\LaravelUpdater\Webhooks\WebhookDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;

class DeliverWebhookJob implements ShouldQueue
{
    public int $tries = 1;
    public int $timeout = 30;

    public function __construct(public readonly int $deliveryId, public readonly int $attempt)
    {
    }

    public function handle(WebhookDispatcher $dispatcher): void
    {
        // Retries and backoff are persisted in the outbox, independently of queue driver settings.
        $dispatcher->deliver($this->deliveryId, $this->attempt);
    }
}
