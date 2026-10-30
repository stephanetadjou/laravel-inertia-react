import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, Page, Panel, SelectFilter, StatStrip, Stat } from '../../components/page/Page';
import AudienceActions, { AudienceFilters } from '../../components/audience/AudienceActions';

interface Table {
    key: string;
    title: string;
    columns: string[];
    rows: (string | number)[][];
    links?: (string | null)[];
    /** Filtres des clients de chaque ligne (export, relance). */
    audiences?: (AudienceFilters | null)[];
    personal?: boolean;
}

interface Filters {
    period: string | null;
    from: string;
    to: string;
    brand: number | null;
    brand_locked: boolean;
    brand_hidden: boolean;
    store: number | null;
    family: number | null;
    active_days: number;
}

interface Totals {
    gross: number;
    discounts: number;
    returns: number;
    net: number;
    tickets: number;
    units: number;
    basket: number;
    customers: number;
    identification_rate: number;
    new_customers?: number;
    active_customers?: number;
}

interface PageProps {
    tab: string;
    tabs: { key: string; label: string }[];
    filters: Filters;
    periods: { key: string; label: string }[];
    activeWindows: number[];
    brands: { value: number; label: string }[];
    stores: { value: number; label: string }[];
    families: { value: number; label: string }[];
    currency: string;
    scopeLabel: string;
    canExport: boolean;
    audienceBase: AudienceFilters;
    data: {
        kpis?: Record<string, number | null>;
        previous?: Totals;
        active_days?: number;
        insights?: { key: string; tone: string; title: string; detail: string; action: string; link: string; audience?: AudienceFilters | null }[];
        kpi_audiences?: Record<string, { label: string; filters: AudienceFilters }>;
        monthly?: { month: string; net: number; tickets: number; customers: number }[];
        tables: Table[];
    };
    [key: string]: unknown;
}

const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

function delta(current: number, previous: number | undefined): string | undefined {
    if (!previous) return undefined;
    const d = Math.round(((current - previous) / Math.abs(previous)) * 100);
    return `${d >= 0 ? '+' : ''}${d} % vs période précédente`;
}

function cell(value: string | number, money: boolean, currency: string): string {
    if (typeof value === 'number') return money ? `${formatNumber(value)} ${currency}` : formatNumber(value);
    return value;
}

const MONEY_COLUMNS = ['CA net', 'Panier moyen', 'Retours'];

function TablePanel({ table, tab, query, canExport, currency, base }: { table: Table; tab: string; query: string; canExport: boolean; currency: string; base: AudienceFilters }) {
    const exportUrl = (format: string) => `/analytics/${tab}/export/${table.key}?${query}${query ? '&' : ''}format=${format}`;

    return (
        <Panel
            title={table.title}
            className="c12"
            actions={
                canExport && table.rows.length > 0 ? (
                    <>
                        <a className="mdash-btn ghost sm" href={exportUrl('csv')}>
                            CSV
                        </a>
                        <a className="mdash-btn ghost sm" href={exportUrl('xlsx')}>
                            Excel
                        </a>
                    </>
                ) : undefined
            }
        >
            {table.rows.length === 0 ? (
                <Empty title="Aucune donnée pour ces filtres" />
            ) : (
                <div className="mdash-tbl mpage-table">
                    <table>
                        <thead>
                            <tr>
                                {table.columns.map((c, i) => (
                                    <th key={c} className={i > 0 ? 'r' : undefined}>
                                        {c}
                                    </th>
                                ))}
                                {table.audiences && <th className="r">Clients</th>}
                            </tr>
                        </thead>
                        <tbody>
                            {table.rows.map((row, i) => (
                                <tr key={i}>
                                    {row.map((v, j) => (
                                        <td key={j} className={j > 0 ? 'r num' : 'cell-main'}>
                                            {j === 0 && table.links?.[i] ? (
                                                <Link href={table.links[i] as string} className="mdash-link">
                                                    {String(v)}
                                                </Link>
                                            ) : (
                                                cell(v, MONEY_COLUMNS.includes(table.columns[j]), currency)
                                            )}
                                        </td>
                                    ))}
                                    {table.audiences && (
                                        <td className="r">
                                            {table.audiences[i] && <AudienceActions compact filters={{ ...base, ...table.audiences[i] }} label={`${table.title} · ${row[0]}`} />}
                                        </td>
                                    )}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </Panel>
    );
}

function MonthlyBars({ monthly, currency }: { monthly: NonNullable<PageProps['data']['monthly']>; currency: string }) {
    const max = Math.max(1, ...monthly.map((m) => m.net));
    return (
        <Panel title="Évolution mensuelle du CA net" className="c12">
            <div style={{ display: 'flex', alignItems: 'flex-end', gap: 6, height: 170, padding: '10px 18px 0' }}>
                {monthly.map((m) => (
                    <div key={m.month} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 4 }} title={`${formatNumber(m.net)} ${currency} · ${formatNumber(m.tickets)} tickets`}>
                        <div style={{ width: '70%', height: Math.max(2, (Math.max(0, m.net) / max) * 130), background: 'var(--brand)', borderRadius: '4px 4px 0 0' }} />
                    </div>
                ))}
            </div>
            <div style={{ display: 'flex', gap: 6, padding: '4px 18px 16px' }}>
                {monthly.map((m) => (
                    <div key={m.month} style={{ flex: 1, textAlign: 'center', fontSize: 11, color: 'var(--muted)' }}>
                        {MONTHS[Number(m.month.slice(5, 7)) - 1]} {m.month.slice(2, 4)}
                    </div>
                ))}
            </div>
        </Panel>
    );
}

function AnalyticsIndex() {
    const { tab, tabs, filters, periods, activeWindows, brands, stores, families, currency, scopeLabel, canExport, data, audienceBase } = usePage<PageProps>().props;

    const current: Record<string, string | number | null> = {
        period: filters.period,
        from: filters.period ? null : filters.from,
        to: filters.period ? null : filters.to,
        brand: filters.brand_locked ? null : filters.brand,
        store: filters.store,
        family: filters.family,
        active_days: filters.active_days === 90 ? null : filters.active_days,
    };
    const query = new URLSearchParams(
        Object.entries(current)
            .filter(([, v]) => v !== null && v !== '')
            .map(([k, v]) => [k, String(v)]),
    ).toString();

    function apply(changes: Record<string, string | number | null>, nextTab = tab) {
        const params = Object.fromEntries(Object.entries({ ...current, ...changes }).filter(([, v]) => v !== null && v !== '')) as Record<string, string>;
        router.get(`/analytics/${nextTab}`, params, { preserveState: true, preserveScroll: true });
    }

    const k = data.kpis ?? {};
    const money = (n: number | null | undefined) => `${formatNumber(n ?? 0)} ${currency}`;
    let stats: Stat[] = [];

    if (tab === 'direction') {
        stats = [
            { label: 'CA net', value: money(k.net), hint: delta(Number(k.net), data.previous?.net) },
            { label: 'Tickets', value: Number(k.tickets), hint: delta(Number(k.tickets), data.previous?.tickets) },
            { label: 'Panier moyen', value: money(k.basket), hint: delta(Number(k.basket), data.previous?.basket) },
            { label: 'Unités', value: Number(k.units) },
            { label: 'Clients identifiés', value: Number(k.customers), hint: `${k.identification_rate} % des tickets` },
            { label: 'Nouveaux clients', value: Number(k.new_customers) },
            { label: `Clients actifs (${data.active_days} j)`, value: Number(k.active_customers) },
            { label: 'Retours', value: money(k.returns), hint: `remises ${money(k.discounts)}`, tone: Number(k.returns) > 0 ? 'warn' : undefined },
        ];
    } else if (tab === 'clientele') {
        stats = [
            { label: 'Clients identifiés', value: Number(k.buyers) },
            { label: 'Nouveaux', value: Number(k.new) },
            { label: 'Récurrents', value: Number(k.returning) },
            { label: 'Multimarques', value: Number(k.multi_brand) },
            { label: 'Entreprises', value: Number(k.business) },
        ];
    } else if (tab === 'retention') {
        stats = [
            { label: 'Clients identifiés', value: Number(k.buyers) },
            { label: 'Taux de réachat', value: `${k.repeat_rate} %`, hint: 'au moins deux jours d’achat' },
            { label: 'Délai moyen entre deux achats', value: k.average_interval === null ? '—' : `${k.average_interval} j` },
        ];
    } else if (tab === 'quality') {
        stats = [
            { label: 'Imports', value: Number(k.batches) },
            { label: 'Lignes lues', value: Number(k.rows) },
            { label: 'Rejets', value: Number(k.rejected), tone: Number(k.rejected) > 0 ? 'bad' : undefined },
            { label: 'Doublons', value: Number(k.duplicates), tone: Number(k.duplicates) > 0 ? 'warn' : undefined },
            { label: 'Ventes anonymes', value: `${k.anonymous_share} %`, hint: 'des tickets' },
            { label: 'Lignes à classer', value: `${k.unclassified_share} %`, tone: Number(k.unclassified_share) > 10 ? 'warn' : undefined },
            { label: 'Rapprochements à vérifier', value: Number(k.pending_reviews), tone: Number(k.pending_reviews) > 0 ? 'warn' : undefined },
        ];
    }

    return (
        <Page
            title="Analyses"
            subtitle={`${scopeLabel} · du ${new Date(`${filters.from}T12:00:00`).toLocaleDateString('fr-FR')} au ${new Date(`${filters.to}T12:00:00`).toLocaleDateString('fr-FR')}`}
            actions={
                canExport ? (
                    <>
                        <a className="mdash-btn ghost" href={`/analytics/report?${query}${query ? '&' : ''}print=1`} target="_blank" rel="noreferrer">
                            Rapport de direction
                        </a>
                        <a className="mdash-btn ghost" href={`/analytics/report?${query}${query ? '&' : ''}format=pdf`}>
                            PDF
                        </a>
                    </>
                ) : undefined
            }
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
                    <div className="mdash-fgroup">
                        <span>Du / au</span>
                        <div className="mdash-range">
                            <input type="date" aria-label="Date de début" value={filters.from} max={filters.to} onChange={(e) => e.target.value && apply({ period: null, from: e.target.value, to: filters.to })} />
                            <span>→</span>
                            <input type="date" aria-label="Date de fin" value={filters.to} min={filters.from} onChange={(e) => e.target.value && apply({ period: null, from: filters.from, to: e.target.value })} />
                        </div>
                    </div>
                    {!filters.brand_hidden && !filters.brand_locked && brands.length > 1 && (
                        <SelectFilter label="Marque" value={filters.brand} options={brands} allLabel="Toutes" onChange={(v) => apply({ brand: v, store: null })} />
                    )}
                    {stores.length > 1 && <SelectFilter label="Point de vente" value={filters.store} options={stores} allLabel="Tous" onChange={(v) => apply({ store: v })} />}
                    {families.length > 0 && <SelectFilter label="Famille" value={filters.family} options={families} allLabel="Toutes" onChange={(v) => apply({ family: v })} />}
                    {tab === 'direction' && (
                        <SelectFilter
                            label="Clients actifs sur"
                            value={filters.active_days}
                            options={activeWindows.map((d) => ({ value: d, label: `${d} jours` }))}
                            allLabel="90 jours"
                            onChange={(v) => apply({ active_days: v })}
                        />
                    )}
                </>
            }
        >
            <div className="mdash-seg" role="tablist" aria-label="Tableaux de bord" style={{ margin: '0 0 14px', flexWrap: 'wrap' }}>
                {tabs.map((t) => (
                    <button key={t.key} type="button" role="tab" aria-pressed={t.key === tab} onClick={() => apply({}, t.key)}>
                        {t.label}
                    </button>
                ))}
            </div>

            {stats.length > 0 && <StatStrip items={stats} />}
            {data.kpi_audiences && (
                <div className="mpage-note" style={{ display: 'flex', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
                    <b>Clients derrière les chiffres :</b>
                    {Object.entries(data.kpi_audiences).map(([key, a]) => (
                        <span key={key} style={{ display: 'inline-flex', gap: 6, alignItems: 'center' }}>
                            {a.label} <AudienceActions compact filters={{ ...audienceBase, ...a.filters }} label={a.label} />
                        </span>
                    ))}
                </div>
            )}

            <div className="mdash-grid">
                {tab === 'direction' && data.insights && data.insights.length > 0 && (
                    <Panel title="À retenir" sub="Constats calculés automatiquement sur la période et le périmètre choisis" className="c12">
                        <ul className="mpage-list" style={{ listStyle: 'none', paddingLeft: 18 }}>
                            {data.insights.map((i) => (
                                <li key={i.key}>
                                    <span className={`mdash-pill ${i.tone}`}>{i.action}</span> <b>{i.title}</b> — <span className="mpage-muted">{i.detail}</span>{' '}
                                    <Link href={i.link} className="mdash-link">
                                        Voir et agir
                                    </Link>{' '}
                                    {i.audience && <AudienceActions compact filters={{ ...audienceBase, ...i.audience }} label={i.action} />}
                                </li>
                            ))}
                        </ul>
                    </Panel>
                )}
                {tab === 'direction' && data.monthly && data.monthly.length > 1 && <MonthlyBars monthly={data.monthly} currency={currency} />}
                {data.tables.map((t) => (
                    <TablePanel key={t.key} table={t} tab={tab} query={query} canExport={canExport} currency={currency} base={audienceBase} />
                ))}
            </div>
        </Page>
    );
}

AnalyticsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AnalyticsIndex;
