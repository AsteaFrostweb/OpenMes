import { __ } from '../../../lib/i18n';

export function lineFields(warehouses = []) {
    return [
        { name: 'code', label: __('Code'), required: true },
        { name: 'name', label: __('Name'), required: true },
        {
            name: 'warehouse_id', label: __('Stock location'), type: 'select',
            help: __('Consumption booked on this line is deducted from this location.'),
            options: [
                { value: '', label: __('— None —') },
                ...warehouses.map((w) => ({ value: String(w.id), label: w.name })),
            ],
        },
        {
            name: 'planning_efficiency_pct', label: __('Planning efficiency (%)'), type: 'number', min: 1, max: 100, step: 0.5,
            help: __('Share of shift capacity (after scheduled maintenance) that planning may load. 100 = no derating. Not the same as OEE.'),
        },
        { name: 'description', label: __('Description'), type: 'textarea' },
        { name: 'is_active', label: __('Active'), type: 'checkbox' },
    ];
}

/**
 * A record as form values, and with no record an empty form.
 *
 * One definition shared by Create.jsx, Edit.jsx and the list's create/edit
 * drawer, so the three can't drift on what a blank field is or how a stored
 * value is coerced for the input that shows it.
 */
export function lineInitial(record) {
    if (!record) {
        return { code: '', name: '', warehouse_id: '', planning_efficiency_pct: '100', description: '', is_active: true };
    }

    return {
        code: record.code ?? '',
        name: record.name ?? '',
        warehouse_id: record.warehouse_id != null ? String(record.warehouse_id) : '',
        planning_efficiency_pct: record.planning_efficiency_pct != null ? String(Number(record.planning_efficiency_pct)) : '100',
        description: record.description ?? '',
        is_active: !!record.is_active,
        custom_fields: record.custom_fields ?? {},
    };
}
