import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { formatCompact, formatNumber } from '../../components/dashboard/Charts';
import { Empty, Page, Panel, SelectFilter } from '../../components/page/Page';
import AudienceActions, { AudienceFilters } from '../../components/audience/AudienceActions';
import './pilotage.css';

/*
 * Pilotage : onglets, filtres communs, filtrage croisé (un clic sur une barre
 * applique son filtre à toute la page), comparaison à la période précédente
 * et à N-1, listes exportables et enregistrables en groupe.
 */

interface KpiData {
    label: string;
    value: number;
    unit: 'fcfa' | '%' | 'n' | 'x';
    previous: number | null;
    last_year: number | null;
    inverted: boolean;
}

interface ChartData {
    key: string;
    title: string;
    filter: string | null;
    unit: string;
    items: { id: string | number | null; label: string; value: number }[];
}

interface ListData {
    key: string;
    title: string;
    columns: string[];
    rows: (string | number)[][];
    audience?: AudienceFilters;
    audiences?: (AudienceFilters | null)[];
}

type Option = { value: string | number; label: string };

interface PageProps {
    tab: string;
    tabs: { key: string; label: string }[];
    filters: { period: string | null; from: string; to: string; brand: number | null; brand_locked: boolean; brand_hidden: boolean; store: number | null; family: number | null; segment: string | null; loyalty_status: string | null };
    periods: { key: string; label: string }[];
    brands: Option[];
    stores: Option[];
    families: Option[];
    segments: Option[];
    loyaltyStatuses: Option[];
    scopeLabel: string;
    currency: string;
    canExport: boolean;
    data: { kpis: KpiData[]; charts: ChartData[]; lists: ListData[]; monthly?: { month: string; label: string; net: number; last_year: number }[] };
    [key: string]: unknown;
}

function format(value: number, unit: string, currency: string): string {
    if (unit === 'fcfa') return `${formatNumber(value)} ${currency}`;
    if (unit === '%') return `${value} %`;
    if (unit === 'x') return value.toLocaleString('fr-FR', { maximumFractionDigits: 1 });
    return formatNumber(value);
}

function Change({ label, value, base, inverted }: { label: string; value: number; base: number | null; inverted: boolean }) {
    if (base === null) return null;
    const change = base === 0 ? null : ((value - base) / Math.abs(base)) * 100;
    const good = change !== null && (inverted ? change <= 0 : change >= 0);

    return (
        <span className={`mbi-change ${change === null || Math.round(change) === 0 ? '' : good ? 'up' : 'down'}`}>
            {label} {change === null ? '—' : `${change > 0 ? '+' : ''}${Math.round(change)} %`}
        </span>
    );
}

function Bars({ chart, active, onPick, currency }: { chart: ChartData; active: string | number | null; onPick: (id: string | number | null) => void; currency: string }) {
    const max = Math.max(1, ...chart.items.map((i) => Math.abs(i.value)));

    return (
        <div className="mbi-bars">
            {chart.items.map((item) => {
                const content = (
                    <>
                        <span className="mbi-bar-label">{item.label}</span>
                        <span className="mbi-bar-track">
                            <i style={{ width: `${Math.max(1, (Math.abs(item.value) / max) * 100)}%` }} />
                        </span>
                        <span className="mbi-bar-value num">{chart.unit === 'fcfa' ? `${formatCompact(item.value)} ${currency}` : formatNumber(item.value)}</span>
                    </>
                );

                return chart.filter && item.id !== null ? (
                    <button key={String(item.id)} type="button" className={`mbi-bar ${String(active) === String(item.id) ? 'on' : ''}`} onClick={() => onPick(item.id)} title="Filtrer toute la page">
                        {content}
                    </button>
                ) : (
                    <div key={String(item.id)} className="mbi-bar">
                        {content}
                    </div>
                );
            })}
        </div>
    );
}

function Monthly({ rows, currency }: { rows: NonNullable<PageProps['data']['monthly']>; currency: string }) {
    const max = Math.max(1, ...rows.flatMap((r) => [r.net, r.last_year]));

    return (
        <div className="mbi-months" role="img" aria-label="CA net par mois, comparé à l’année précédente">
            {rows.map((r) => (
                <div key={r.month} className="mbi-month" title={`${r.label} : ${formatNumber(r.net)} ${currency} (N-1 : ${formatNumber(r.last_year)} ${currency})`}>
                    <div className="mbi-month-bars">
                        <i className="n1" style={{ height: `${(r.last_year / max) * 100}%` }} />
                        <i style={{ height: `${(r.net / max) * 100}%` }} />
                    </div>
                    <span>{r.label}</span>
                </div>
            ))}
        </div>
    );
}

function Pilotage() {
    const { tab, tabs, filters, periods, brands, stores, families, segments, loyaltyStatuses, scopeLabel, currency, canExport, data } = usePage<PageProps>().props;

    const current: Record<string, string | number | null> = {
        period: filters.period,
        from: filters.period ? null : filters.from,
        to: filters.period ? null : filters.to,
        brand: filters.brand_locked ? null : filters.brand,
        store: filters.store,
        family: filters.family,
        segment: filters.segment,
        loyalty_status: filters.loyalty_status,
    };

    const params = (changes: Record<string, string | number | null> = {}) =>
        Object.fromEntries(Object.entries({ ...current, ...changes }).filter(([, v]) => v !== null && v !== '').map(([k, v]) => [k, String(v)]));
    const query = new URLSearchParams(params()).toString();
    const apply = (changes: Record<string, string | number | null>, nextTab = tab) => router.get(`/pilotage/${nextTab}`, params(changes), { preserveState: true, preserveScroll: true });
    const pick = (filter: string, id: string | number | null) => apply({ [filter]: String(current[filter]) === String(id) ? null : id, ...(filter === 'brand' ? { store: null } : {}) });

    const labelOf = (options: Option[], value: string | number | null) => options.find((o) => String(o.value) === String(value))?.label.replace('— ', '');
    const chips = [
        ['brand', labelOf(brands, filters.brand_locked ? null : filters.brand)],
        ['store', labelOf(stores, filters.store)],
        ['family', labelOf(families, filters.family)],
        ['segment', labelOf(segments, filters.segment)],
        ['loyalty_status', labelOf(loyaltyStatuses, filters.loyalty_status)],
    ].filter(([, label]) => label) as [string, string][];

    return (
        <Page
            title="Pilotage"
            subtitle={`${scopeLabel} · du ${new Date(`${filters.from}T12:00:00`).toLocaleDateString('fr-FR')} au ${new Date(`${filters.to}T12:00:00`).toLocaleDateString('fr-FR')}`}
            filters={
                <>
                    <div className="mdash-fgroup">
                        <span>Période</span>
                        <div className="mdash-seg" role="group" aria-label="Période">
                            {periods.map((p) => (
                                <button key={p.key} type="button" aria-pressed={filters.period === p.key} onClick={() => apply({ period: p.key, from: null, to: null })}>
                                    {p.label}
                                </button>
                            ))}
                        </div>
                    </div>
                    {!filters.brand_hidden && <SelectFilter label="Enseigne" value={filters.brand_locked ? '' : (filters.brand ?? '')} options={brands} allLabel="Toutes les enseignes" onChange={(v) => apply({ brand: v, store: null })} />}
                    <SelectFilter label="Magasin" value={filters.store ?? ''} options={stores} allLabel="Tous les magasins" onChange={(v) => apply({ store: v })} />
                    <SelectFilter label="Segment RFM" value={filters.segment ?? ''} options={segments} allLabel="Tous les segments" onChange={(v) => apply({ segment: v })} />
                    <SelectFilter label="Famille" value={filters.family ?? ''} options={families} allLabel="Toutes les familles" onChange={(v) => apply({ family: v })} />
                    {loyaltyStatuses.length > 0 && <SelectFilter label="Statut fidélité" value={filters.loyalty_status ?? ''} options={loyaltyStatuses} allLabel="Tous les statuts" onChange={(v) => apply({ loyalty_status: v })} />}
                </>
            }
        >
            <nav className="mbi-tabs" aria-label="Pages du pilotage">
                {tabs.map((t) => (
                    <Link key={t.key} href={`/pilotage/${t.key}`} data={params()} preserveState preserveScroll className={t.key === tab ? 'on' : ''}>
                        {t.label}
                    </Link>
                ))}
            </nav>

            {chips.length > 0 && (
                <div className="mbi-chips">
                    {chips.map(([key, label]) => (
                        <button key={key} type="button" className="mdash-btn ghost sm" onClick={() => apply({ [key]: null })}>
                            {label} ×
                        </button>
                    ))}
                </div>
            )}

            <div className="mbi-kpis">
                {data.kpis.map((k) => (
                    <div key={k.label} className="mbi-kpi">
                        <span>{k.label}</span>
                        <b className="num">{format(k.value, k.unit, currency)}</b>
                        <small>
                            <Change label="préc." value={k.value} base={k.previous} inverted={k.inverted} />
                            <Change label="N-1" value={k.value} base={k.last_year} inverted={k.inverted} />
                        </small>
                    </div>
                ))}
            </div>

            <div className="mdash-grid">
                {data.monthly && (
                    <Panel title="CA net par mois" sub="Comparé à la même période de l’année précédente (barres claires)" className="c12">
                        <Monthly rows={data.monthly} currency={currency} />
                    </Panel>
                )}
                {data.charts.map((chart) => (
                    <Panel key={chart.key} title={chart.title} sub={chart.filter ? 'Cliquez pour filtrer toute la page' : undefined} className="c6">
                        {chart.items.length === 0 ? <Empty title="Rien sur cette sélection" /> : <Bars chart={chart} active={chart.filter ? current[chart.filter] : null} onPick={(id) => pick(chart.filter as string, id)} currency={currency} />}
                    </Panel>
                ))}
                {data.lists.map((list) => (
                    <Panel
                        key={list.key}
                        title={list.title}
                        className="c12"
                        actions={
                            <>
                                {canExport && list.rows.length > 0 && (
                                    <a className="mdash-btn ghost sm" href={`/pilotage/${tab}/export/${list.key}?${query}`}>
                                        Excel
                                    </a>
                                )}
                                {list.audience && list.rows.length > 0 && <AudienceActions compact filters={list.audience} label={list.title} />}
                            </>
                        }
                    >
                        {list.rows.length === 0 ? (
                            <Empty title="Aucune ligne sur cette sélection" />
                        ) : (
                            <div className="mdash-tbl mpage-table">
                                <table>
                                    <thead>
                                        <tr>
                                            {list.columns.map((c) => (
                                                <th key={c}>{c}</th>
                                            ))}
                                            {list.audiences && <th />}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {list.rows.map((row, i) => (
                                            <tr key={i}>
                                                {row.map((cell, j) => (
                                                    <td key={j} className={typeof cell === 'number' ? 'mono' : ''}>
                                                        {typeof cell === 'number' ? formatNumber(cell) : cell}
                                                    </td>
                                                ))}
                                                {list.audiences && <td className="r">{list.audiences[i] && <AudienceActions compact filters={list.audiences[i] as AudienceFilters} label={`${list.title} · ${row[0]}`} />}</td>}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>
                ))}
            </div>
        </Page>
    );
}

Pilotage.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default Pilotage;
