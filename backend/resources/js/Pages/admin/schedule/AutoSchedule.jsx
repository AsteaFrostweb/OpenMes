import { useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Button, Checkbox, Dropdown, Icon, InlineAlert } from '@openmes/ui';
import AppLayout from '../../../layouts/AppLayout';
import AppDateTimePicker from '../../../components/AppDateTimePicker';
import useConfirm from '../../../components/useConfirm';
import { __ } from '../../../lib/i18n';
import { loadBarClass, loadPercClass } from '../../../lib/load';

// ─── Small pieces ────────────────────────────────────────────────────────────

const STATUS_TONE = {
    scheduled: 'text-om-running',
    scheduled_late: 'text-orange-600',
};

function StatusText({ op }) {
    return (
        <span className={`font-semibold ${STATUS_TONE[op.status] ?? 'text-om-blocked'}`}>
            {op.status_label}
        </span>
    );
}

/** The reasons an operation carries, minus the routine "it is scheduled" ones. */
function Reasons({ reasons = [] }) {
    if (!reasons.length) return null;

    return (
        <ul className="mt-0.5 space-y-0.5">
            {reasons.map((r, i) => (
                <li key={i} className="text-[11px] text-om-muted">
                    {r.message}
                    {r.code === 'material' && Array.isArray(r.shortages) && r.shortages.length > 0 && (
                        <span>
                            {' '}
                            ({r.shortages.map((s) => `${s.material_code}: −${s.missing_qty} ${s.unit_of_measure ?? ''}`.trim()).join(', ')})
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

function Tile({ label, value, tone = 'text-om-ink' }) {
    return (
        <div className="border border-om-line rounded-om-sm bg-om-panel px-3 py-2 min-w-[110px]">
            <div className="text-[10px] uppercase tracking-wide text-om-muted">{label}</div>
            <div className={`text-xl font-bold ${tone}`}>{value}</div>
        </div>
    );
}

const hours = (minutes) => (minutes == null ? '—' : `${Math.round((minutes / 60) * 10) / 10} h`);

// ─── Workstation override (manual selection, audited server-side) ────────────

function StationCell({ op, stationsByType, canDecide }) {
    const options = op.workstation_type_id ? stationsByType[op.workstation_type_id] ?? [] : [];
    const [value, setValue] = useState(op.workstation_id ? String(op.workstation_id) : '');
    const [error, setError] = useState(null);

    const label = op.workstation_name ?? op.line_name ?? '—';
    const source = {
        fixed: __('fixed'),
        auto: __('auto'),
        manual: __('manual'),
        line: __('whole line'),
    }[op.resource_source];

    if (!canDecide || !op.scheduled || options.length < 2) {
        return (
            <span>
                {label}
                {source && <span className="text-om-muted text-[10px]"> · {source}</span>}
            </span>
        );
    }

    // A refused move (station busy in this window, wrong class) comes back as a
    // validation error. Keep this cell's state through that visit so the reason
    // shows next to the select instead of the choice silently snapping back.
    const save = (next) => {
        setValue(next);
        setError(null);
        if (next && next !== String(op.workstation_id)) {
            router.put(`/admin/schedule/operations/${op.id}`, { workstation_id: next }, {
                preserveScroll: true,
                preserveState: 'errors',
                onError: (errors) => {
                    setValue(op.workstation_id ? String(op.workstation_id) : '');
                    setError(errors.workstation_id ?? __('The operation could not be moved.'));
                },
            });
        }
    };

    return (
        <span className="inline-flex flex-col gap-0.5">
            <span className="inline-flex items-center gap-1">
                <Dropdown
                    size="sm"
                    className="min-w-[190px]"
                    options={options.map((st) => ({ value: String(st.id), label: st.line_name ? `${st.name} · ${st.line_name}` : st.name }))}
                    value={value}
                    onChange={save}
                    aria-label={__('Workstation for :order step :step', { order: op.order_no, step: op.step_number })}
                />
                {source && <span className="text-om-muted text-[10px]">{source}</span>}
            </span>
            {error && <span role="alert" className="text-[11px] text-om-blocked">{error}</span>}
        </span>
    );
}

function OperationsTable({ operations, stationsByType, canDecide, emptyText }) {
    if (!operations.length) {
        return <div className="px-3 py-6 text-center text-xs text-om-muted">{emptyText}</div>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs border-collapse">
                <thead>
                    <tr className="bg-om-panel text-om-muted">
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line">{__('Work order')}</th>
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line">{__('Step')}</th>
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line">{__('Resource')}</th>
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line whitespace-nowrap">{__('Start')}</th>
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line whitespace-nowrap">{__('End')}</th>
                        <th className="text-right px-3 py-2 font-semibold border-b border-om-line">{__('Duration')}</th>
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line">{__('Status')}</th>
                    </tr>
                </thead>
                <tbody>
                    {operations.map((op) => (
                        <tr key={op.id} className="align-top hover:bg-om-line2/30">
                            <td className="px-3 py-2 border-b border-om-line whitespace-nowrap">
                                <Link href={`/admin/work-orders/${op.work_order_id}`} className="font-semibold text-om-ink hover:underline">{op.order_no}</Link>
                            </td>
                            <td className="px-3 py-2 border-b border-om-line">
                                <span className="font-mono text-om-muted">{op.step_number}</span> {op.step_name}
                            </td>
                            <td className="px-3 py-2 border-b border-om-line">
                                <StationCell op={op} stationsByType={stationsByType} canDecide={canDecide} />
                            </td>
                            <td className="px-3 py-2 border-b border-om-line font-mono whitespace-nowrap">{op.planned_start_label ?? '—'}</td>
                            <td className="px-3 py-2 border-b border-om-line font-mono whitespace-nowrap">{op.planned_end_label ?? '—'}</td>
                            <td className="px-3 py-2 border-b border-om-line text-right whitespace-nowrap">
                                {hours(op.duration_minutes)}
                                {op.setup_minutes ? <div className="text-[10px] text-om-muted">{__(':min min setup', { min: op.setup_minutes })}</div> : null}
                            </td>
                            <td className="px-3 py-2 border-b border-om-line min-w-[200px]">
                                <StatusText op={op} />
                                <Reasons reasons={op.reasons.filter((r) => r.code !== 'in_progress' || !op.scheduled)} />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function ResourceLoads({ resources }) {
    if (!resources.length) return null;

    return (
        <div className="overflow-x-auto">
            <table className="min-w-full text-xs border-collapse">
                <thead>
                    <tr className="bg-om-panel text-om-muted">
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line">{__('Resource')}</th>
                        <th className="text-right px-3 py-2 font-semibold border-b border-om-line">{__('Booked')}</th>
                        <th className="text-right px-3 py-2 font-semibold border-b border-om-line">{__('Effective capacity')}</th>
                        <th className="text-left px-3 py-2 font-semibold border-b border-om-line w-48">{__('Load')}</th>
                    </tr>
                </thead>
                <tbody>
                    {resources.map((r) => (
                        <tr key={r.key}>
                            <td className="px-3 py-2 border-b border-om-line">
                                {r.name}
                                <span className="text-om-muted"> · {r.type === 'line' ? __('Line') : __('Workstation')}</span>
                            </td>
                            <td className="px-3 py-2 border-b border-om-line text-right">{hours(r.booked_minutes)}</td>
                            <td className="px-3 py-2 border-b border-om-line text-right">{hours(r.available_minutes)}</td>
                            <td className="px-3 py-2 border-b border-om-line">
                                <div className="flex items-center gap-2">
                                    <div className="h-1.5 flex-1 rounded-full bg-om-line2 overflow-hidden">
                                        <div className={`h-full ${loadBarClass(r.load_pct)}`} style={{ width: `${Math.min(100, r.load_pct ?? 0)}%` }} />
                                    </div>
                                    <span className={`text-[11px] font-bold ${loadPercClass(r.load_pct)}`}>{r.load_pct == null ? '—' : `${r.load_pct}%`}</span>
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

// ─── Generate form ───────────────────────────────────────────────────────────

function GenerateForm({ defaults, canDecide }) {
    const form = useForm({
        start: defaults.start,
        horizon_days: defaults.horizon_days,
        scope: defaults.scope,
        respect_material: defaults.respect_material,
        respect_workforce: defaults.respect_workforce ?? true,
    });

    const submit = (e) => {
        e.preventDefault();
        form.post('/admin/schedule/simulations', { preserveScroll: true });
    };

    // Same field anatomy as ResourceForm, so this reads like every other form.
    const label = 'block font-mono text-[9.5px] uppercase tracking-[0.08em] text-om-faint mb-[7px]';
    const input = 'w-full bg-om-bg border border-om-line rounded-om-sm px-3 py-2.5 text-[13px] text-om-ink outline-none focus:border-om-accent focus:ring-[3px] focus:ring-[rgba(234,90,43,.12)]';
    const error = (key) => form.errors[key] && <p className="mt-1 text-[11.5px] text-om-blocked">{form.errors[key]}</p>;

    return (
        <form onSubmit={submit} className="border border-om-line rounded-om-sm bg-om-panel p-4 space-y-4">
            <div className="text-sm font-bold text-om-ink">{__('Simulate a schedule')}</div>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-[minmax(260px,1.2fr)_120px_minmax(240px,1fr)]">
                <div>
                    <div className={label}>{__('Start')}</div>
                    <AppDateTimePicker value={form.data.start} onChange={(v) => form.setData('start', v)} aria-label={__('Start')} />
                    {error('start')}
                </div>
                <div>
                    <label htmlFor="auto-horizon" className={label}>{__('Horizon (days)')}</label>
                    <input
                        id="auto-horizon"
                        type="number" min={1} max={defaults.max_horizon_days}
                        value={form.data.horizon_days}
                        onChange={(e) => form.setData('horizon_days', e.target.value)}
                        className={input}
                    />
                    {error('horizon_days')}
                </div>
                <div>
                    <div className={label}>{__('Orders to schedule')}</div>
                    <Dropdown
                        className="w-full"
                        options={[
                            { value: 'unplanned', label: __('Only orders without a time plan') },
                            { value: 'all', label: __('Replan every order not yet started') },
                        ]}
                        value={form.data.scope}
                        onChange={(v) => form.setData('scope', v)}
                        aria-label={__('Orders to schedule')}
                    />
                    {error('scope')}
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-x-6 gap-y-3">
                <Checkbox
                    checked={!!form.data.respect_material}
                    onChange={(v) => form.setData('respect_material', v)}
                    label={__('Hold orders that stock cannot cover')}
                />
                <Checkbox
                    checked={!!form.data.respect_workforce}
                    onChange={(v) => form.setData('respect_workforce', v)}
                    label={__('Count available operators')}
                />
                <div className="ml-auto">
                    <Button type="submit" disabled={!canDecide || form.processing} leftIcon={<Icon name="calendar-cog" size={14} />}>
                        {form.processing ? __('Calculating…') : __('Simulate')}
                    </Button>
                </div>
            </div>
            <p className="text-[11px] text-om-muted">
                {__('Places each remaining step in the earliest slot that fits shifts, maintenance, planning efficiency, already planned work, step order, material, eligible workstations and operators. Nothing changes until you apply the result.')}
            </p>
            {!canDecide && (
                <InlineAlert severity="info" title={__('Only a supervisor or administrator can simulate or apply schedules.')} />
            )}
        </form>
    );
}

// ─── Page ────────────────────────────────────────────────────────────────────

export default function AutoSchedule() {
    const { draft, committed = [], stationsByType = {}, defaults, canDecide } = usePage().props;
    const { confirm, dialog } = useConfirm();
    const [showOnlyProblems, setShowOnlyProblems] = useState(false);

    const counts = draft?.counts ?? {};
    const placed = (counts.scheduled ?? 0) + (counts.scheduled_late ?? 0);
    const late = counts.scheduled_late ?? 0;
    const infeasible = Object.entries(counts).filter(([k]) => k.startsWith('infeasible_')).reduce((a, [, v]) => a + v, 0);
    const draftOps = (draft?.operations ?? []).filter((op) => !showOnlyProblems || op.status !== 'scheduled');

    const apply = () => confirm(
        {
            title: __('Apply this schedule?'),
            body: __('Fully schedulable work orders get the simulated time plan and workstations. Orders with infeasible steps keep their current placement. Each change is logged and can be undone from the planner.'),
            confirmLabel: __('Apply'),
        },
        () => router.post(`/admin/schedule/simulations/${draft.id}/apply`, {}, { preserveScroll: true }),
    );
    const discard = () => confirm(
        { title: __('Discard this simulation?'), body: __('The committed schedule is not affected.'), confirmLabel: __('Discard') },
        () => router.post(`/admin/schedule/simulations/${draft.id}/discard`, {}, { preserveScroll: true }),
    );

    return (
        <>
            <Head title={__('Automatic scheduling')} />

            <div className="p-4 space-y-4">
                <div className="flex items-center justify-between flex-wrap gap-2">
                    <h1 className="text-lg font-bold text-om-ink">{__('Automatic scheduling')}</h1>
                    <div className="flex items-center gap-3 text-xs">
                        <Link href="/admin/schedule" className="text-om-accent hover:underline">{__('Planner')} &rarr;</Link>
                        <Link href="/admin/schedule/capacity" className="text-om-accent hover:underline">{__('Capacity')} &rarr;</Link>
                        <Link href="/admin/schedule/changeover-rules" className="text-om-accent hover:underline">{__('Changeover Rules')} &rarr;</Link>
                    </div>
                </div>

                <GenerateForm defaults={defaults} canDecide={canDecide} />

                {draft && (
                    <section className="border border-om-accent rounded-om-sm bg-om-panel">
                        <div className="flex items-start justify-between flex-wrap gap-3 p-3 border-b border-om-line">
                            <div>
                                <div className="text-sm font-bold text-om-ink">{__('Simulation — not applied')}</div>
                                <div className="text-[11px] text-om-muted">
                                    {__('Horizon :range', { range: draft.horizon_label })}
                                    {' · '}
                                    {__('Generated :at by :user', { at: draft.created_label, user: draft.created_by ?? '—' })}
                                </div>
                            </div>
                            {canDecide && (
                                <div className="flex items-center gap-2">
                                    <Button variant="outline" size="sm" onClick={discard}>{__('Discard')}</Button>
                                    <Button size="sm" onClick={apply} disabled={placed === 0}>{__('Apply')}</Button>
                                </div>
                            )}
                        </div>

                        <div className="flex flex-wrap gap-2 p-3">
                            <Tile label={__('Placed')} value={placed} tone="text-om-running" />
                            <Tile label={__('Late')} value={late} tone={late ? 'text-orange-600' : 'text-om-ink'} />
                            <Tile label={__('Infeasible')} value={infeasible} tone={infeasible ? 'text-om-blocked' : 'text-om-ink'} />
                            <Tile label={__('Work orders')} value={draft.work_orders.length} />
                        </div>

                        {draft.resources.length > 0 && (
                            <div className="px-3 pb-3">
                                <div className="text-xs font-semibold text-om-ink mb-1">{__('Resource load over the horizon')}</div>
                                <div className="border border-om-line rounded-om-sm"><ResourceLoads resources={draft.resources} /></div>
                            </div>
                        )}

                        <div className="px-3 pb-3">
                            <div className="flex items-center justify-between mb-1">
                                <div className="text-xs font-semibold text-om-ink">{__('Operations')}</div>
                                <Checkbox size="sm" checked={showOnlyProblems} onChange={setShowOnlyProblems} label={__('Only late or infeasible')} />
                            </div>
                            <div className="border border-om-line rounded-om-sm">
                                <OperationsTable
                                    operations={draftOps}
                                    stationsByType={stationsByType}
                                    canDecide={canDecide}
                                    emptyText={__('No work orders to schedule — every active order already has a time plan, or none has a routing.')}
                                />
                            </div>
                        </div>
                    </section>
                )}

                <section className="border border-om-line rounded-om-sm bg-om-panel">
                    <div className="p-3 border-b border-om-line">
                        <div className="text-sm font-bold text-om-ink">{__('Committed operation plan')}</div>
                        <div className="text-[11px] text-om-muted">
                            {__('Operations of active work orders from applied schedules. Moving an order by hand on the planner removes its rows here; the next simulation works around its new time plan.')}
                        </div>
                    </div>
                    <OperationsTable
                        operations={committed}
                        stationsByType={stationsByType}
                        canDecide={canDecide}
                        emptyText={__('No schedule has been applied yet.')}
                    />
                </section>
            </div>
            {dialog}
        </>
    );
}

AutoSchedule.layout = (page) => <AppLayout>{page}</AppLayout>;
