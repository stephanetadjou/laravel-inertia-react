import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, formatDay, Page, Pager, Panel, SearchFilter, SelectFilter } from '../../components/page/Page';
import { Redeem } from '../Customers/LoyaltyCardsPanel';

/*
 * Clients › Fidélité : une ligne par client et par enseigne, en trois
 * onglets (récompense atteinte, proche, à venir).
 */

type Option = { value: number | string; label: string };

interface Row {
    id: number;
    customer_id: number;
    name: string;
    brand: string;
    points: number;
    reward: string | null;
    tier_points: number | null;
    reached_on: string | null;
    expires_on: string | null;
    next: string | null;
    missing: number | null;
    amount_left: number | null;
    estimated_on: string | null;
    stores: { id: number; name: string }[];
    tiers: { points: number; percent: number; label: string; cost: number }[];
}

interface PageProps {
    programs: Option[];
    brands: Option[];
    stores: Option[];
    segments: Option[];
    filters: { tab: string; program: number | null; brand: number | null; store: number | null; segment: string | null; q: string; sort: string | null; dir: string };
    counts: Record<string, number>;
    cards: React.ComponentProps<typeof Pager>['page'];
    canExport: boolean;
    [key: string]: unknown;
}

const TABS = [
    { key: 'reached', label: 'Récompense atteinte' },
    { key: 'near', label: 'Proche' },
    { key: 'future', label: 'À venir' },
];

const COLUMNS: Record<string, { key: string; label: string; right?: boolean }[]> = {
    reached: [
        { key: 'name', label: 'Client' },
        { key: 'brand', label: 'Enseigne' },
        { key: 'points', label: 'Points', right: true },
        { key: 'reward', label: 'Récompense' },
        { key: 'reached_on', label: 'Atteinte le' },
        { key: 'expires_on', label: 'Valable jusqu’au' },
    ],
    near: [
        { key: 'name', label: 'Client' },
        { key: 'brand', label: 'Enseigne' },
        { key: 'points', label: 'Points', right: true },
        { key: 'next', label: 'Prochaine récompense' },
        { key: 'missing', label: 'Points manquants', right: true },
        { key: 'amount_left', label: 'CA restant', right: true },
    ],
    future: [
        { key: 'name', label: 'Client' },
        { key: 'brand', label: 'Enseigne' },
        { key: 'points', label: 'Points', right: true },
        { key: 'next', label: 'Prochaine récompense' },
        { key: 'missing', label: 'Points manquants', right: true },
        { key: 'amount_left', label: 'CA restant', right: true },
        { key: 'estimated_on', label: 'Date estimée' },
    ],
};

const SORTABLE = ['name', 'brand', 'points', 'missing', 'expires_on', 'estimated_on'];

function cell(row: Row, key: string) {
    const value = row[key as keyof Row] as string | number | null;
    if (value === null) return '—';
    if (key.endsWith('_on')) return formatDay(String(value));
    return typeof value === 'number' ? value.toLocaleString('fr-FR') : value;
}

function Line({ row, columns, tab }: { row: Row; columns: (typeof COLUMNS)['near']; tab: string }) {
    const [redeeming, setRedeeming] = useState(false);

    return (
        <>
            <tr>
                {columns.map((c) => (
                    <td key={c.key} className={c.right ? 'r mono' : ''}>
                        {c.key === 'name' ? (
                            <Link href={`/customers/${row.customer_id}`}>
                                <b>{row.name}</b>
                            </Link>
                        ) : (
                            cell(row, c.key)
                        )}
                    </td>
                ))}
                <td className="r">
                    {tab === 'reached' && !redeeming && (
                        <button type="button" className="mdash-btn ghost sm" onClick={() => setRedeeming(true)}>
                            Récompense utilisée
                        </button>
                    )}
                </td>
            </tr>
            {redeeming && (
                <tr>
                    <td colSpan={columns.length + 1}>
                        <Redeem customerId={row.customer_id} card={row} onDone={() => setRedeeming(false)} />
                    </td>
                </tr>
            )}
        </>
    );
}

function LoyaltyTracking() {
    const { programs, brands, stores, segments, filters, counts, cards, canExport } = usePage<PageProps>().props;

    const params = (changes: Record<string, string | number | null> = {}) =>
        Object.fromEntries(Object.entries({ ...filters, ...changes }).filter(([, v]) => v !== null && v !== '').map(([k, v]) => [k, String(v)]));
    const visit = (changes: Record<string, string | number | null>) => router.get('/fidelite', params({ page: null, ...changes }), { preserveState: true, preserveScroll: true });

    function sortBy(key: string) {
        visit({ sort: key, dir: filters.sort === key && filters.dir === 'asc' ? 'desc' : 'asc' });
    }

    function saveGroup() {
        const label = TABS.find((t) => t.key === filters.tab)?.label ?? '';
        const name = window.prompt('Nom du groupe de clients :', `Fidélité : ${label}`);
        if (name?.trim()) router.post('/fidelite/group', { ...params(), name: name.trim() }, { preserveScroll: true });
    }

    const columns = COLUMNS[filters.tab];
    const rows = cards.data as Row[];

    return (
        <Page
            title="Suivi fidélité"
            subtitle="Une ligne par client et par enseigne"
            actions={
                programs.length > 0 && (
                    <>
                        {canExport && (
                            <a className="mdash-btn ghost" href={`/fidelite/export?${new URLSearchParams(params()).toString()}`}>
                                Excel
                            </a>
                        )}
                        <button type="button" className="mdash-btn ghost" onClick={saveGroup}>
                            Enregistrer comme groupe
                        </button>
                    </>
                )
            }
            filters={
                programs.length > 0 && (
                    <>
                        <SearchFilter value={filters.q} placeholder="Nom ou numéro" onSearch={(q) => visit({ q })} />
                        <SelectFilter label="Programme" value={filters.program ?? ''} options={programs} allLabel="Programme" onChange={(v) => visit({ program: v, brand: null, store: null })} />
                        <SelectFilter label="Enseigne" value={filters.brand ?? ''} options={brands} allLabel="Toutes les enseignes" onChange={(v) => visit({ brand: v, store: null })} />
                        <SelectFilter label="Magasin" value={filters.store ?? ''} options={stores} allLabel="Tous les magasins" onChange={(v) => visit({ store: v })} />
                        <SelectFilter label="Segment RFM" value={filters.segment ?? ''} options={segments} allLabel="Tous les segments" onChange={(v) => visit({ segment: v })} />
                    </>
                )
            }
        >
            {programs.length === 0 ? (
                <Panel>
                    <Empty title="Aucun programme de fidélité">Créez-en un dans Paramètres › Fidélité.</Empty>
                </Panel>
            ) : (
                <Panel>
                    <div className="mdash-seg mb-3" role="group" aria-label="Onglets">
                        {TABS.map((t) => (
                            <button key={t.key} type="button" aria-pressed={filters.tab === t.key} onClick={() => visit({ tab: t.key, sort: null, dir: null })}>
                                {t.label} ({counts[t.key] ?? 0})
                            </button>
                        ))}
                    </div>
                    {rows.length === 0 ? (
                        <Empty title="Aucun client ici" />
                    ) : (
                        <>
                            <div className="mdash-tbl mpage-table">
                                <table>
                                    <thead>
                                        <tr>
                                            {columns.map((c) => (
                                                <th key={c.key} className={c.right ? 'r' : ''}>
                                                    {SORTABLE.includes(c.key) ? (
                                                        <button type="button" onClick={() => sortBy(c.key)}>
                                                            {c.label}
                                                            {filters.sort === c.key ? (filters.dir === 'asc' ? ' ↑' : ' ↓') : ''}
                                                        </button>
                                                    ) : (
                                                        c.label
                                                    )}
                                                </th>
                                            ))}
                                            <th />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {rows.map((row) => (
                                            <Line key={row.id} row={row} columns={columns} tab={filters.tab} />
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <Pager page={cards} onPage={(n) => router.get('/fidelite', params({ page: n }), { preserveState: true, preserveScroll: true })} unit="lignes" />
                        </>
                    )}
                </Panel>
            )}
        </Page>
    );
}

LoyaltyTracking.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LoyaltyTracking;
