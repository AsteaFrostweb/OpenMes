<?php

namespace App\Http\Controllers\Web\Admin\Schedule;

use App\Http\Controllers\Concerns\StaysOnList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Web\Admin\Schedule\ChangeoverRuleRequest;
use App\Models\ChangeoverRule;
use App\Models\Workstation;
use App\Models\WorkstationType;
use Inertia\Inertia;

/**
 * Sequence-dependent setup rules the finite-capacity scheduler applies when
 * one job follows another on the same resource. Rows live-sync through the
 * `changeover_rules` shape; the lookups the form needs come as props.
 */
class ChangeoverRuleController extends Controller
{
    use StaysOnList;

    public function index()
    {
        return Inertia::render('admin/schedule/changeover-rules/Index', $this->formProps());
    }

    public function create()
    {
        return Inertia::render('admin/schedule/changeover-rules/Create', $this->formProps());
    }

    public function store(ChangeoverRuleRequest $request)
    {
        ChangeoverRule::create($request->validated());

        return $this->saved(
            $request,
            redirect()->route('admin.schedule.changeover-rules.index'),
            __('Changeover rule created.'),
        );
    }

    public function edit(ChangeoverRule $changeoverRule)
    {
        return Inertia::render('admin/schedule/changeover-rules/Edit', $this->formProps() + [
            'rule' => $changeoverRule->only('id', 'name', 'attribute', 'from_value', 'to_value', 'workstation_type_id', 'workstation_id', 'setup_minutes', 'is_active'),
        ]);
    }

    public function update(ChangeoverRuleRequest $request, ChangeoverRule $changeoverRule)
    {
        $changeoverRule->update($request->validated());

        return $this->saved(
            $request,
            redirect()->route('admin.schedule.changeover-rules.index'),
            __('Changeover rule updated.'),
        );
    }

    /** Soft-deleted, like every CRUD entity — restorable from Admin → Trash. */
    public function destroy(ChangeoverRule $changeoverRule)
    {
        abort_unless(request()->user()?->hasAnyRole(['Admin', 'Supervisor']), 403);

        $changeoverRule->delete();

        return redirect()->route('admin.schedule.changeover-rules.index')
            ->with('success', __('Changeover rule deleted.'));
    }

    /** @return array<string, mixed> */
    private function formProps(): array
    {
        return [
            'attributes' => ChangeoverRule::attributeOptions(),
            'workstationTypes' => WorkstationType::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'workstations' => Workstation::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'workstation_type_id']),
            'productTypeCodes' => \App\Models\ProductType::orderBy('code')->pluck('code')->filter()->values(),
            'productCategories' => \App\Models\ProductType::whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values(),
        ];
    }
}
