<?php

namespace App\Observers;

use App\Models\ScheduledOperation;
use App\Models\WorkOrder;

/**
 * Keeps the committed operation plan honest when an order is moved by hand.
 *
 * Once a planner drags an order on the board (or the edit form or an import
 * moves it), the operation times the scheduler committed for it no longer
 * describe where it runs. They are dropped, so the next scheduling run books
 * the order's actual minute plan instead of a stale one. Applying a simulation
 * moves the order first and commits its operations afterwards, so this never
 * removes what an apply has just written.
 */
class ScheduledOperationInvalidationObserver
{
    public function updated(WorkOrder $workOrder): void
    {
        if ($workOrder->wasChanged(['line_id', 'planned_start_at', 'planned_end_at'])) {
            ScheduledOperation::committed()->where('work_order_id', $workOrder->id)->delete();
        }
    }
}
