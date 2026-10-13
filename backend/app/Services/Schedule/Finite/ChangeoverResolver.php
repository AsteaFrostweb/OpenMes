<?php

namespace App\Services\Schedule\Finite;

use App\Models\ChangeoverRule;
use Illuminate\Support\Collection;

/**
 * How long an operation's setup takes, given what ran before it on the same
 * resource.
 *
 * Without a matching {@see ChangeoverRule} — or with nothing before it on the
 * resource — this is the routing's standard setup time, unchanged. When rules
 * match, the most specific wins: a rule for this workstation beats one for its
 * equipment class, which beats a plant-wide rule; within that, a named
 * "from" value beats "any", then a named "to" value; remaining ties go to the
 * older rule. The winner's minutes replace the standard setup — the routing's
 * standard setup is what a changeover rule refines, not something added to it.
 * A step with only an overall estimate (no separable setup) gets the rule's
 * minutes on top of that estimate.
 *
 * Accounts for setup only; it does not reorder jobs to minimise it.
 */
class ChangeoverResolver
{
    /** @var Collection<int, ChangeoverRule>|null */
    private ?Collection $rules = null;

    /**
     * @param  array<string, mixed>|null  $previous  attributes of the operation that ran last on the resource
     * @return array{minutes: ?int, rule_id: ?int}
     */
    public function setup(OperationDemand $operation, ?array $previous, ?int $workstationId, ?int $workstationTypeId): array
    {
        $standard = ['minutes' => $operation->setupMinutes, 'rule_id' => null];
        if ($previous === null) {
            return $standard;
        }

        $best = null;
        $bestScore = -1;
        foreach ($this->rules() as $rule) {
            if ($rule->workstation_id !== null && (int) $rule->workstation_id !== $workstationId) {
                continue;
            }
            if ($rule->workstation_type_id !== null && (int) $rule->workstation_type_id !== $workstationTypeId) {
                continue;
            }
            if (! $this->matches($rule->from_value, $previous[$rule->attribute] ?? null)
                || ! $this->matches($rule->to_value, $operation->attributes[$rule->attribute] ?? null)) {
                continue;
            }

            $score = ($rule->workstation_id !== null ? 8 : 0)
                + ($rule->workstation_type_id !== null ? 4 : 0)
                + ($rule->from_value !== null ? 2 : 0)
                + ($rule->to_value !== null ? 1 : 0);
            if ($score > $bestScore) {
                $best = $rule;
                $bestScore = $score;
            }
        }

        return $best ? ['minutes' => $best->setup_minutes, 'rule_id' => $best->id] : $standard;
    }

    /** A rule side left empty matches anything; otherwise a case-insensitive exact value. */
    private function matches(?string $ruleValue, mixed $actual): bool
    {
        if ($ruleValue === null || trim($ruleValue) === '') {
            return true;
        }

        return $actual !== null && strcasecmp(trim($ruleValue), trim((string) $actual)) === 0;
    }

    /** @return Collection<int, ChangeoverRule> */
    private function rules(): Collection
    {
        return $this->rules ??= ChangeoverRule::where('is_active', true)->orderBy('id')->get();
    }
}
