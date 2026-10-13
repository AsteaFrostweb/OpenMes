<?php

namespace App\Models;

use App\Models\Concerns\HasTenant;
use App\Models\Concerns\SoftDeletesWithAudit;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup time when a job with one attribute value follows a job with another on
 * the same resource — e.g. product family A → B takes 25 minutes. Replaces the
 * routing's standard setup for that transition; see ChangeoverResolver for
 * which rule wins when several match.
 */
class ChangeoverRule extends Model
{
    use HasFactory, HasTenant;
    use SoftDeletesWithAudit;

    /** Product type code of the job. */
    const ATTRIBUTE_PRODUCT_TYPE = 'product_type';

    /** Product type category (family) of the job. */
    const ATTRIBUTE_PRODUCT_CATEGORY = 'product_category';

    const ATTRIBUTES = [self::ATTRIBUTE_PRODUCT_TYPE, self::ATTRIBUTE_PRODUCT_CATEGORY];

    protected $fillable = [
        'tenant_id',
        'name',
        'attribute',
        'from_value',
        'to_value',
        'workstation_type_id',
        'workstation_id',
        'setup_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'setup_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function workstationType(): BelongsTo
    {
        return $this->belongsTo(WorkstationType::class);
    }

    public function workstation(): BelongsTo
    {
        return $this->belongsTo(Workstation::class);
    }

    /** @return list<array{value: string, label: string}> */
    public static function attributeOptions(): array
    {
        return [
            ['value' => self::ATTRIBUTE_PRODUCT_TYPE, 'label' => __('Product type (code)')],
            ['value' => self::ATTRIBUTE_PRODUCT_CATEGORY, 'label' => __('Product category')],
        ];
    }
}
