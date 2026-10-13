<?php

namespace App\Services\Schedule\Finite;

/**
 * How long an operation's setup takes, given what ran before it on the same
 * resource. Without changeover rules this is simply the routing's standard
 * setup time.
 */
class ChangeoverResolver
{
    /**
     * @param  array<string, mixed>|null  $previous  attributes of the operation that ran last on the resource
     * @return array{minutes: ?int, rule_id: ?int}
     */
    public function setup(OperationDemand $operation, ?array $previous, ?int $workstationId, ?int $workstationTypeId): array
    {
        return ['minutes' => $operation->setupMinutes, 'rule_id' => null];
    }
}
