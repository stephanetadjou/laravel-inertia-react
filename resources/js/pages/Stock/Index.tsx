import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, Seg, StatStrip, useQueryFilters } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';

/*
 * Application « Stock » : les produits vendus avec les prestations. Le stock
 * baisse à chaque vente ; on voit d'un coup d'œil quoi réassortir.
 */

interface Row {
    id: number;
    name: string;
    reference: string | null;
    price: number;
    quantity: number;
    min_quantity: number;
    cost_price: number | null;
    supplier: string | null;
    level: 'ok' | 'low' | 'out';
    sold_30: number;
    days_left: number | null;
}

interface PageProps {
    filters: { level: 'all' | 'low' | 'out'; q: string };
    stats: { products: number; low: number; out: number; value: number };
    items: Row[];
    untracked: { id: number; name: string }[];
    stores: { id: number; name: string }[];
    hasExpenses: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const money = (n: number) => `${new Intl.NumberFormat('fr-FR').format(n)} F`;
const LEVEL = { ok: { label: 'En stock', cls: 'ok' }, low: { label: 'À réassortir', cls: 'warn' }, out: { label: 'Rupture', cls: 'bad' } };

function ReceiveModal({ row, onClose }: { row: Row; onClose: () => void }) {
    const { stores, hasExpenses } = usePage<PageProps>().props;
    const { data, setData, post, processing } = useForm({ quantity: '', cost_price: row.cost_price ? String(row.cost_price) : '', supplier: row.supplier ?? '', store_id: stores[0]?.id ?? '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/stock/${row.id}/receive`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Entrée de marchandise · {row.name}</h2>
                <label className="block">
                    <span className="mb-1 block font-medium">Quantité reçue</span>
                    <input className={field} type="number" min={1} value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} required autoFocus />
                </label>
                <label className="block">
                    <span className="mb-1 block font-medium">Prix d’achat unitaire (facultatif)</span>
                    <input className={field} type="number" min={0} value={data.cost_price} onChange={(e) => setData('cost_price', e.target.value)} />
                    {hasExpenses && <span className="text-xs text-gray-500">Avec un prix, la dépense est ajoutée dans Dépenses.</span>}
                </label>
                <label className="block">
                    <span className="mb-1 block font-medium">Fournisseur</span>
                    <input className={field} value={data.supplier} onChange={(e) => setData('supplier', e.target.value)} />
                </label>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Ajouter au stock
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function TrackModal({ onClose }: { onClose: () => void }) {
    const { untracked } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({ catalog_item_id: untracked[0] ? String(untracked[0].id) : '', quantity: '', min_quantity: '2', cost_price: '' });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/stock/track', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Suivre le stock d’un produit</h2>
                {untracked.length === 0 ? (
                    <p className="text-gray-500">Tous vos produits sont suivis. Ajoutez des produits dans Ventes › Catalogue.</p>
                ) : (
                    <>
                        <label className="block">
                            <span className="mb-1 block font-medium">Produit du catalogue</span>
                            <select className={field} value={data.catalog_item_id} onChange={(e) => setData('catalog_item_id', e.target.value)}>
                                {untracked.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </select>
                            {errors.catalog_item_id && <span className="text-xs text-error-500">{errors.catalog_item_id}</span>}
                        </label>
                        <div className="grid grid-cols-2 gap-3">
                            <label>
                                <span className="mb-1 block font-medium">Quantité en stock</span>
                                <input className={field} type="number" min={0} value={data.quantity} onChange={(e) => setData('quantity', e.target.value)} required />
                            </label>
                            <label>
                                <span className="mb-1 block font-medium">Alerte à partir de</span>
                                <input className={field} type="number" min={0} value={data.min_quantity} onChange={(e) => setData('min_quantity', e.target.value)} />
                            </label>
                        </div>
                    </>
                )}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Fermer
                    </button>
                    {untracked.length > 0 && (
                        <button type="submit" className="mdash-btn brand" disabled={processing}>
                            Suivre ce produit
                        </button>
                    )}
                </div>
            </form>
        </Modal>
    );
}

function StockIndex() {
    const { filters, stats, items } = usePage<PageProps>().props;
    const [receiving, setReceiving] = useState<Row | null>(null);
    const [tracking, setTracking] = useState(false);
    const visit = useQueryFilters('/stock', filters);

    const countRow = (row: Row) => {
        const value = prompt(`Inventaire de « ${row.name} » : combien en comptez-vous ?`, String(row.quantity));
        if (value !== null && value.trim() !== '' && !Number.isNaN(Number(value))) router.post(`/stock/${row.id}/count`, { counted: Number(value) }, { preserveScroll: true });
    };

    return (
        <Page
            title="Stock"
            subtitle="Les produits que vous vendez avec vos prestations"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setTracking(true)}>
                    <PlusIcon />
                    Suivre un produit
                </button>
            }
            filters={
                <>
                    <Seg
                        label="Niveau"
                        value={filters.level}
                        options={[
                            { key: 'all', label: 'Tous' },
                            { key: 'low', label: 'À réassortir' },
                            { key: 'out', label: 'En rupture' },
                        ]}
                        onChange={(level) => visit({ level })}
                    />
                    <SearchFilter value={filters.q} placeholder="Produit" onSearch={(q) => visit({ q })} />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Produits suivis', value: stats.products },
                    { label: 'À réassortir', value: stats.low, tone: stats.low ? 'warn' : undefined },
                    { label: 'En rupture', value: stats.out, tone: stats.out ? 'bad' : undefined },
                    { label: 'Valeur du stock', value: money(stats.value) },
                ]}
            />

            <Panel>
                {items.length === 0 ? (
                    <Empty title="Aucun produit suivi">Choisissez les produits de votre catalogue à suivre : montures, soins, accessoires…</Empty>
                ) : (
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Produit</th>
                                    <th className="num">En stock</th>
                                    <th className="num">Vendus (30 j)</th>
                                    <th>Couverture</th>
                                    <th>Niveau</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((row) => (
                                    <tr key={row.id}>
                                        <td>
                                            <span className="font-medium">{row.name}</span>
                                            <span className="block text-xs text-gray-500">{[row.reference, money(row.price), row.supplier].filter(Boolean).join(' · ')}</span>
                                        </td>
                                        <td className="num text-base font-bold">{row.quantity}</td>
                                        <td className="num">{row.sold_30}</td>
                                        <td>{row.days_left === null ? '—' : `≈ ${row.days_left} jour(s)`}</td>
                                        <td>
                                            <span className={`mdash-pill ${LEVEL[row.level].cls}`}>{LEVEL[row.level].label}</span>
                                            <span className="block text-xs text-gray-500">alerte à {row.min_quantity}</span>
                                        </td>
                                        <td>
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setReceiving(row)}>
                                                    + Entrée
                                                </button>
                                                <button type="button" onClick={() => countRow(row)}>
                                                    Inventaire
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {receiving && <ReceiveModal row={receiving} onClose={() => setReceiving(null)} />}
            {tracking && <TrackModal onClose={() => setTracking(false)} />}
        </Page>
    );
}

StockIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default StockIndex;
