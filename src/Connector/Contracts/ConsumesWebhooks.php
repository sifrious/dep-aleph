<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\Contracts;

use Sifrious\Aleph\Connector\Values\OperationResult;
use Sifrious\Aleph\Connector\Values\WebhookDelivery;

interface ConsumesWebhooks
{
    public function consumeWebhook(WebhookDelivery $delivery): OperationResult;
}
