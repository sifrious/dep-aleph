<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\Contracts\ConsumesWebhooks;
use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;

final class WebhookConnector extends BaseFakeConnector implements ConsumesWebhooks
{
    /** @var list<WebhookDelivery> */
    public array $deliveries = [];

    public function consumeWebhook(WebhookDelivery $delivery): OperationResult
    {
        $this->deliveries[] = $delivery;

        if ($delivery->signature === null) {
            return OperationResult::failed('missing signature');
        }

        return OperationResult::completed(1, ['event' => $delivery->header('X-Event') ?? 'unknown']);
    }
}
