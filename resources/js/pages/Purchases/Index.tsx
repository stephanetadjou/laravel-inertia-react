import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatCompact, formatNumber } from '../../components/dashboard/Charts';
import { Delta, Kpi, percentChange } from '../../components/dashboard/Kpi';
import { useHeaderHeight } from '../../components/dashboard/useHeaderHeight';
import PurchaseImportModal, { PurchaseImportPreview } from './PurchaseImportModal';
import '../Dashboard/dashboard.css';
import './purchases.css';
import { customerUrl } from '../../components/common/customerUrl';

type Preset = 'today' | '7d' | '30d' | '90d';

interface Filters {
    from: string;
    to: string;
    preset: Preset | null;
    days: number;
    brand: number | null;
    brand_locked: boolean;
    brand_hidden: boolean;
    brands: { id: number; name: string }[];
    store: number | null;
    category: string | null;
    q: string;
    sort: 'date' | 'amount';
    dir: 'asc' | 'desc';
    per_page: number;
}

interface Totals {
    revenue: number;
    purchases: number;
    buyers: number;
    quantity: number;
}

interface PurchaseRow {
    id: number;
    purchased_at: string;
    contact: { id: number; customer_id: number | null; name: string | null; phone_e164: string | null };
    store_name: string;
    brand_name: string | null;
    product_category: string | null;
    quantity: number | null;
    amount: number;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

interface PageProps {
    filters: Filters;
    stores: { id: number; name: string }[];
    categories: string[];
    kpis: { current: Totals; previous: Totals };
    purchases: Paginated<PurchaseRow>;
    preview: PurchaseImportPreview | null;
    flash?: { success: string | null; error: string | null };
    [key: string]: unknown;
}

type QueryValue = string | number | null;

const PRESETS: { key: Preset; label: string }[] = [
    { key: 'today', label: "Aujourd'hui" },
    { key: '7d', label: '7 j' },
    { key: '30d', label: '30 j' },
    { key: '90d', label: '90 j' },
];

function formatDate(day: string): string {
    const [y, m, d] = day.split('-');
    return `${d}/${m}/${y}`;
}

function plural(n: number, word: string, pluralWord = word + 's'): string {
    return `${formatNumber(n)} ${n > 1 ? pluralWord : word}`;
}

function PurchasesIndex() {
    const { filters, stores, categories, kpis, purchases, preview, flash } = usePage<PageProps>().props;
    const headerHeight = useHeaderHeight();
    const [isImportOpen, setIsImportOpen] = useState(() => preview !== null);
    const [customFrom, setCustomFrom] = useState(filters.from);
    const [customTo, setCustomTo] = useState(filters.to);
    const [search, setSearch] = useState(filters.q);

    useEffect(() => {
        setCustomFrom(filters.from);
        setCustomTo(filters.to);
    }, [filters.from, filters.to]);

    useEffect(() => setSearch(filters.q), [filters.q]);

    useEffect(() => {
        if (preview) setIsImportOpen(true);
    }, [preview]);

    /** Paramètres actuels + modifications ; un changement de filtre revient à la page 1. */
    function visit(overrides: Record<string, QueryValue>, keepPage = false) {
        const current: Record<string, QueryValue> = {
            period: filters.preset,
            from: filters.preset ? null : filters.from,
            to: filters.preset ? null : filters.to,
            brand: filters.brand_locked ? null : filters.brand,
            store: filters.store,
            category: filters.category,
            q: filters.q || null,
            sort: filters.sort === 'date' ? null : filters.sort,
            dir: filters.dir === 'desc' ? null : filters.dir,
            per_page: filters.per_page === 25 ? null : filters.per_page,
            page: keepPage ? purchases.current_page : null,
        };
        const query = Object.fromEntries(
            Object.entries({ ...current, ...overrides }).filter(([, v]) => v !== null && v !== ''),
        ) as Record<string, string | number>;
        router.get('/purchases', query, { preserveState: true, preserveScroll: true, replace: !('page' in overrides) });
    }

    function applyCustomRange(from: string, to: string) {
        setCustomFrom(from);
        setCustomTo(to);
        if (from && to && from <= to) {
            visit({ period: null, from, to });
        }
    }

    // Recherche : appliquée après une courte pause de frappe.
    useEffect(() => {
        if (search === filters.q) return;
        const timer = setTimeout(() => visit({ q: search.trim() || null }), 350);
        return () => clearTimeout(timer);
    }, [search]);

    function toggleSort(column: 'date' | 'amount') {
        const dir = filters.sort === column && filters.dir === 'desc' ? 'asc' : 'desc';
        visit({ sort: column === 'date' ? null : column, dir: dir === 'desc' ? null : dir });
    }

    const { current, previous } = kpis;
    const basket = current.purchases ? current.revenue / current.purchases : 0;
    const previousBasket = previous.purchases ? previous.revenue / previous.purchases : 0;
    const selectedBrandName = filters.brands.find((b) => b.id === filters.brand)?.name;
    const hasFilters = Boolean(filters.store || filters.category || filters.q || (filters.brand && !filters.brand_locked));

    const sortArrow = (column: 'date' | 'amount') => (filters.sort === column ? (filters.dir === 'desc' ? '↓' : '↑') : '↕');

    return (
        <div className="mdash mpur">
            <Head title="Historique d'achat" />

            <header className="mdash-top" style={{ top: headerHeight }}>
                <div className="mdash-top-in">
                    <div className="mpur-title">
                        <div>
                            <h1>Historique d'achat</h1>
                            <small>Achats clients importés, par boutique et catégorie</small>
                        </div>
                        <div className="mpur-actions">
                            <input
                                type="search"
                                className="mpur-search"
                                aria-label="Rechercher un client, un numéro ou un produit"
                                placeholder="Rechercher client, numéro ou produit"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <button type="button" className="mdash-btn ghost" onClick={() => setIsImportOpen(true)}>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <path d="M12 3v12M7 10l5 5 5-5M5 21h14" />
                                </svg>
                                Importer un historique
                            </button>
                        </div>
                    </div>
                    <div className="mdash-filters">
                        <div className="mdash-fgroup">
                            <span>Période</span>
                            <div className="mdash-seg" role="group" aria-label="Période">
                                {PRESETS.map((p) => (
                                    <button key={p.key} type="button" aria-pressed={filters.preset === p.key} onClick={() => visit({ period: p.key, from: null, to: null })}>
                                        {p.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="mdash-fgroup">
                            <span>Du / au</span>
                            <div className="mdash-range">
                                <input type="date" aria-label="Date de début" value={customFrom} max={customTo} onChange={(e) => applyCustomRange(e.target.value, customTo)} />
                                <span>→</span>
                                <input type="date" aria-label="Date de fin" value={customTo} min={customFrom} onChange={(e) => applyCustomRange(customFrom, e.target.value)} />
                            </div>
                        </div>
                        {!filters.brand_hidden && (
                            <div className="mdash-fgroup">
                                <label htmlFor="mpur-brand">Marque</label>
                                <select
                                    id="mpur-brand"
                                    value={filters.brand ?? ''}
                                    disabled={filters.brand_locked}
                                    title={filters.brand_locked ? 'Votre rôle est limité à cette marque' : undefined}
                                    onChange={(e) => visit({ brand: e.target.value ? Number(e.target.value) : null, store: null })}
                                >
                                    {!filters.brand_locked && <option value="">Toutes les marques</option>}
                                    {filters.brands.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        {!filters.brand_hidden && stores.length > 1 && (
                            <div className="mdash-fgroup">
                                <label htmlFor="mpur-store">Boutique</label>
                                <select id="mpur-store" value={filters.store ?? ''} onChange={(e) => visit({ store: e.target.value ? Number(e.target.value) : null })}>
                                    <option value="">Toutes</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div className="mdash-fgroup">
                            <label htmlFor="mpur-category">Catégorie</label>
                            <select id="mpur-category" value={filters.category ?? ''} onChange={(e) => visit({ category: e.target.value || null })}>
                                <option value="">Toutes</option>
                                {categories.map((c) => (
                                    <option key={c} value={c}>
                                        {c}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </div>
                </div>
                <div className="mdash-scope">
                    Vous regardez <b>{selectedBrandName ?? (filters.brand_hidden ? 'votre boutique' : 'toutes les marques')}</b> · du{' '}
                    <b>{formatDate(filters.from)}</b> au <b>{formatDate(filters.to)}</b>
                    {filters.category && (
                        <>
                            {' '}
                            · catégorie <b>{filters.category}</b>
                        </>
                    )}
                </div>
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            <section className="mdash-grid" aria-label="Historique d'achat">
                <div className="c12 mdash-kpis four">
                    <Kpi
                        hero
                        label="CA de la période"
                        value={formatCompact(current.revenue)}
                        unit="FCFA"
                        delta={<Delta value={percentChange(current.revenue, previous.revenue)} />}
                    />
                    <Kpi
                        label="Achats"
                        value={formatNumber(current.purchases)}
                        delta={<Delta value={percentChange(current.purchases, previous.purchases)} />}
                        foot={plural(current.quantity, 'article')}
                    />
                    <Kpi
                        label="Clients acheteurs"
                        value={formatNumber(current.buyers)}
                        delta={<Delta value={percentChange(current.buyers, previous.buyers)} />}
                    />
                    <Kpi label="Panier moyen" value={formatNumber(basket)} unit="FCFA" delta={<Delta value={percentChange(basket, previousBasket)} />} />
                </div>

                <article className="mdash-panel c12 mpur-table">
                    <div className="mdash-ph">
                        <h2>Achats</h2>
                        <span className="mdash-sub">{plural(purchases.total, 'achat')} sur la période</span>
                    </div>
                    {purchases.data.length === 0 ? (
                        <div className="mpur-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z" />
                                <path d="M3 6h18M16 10a4 4 0 0 1-8 0" />
                            </svg>
                            <b>Aucun achat pour ces filtres</b>
                            <span>
                                {hasFilters ? 'Retirez un filtre ou élargissez la période.' : "Élargissez la période ou importez un historique d'achats."}
                            </span>
                            <span style={{ display: 'flex', gap: 8, flexWrap: 'wrap', justifyContent: 'center' }}>
                                <button type="button" className="mdash-btn ghost" onClick={() => visit({ period: '90d', from: null, to: null })}>
                                    Voir les 90 derniers jours
                                </button>
                                {hasFilters && (
                                    <button type="button" className="mdash-btn ghost" onClick={() => visit({ brand: null, store: null, category: null, q: null })}>
                                        Retirer les filtres
                                    </button>
                                )}
                            </span>
                        </div>
                    ) : (
                        <div className="mdash-tbl">
                            <table>
                                <thead>
                                    <tr>
                                        <th>
                                            <button type="button" className="mpur-sort" aria-pressed={filters.sort === 'date'} onClick={() => toggleSort('date')}>
                                                Date {sortArrow('date')}
                                            </button>
                                        </th>
                                        <th>Client</th>
                                        <th>Boutique</th>
                                        <th>Catégorie</th>
                                        <th className="r">Qté</th>
                                        <th className="r">
                                            <button type="button" className="mpur-sort" aria-pressed={filters.sort === 'amount'} onClick={() => toggleSort('amount')}>
                                                Montant {sortArrow('amount')}
                                            </button>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {purchases.data.map((p) => (
                                        <tr key={p.id}>
                                            <td className="mono">{formatDate(p.purchased_at)}</td>
                                            <td className="cell-main">
                                                <b>
                                                    <Link href={customerUrl(p.contact)}>{p.contact.name ?? 'Contact supprimé'}</Link>
                                                </b>
                                                <span className="mono">{p.contact.phone_e164}</span>
                                            </td>
                                            <td className="cell-main wrap">
                                                <b style={{ fontWeight: 500 }}>{p.store_name}</b>
                                                {p.brand_name && <span>{p.brand_name}</span>}
                                            </td>
                                            <td>{p.product_category ? <span className="mpur-cat">{p.product_category}</span> : <span className="mdash-src">—</span>}</td>
                                            <td className="r mono">{p.quantity ?? '—'}</td>
                                            <td className="mpur-amount">{formatNumber(p.amount)} F</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {purchases.total > 0 && (
                        <div className="mpur-pager">
                            <span>
                                {purchases.from}–{purchases.to} sur {formatNumber(purchases.total)}
                            </span>
                            <div className="btns">
                                <select aria-label="Lignes par page" value={filters.per_page} onChange={(e) => visit({ per_page: Number(e.target.value) === 25 ? null : Number(e.target.value) })}>
                                    {[25, 50, 100].map((n) => (
                                        <option key={n} value={n}>
                                            {n} / page
                                        </option>
                                    ))}
                                </select>
                                <button
                                    type="button"
                                    className="mdash-btn ghost"
                                    disabled={purchases.current_page <= 1}
                                    onClick={() => visit({ page: purchases.current_page - 1 })}
                                >
                                    Précédent
                                </button>
                                <button
                                    type="button"
                                    className="mdash-btn ghost"
                                    disabled={purchases.current_page >= purchases.last_page}
                                    onClick={() => visit({ page: purchases.current_page + 1 })}
                                >
                                    Suivant
                                </button>
                            </div>
                        </div>
                    )}
                </article>
            </section>

            <PurchaseImportModal isOpen={isImportOpen} onClose={() => setIsImportOpen(false)} preview={preview} />
        </div>
    );
}

PurchasesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default PurchasesIndex;
