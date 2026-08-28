<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Testing\Fakes;

use Sifrious\Aleph\Connector\Contracts\SyncsIncrementally;
use Sifrious\Aleph\Connector\Values\OperationRequest;
use Sifrious\Aleph\Connector\Values\OperationResult;

final class IncrementalConnector extends BaseFakeConnector implements SyncsIncrementally
{
    /** @var list<OperationRequest> */
    public array $syncCalls = [];

    public function syncIncrementally(OperationRequest $request): OperationResult
    {
        $this->syncCalls[] = $request;

        return $request->cursor === null
            ? OperationResult::partial(2, 'cursor-1')
            : OperationResult::completed(1, ['resumed_from' => $request->cursor]);
    }
}
