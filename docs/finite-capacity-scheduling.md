# Finite-capacity scheduling

OpenMES can calculate a feasible production schedule from the data it already
holds. It does not replace the planner board. Drag & drop keeps working, and
nothing changes on the shop floor until a Supervisor or Admin applies a
generated plan.

Open it from **Schedule → Planner → Automatic scheduling** (`/admin/schedule/auto`).

The screens sit under the **Schedule** tab of the admin panel. An Admin has it;
a Supervisor gets it only once it is granted in **Settings → Access matrix**
(by default a Supervisor sees just the order screens). The same applies to the
planner board itself.

## Effective planning capacity

Each line has a **planning efficiency (%)**, editable on the line form. The
default is 100.

```text
calendar capacity  = active shift time − scheduled maintenance
effective capacity = calendar capacity × planning efficiency
```

The capacity view shows both figures. Load and overload are measured against
effective capacity. Crews read the same factor from the workforce provider when
it exposes `planning_efficiency_pct`.

The capacity view has three axes: line, crew and **workstation**. The
workstation axis compares each station's effective capacity (its line's shifts
minus line and station maintenance, × the line's efficiency) with the operations
committed on it by applied automatic schedules. Work that was only ever placed
by hand has no operations, so it shows on the line axis and not here.

On the line and crew axes, an order the scheduler placed counts by its
committed operations: each step loads the line of the station that runs it, as
standard work (the working time it occupies × the line's efficiency). Its
overall time window is not counted, because after automatic scheduling it
also spans steps on other lines and the waits between them. An order placed
only by hand still counts by its time window on its own line.

Planning efficiency is deliberately **not OEE**. Calendar capacity already
removes the availability losses OpenMES knows about. Multiplying by a full OEE
figure would count those losses twice. A value derived from historical OEE may
inform the setting, but nothing changes it automatically.

## What gets scheduled

The scheduler works on operations, not whole work orders. Each remaining step of
each active order's **frozen process snapshot** becomes one operation. The same
snapshot is used to create batch steps, so there is no second routing model.

When batches already exist:

- finished steps are left out;
- a running step is pinned from the start of the horizon for the rest of its estimate;
- a workstation already assigned to a batch step is treated as fixed.

Duration comes from the step's standard times when present:
`setup + run per unit × planned quantity`. Otherwise the step's estimated
duration is used. A step with neither is reported as having no estimate. The
scheduler never guesses a duration.

## How a slot is chosen

The heuristic is earliest-feasible-slot. It is deterministic: the same data
always gives the same plan.

1. Orders are taken by priority (highest first), then priority score, then the
   earliest due date, then id.
2. If stock cannot cover an order (the same shortage check the planner shows),
   none of its operations are placed. Stock is netted across the run in
   scheduling order: an order that is covered takes its quantities before the
   next one is checked, so two orders cannot both count on the same stock. An
   order that is short takes nothing. No material-ready date is invented, so
   the order stays blocked by material.
3. Steps follow routing order. Each step starts no earlier than the previous
   step ends. If a step cannot be placed, every later step of that order is
   reported as `infeasible_predecessor`. Richer dependency graphs (parallel
   operations, lags) belong to #15.
4. Eligible resources for a step are tried in this order:
   - the workstation fixed by the routing or the batch step;
   - otherwise the active workstations of its equipment class (`workstation_type_id`);
   - otherwise the order's line as a whole.

   The operation lands on whichever resource finishes it first. Ties go to the
   order's own line, then to the lower workstation id. A workstation of another
   class is never used.
5. On a resource, a slot must meet all of these conditions:
   - it fits the shift calendar, minus line or station maintenance, stretched by
     the line's planning efficiency;
   - it does not overlap committed work;
   - it leaves enough operators, and enough qualified operators when the step
     requires skills. This only applies where the workforce provider tracks people.

   An operation may pause across non-working time, but it never interleaves
   with another job.

Committed work means one of the following:

- operations committed by an earlier apply;
- the minute plan of an order that was never scheduled operation by operation.

Scope `unplanned` (the default) places only orders without a time plan. Scope
`all` replans every active order that has not started.

## Workforce and skills

People are counted through `App\Extension\Contracts\WorkforceCapacityProvider`.

```php
public function availableOperators(?int $lineId, CarbonInterface $start, CarbonInterface $end): ?int;
public function qualifiedOperators(?int $lineId, CarbonInterface $start, CarbonInterface $end, array $skillIds): ?int;
```

A return value of `null` means "not tracked" and never constrains the schedule.
Core binds a null implementation, so scheduling stays machine-constrained until a
workforce module binds a real one. This contract is separate from
`WorkforceProvider` so that existing implementations of that interface keep
loading.

A step's required skills come from its process segment. They are frozen into
the work-order snapshot as `required_skill_ids`, so later edits to the segment
do not change who may run an order that has already been released. A step
without skills is never constrained by qualification.

## Changeover rules

**Schedule → Automatic scheduling → Changeover Rules** defines optional,
sequence-dependent setup times. A rule matches one attribute of the previous job
and the next job on the same resource. The attribute is either the product type
code or the product category. Either side may be left empty to mean "any". A rule
can be narrowed to one equipment class or one workstation.

When several rules match, the most specific one wins. Specificity is ranked
from highest to lowest:

1. a rule for this workstation;
2. a rule for this equipment class;
3. a named "from" value;
4. a named "to" value.

The winning rule's minutes replace the routing's standard setup for that
transition. With no rule, the standard `setup_time_minutes` applies unchanged.
The scheduler accounts for setup time, but it does not reorder jobs to
minimise it. An idle gap before a job that is already placed is used only if
the new job does not lengthen that job's changeover beyond the setup it
reserved.

## What-if workflow

1. **Simulate**: this stores a draft. Only one draft exists at a time, and a new
   one discards the previous. The draft does not change placements, timestamps,
   workstation assignments or batch steps.
2. **Review**: check placed, late and infeasible counts, resource load over the
   horizon, and every operation with its reasons.
3. **Apply** (Supervisor/Admin):
   - Each fully schedulable order gets its new `planned_start_at` /
     `planned_end_at`, and a line if it had none: the line where most of its
     scheduled time runs. Steps on another line's workstations are reported
     as one count. These are written through the
     planner's own update path, so the change log, undo, `WorkOrderScheduled`
     and the audit trail work as they do for a drag on the board.
   - `due_date` is never changed.
   - Stations picked from an equipment class are assigned to batch steps through
     pool dispatch.
   - Orders with infeasible steps keep their placement. Their reasons are still
     committed.
   - A draft is refused if any of its orders changed after it was generated.
4. **Discard**: removes the draft and leaves the committed schedule untouched.

A Supervisor/Admin can move a scheduled operation to another active workstation
of the same equipment class. Such overrides are audited.

Moving an order by hand on the planner drops its committed operations. The next
run then works around its new minute plan.

## Statuses and reasons

Each operation has a `status`:

- `scheduled`
- `scheduled_late`
- `infeasible_resource_capacity`
- `infeasible_material`
- `infeasible_workforce_capacity`
- `infeasible_qualification`
- `infeasible_no_eligible_workstation`
- `infeasible_predecessor`
- `infeasible_unestimated`

It also has a list of `reasons`. Each reason contains a machine-readable `code`
(`resource_capacity`, `material`, `late`, `changeover`, …), a translated
`message`, and details where they apply: `shortages`, `material_ready_at: null`,
`due_date` or `changeover_rule_id`.

An order is late when its last operation ends after the end of its `due_date`.

## API

All endpoints require the `Admin` or `Supervisor` role (Sanctum).

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/schedule/operations` | Committed operation plan. Filters: `work_order_id`, `workstation_id`, `line_id`, `from`, `to`, `status[]` |
| POST | `/api/v1/schedule/simulations` | Generate a draft (`start`, `horizon_days` ≤ 90, `scope`, `respect_material`, `work_order_ids[]`) |
| GET | `/api/v1/schedule/simulations/{id}` | A simulation with summary and operations |
| POST | `/api/v1/schedule/simulations/{id}/apply` | Commit a draft (409 when stale or not a draft) |
| POST | `/api/v1/schedule/simulations/{id}/discard` | Discard a draft |
| PUT | `/api/v1/schedule/operations/{id}` | Override the workstation (`workstation_id`) |

The completion forecast (#241) and schedule suggestions (#39) should read
`GET /schedule/operations`, or call `FiniteScheduler::schedule()` directly.
They should not re-implement constraints. Schedule feasibility stays
deterministic and independent of any AI feature.
