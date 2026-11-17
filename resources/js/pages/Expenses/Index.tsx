import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Pager, Paginated, Panel, PlusIcon, SearchFilter, SelectFilter, StatStrip, formatDay, useQueryFilters } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';

/*
 * Application « Dépenses » : on n'y gère que les dépenses. Le mois en cours,
 * ses catégories, et la part des dépenses dans le chiffre d'affaires.
 */

interface ExpenseRow {
    id: number;
    spent_on: string;
    amount: number;
    category: string;
    category_label: string;
    label: string;
    supplier: string | null;
    method: string | null;
    method_label: string | null;
    store_id: number;
    store_name: string | null;
    user_name: string | null;
    has_receipt: boolean;
    resource_id?: number | null;
    vehicle?: string | null;
}

interface PageProps {
    filters: { month: string; category: string | null; q: string };
    monthLabel: string;
    stats: { total: number; previous: number; revenue: number; count: number };
    byCategory: { key: string; label: string; total: number; count: number }[];
    expenses: Paginated<ExpenseRow>;
    categories: { value: string; label: string }[];
    suppliers: string[];
    stores: { id: number; name: string }[];
    paymentMethods: Record<string, string>;
    vehicles?: { id: number; label: string }[];
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const money = (n: number) => `${new Intl.NumberFormat('fr-FR').format(n)} F`;

function shiftMonth(month: string, delta: number): string {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function ExpenseModal({ expense, onClose }: { expense: ExpenseRow | null; onClose: () => void }) {
    const { categories, suppliers, stores, paymentMethods, vehicles = [] } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        store_id: expense ? String(expense.store_id) : stores.length === 1 ? String(stores[0].id) : '',
        spent_on: expense?.spent_on ?? new Date().toISOString().slice(0, 10),
        amount: expense ? String(expense.amount) : '',
        category: expense?.category ?? 'fournitures',
        label: expense?.label ?? '',
        supplier: expense?.supplier ?? '',
        method: expense?.method ?? 'cash',
        receipt: null as File | null,
        resource_id: expense?.resource_id ? String(expense.resource_id) : '',
        _method: expense ? 'put' : 'post',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(expense ? `/expenses/${expense.id}` : '/expenses', { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-lg p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">{expense ? 'Modifier la dépense' : 'Nouvelle dépense'}</h2>
                <div className="grid grid-cols-2 gap-3">
                    <label className="col-span-2">
                        <span className="mb-1 block font-medium">Libellé</span>
                        <input className={field} value={data.label} onChange={(e) => setData('label', e.target.value)} placeholder="ex. Shampoings, loyer de novembre" required />
                        {errors.label && <span className="text-xs text-error-500">{errors.label}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Montant (FCFA)</span>
                        <input className={field} type="number" min={1} value={data.amount} onChange={(e) => setData('amount', e.target.value)} required />
                        {errors.amount && <span className="text-xs text-error-500">{errors.amount}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Date</span>
                        <input className={field} type="date" value={data.spent_on} onChange={(e) => setData('spent_on', e.target.value)} required />
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Catégorie</span>
                        <select className={field} value={data.category} onChange={(e) => setData('category', e.target.value)}>
                            {categories.map((c) => (
                                <option key={c.value} value={c.value}>
                                    {c.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Payé en</span>
                        <select className={field} value={data.method} onChange={(e) => setData('method', e.target.value)}>
                            {Object.entries(paymentMethods).map(([k, l]) => (
                                <option key={k} value={k}>
                                    {l}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="col-span-2">
                        <span className="mb-1 block font-medium">Fournisseur (facultatif)</span>
                        <input className={field} list="nx-suppliers" value={data.supplier} onChange={(e) => setData('supplier', e.target.value)} />
                        <datalist id="nx-suppliers">
                            {suppliers.map((s) => (
                                <option key={s} value={s} />
                            ))}
                        </datalist>
                    </label>
                    {stores.length > 1 && (
                        <label className="col-span-2">
                            <span className="mb-1 block font-medium">Établissement</span>
                            <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} required>
                                <option value="">—</option>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {vehicles.length > 0 && (
                        <label className="col-span-2">
                            <span className="mb-1 block font-medium">Véhicule concerné (facultatif)</span>
                            <select className={field} value={data.resource_id} onChange={(e) => setData('resource_id', e.target.value)}>
                                <option value="">—</option>
                                {vehicles.map((v) => (
                                    <option key={v.id} value={v.id}>
                                        {v.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <label className="col-span-2">
                        <span className="mb-1 block font-medium">Justificatif (photo ou PDF, facultatif)</span>
                        <input type="file" accept="image/*,application/pdf" capture="environment" onChange={(e) => setData('receipt', e.target.files?.[0] ?? null)} />
                        {errors.receipt && <span className="text-xs text-error-500">{errors.receipt}</span>}
                    </label>
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        {processing ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function ExpensesIndex() {
    const { filters, monthLabel, stats, byCategory, expenses, categories } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<ExpenseRow | null | 'new'>(null);
    const visit = useQueryFilters('/expenses', filters);
    const max = Math.max(1, ...byCategory.map((c) => c.total));
    const change = stats.previous ? Math.round(((stats.total - stats.previous) * 100) / stats.previous) : null;
    const share = stats.revenue ? Math.round((stats.total * 100) / stats.revenue) : null;

    return (
        <Page
            title="Dépenses"
            subtitle={monthLabel}
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setEditing('new')}>
                    <PlusIcon />
                    Nouvelle dépense
                </button>
            }
            filters={
                <>
                    <span className="inline-flex items-center gap-1">
                        <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ month: shiftMonth(filters.month, -1) })} aria-label="Mois précédent">
                            ‹
                        </button>
                        <input type="month" className="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900" value={filters.month} onChange={(e) => visit({ month: e.target.value })} aria-label="Mois" />
                        <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ month: shiftMonth(filters.month, 1) })} aria-label="Mois suivant">
                            ›
                        </button>
                    </span>
                    <SelectFilter label="Catégorie" value={filters.category} options={categories} allLabel="Toutes" onChange={(category) => visit({ category })} />
                    <SearchFilter value={filters.q} placeholder="Libellé ou fournisseur" onSearch={(q) => visit({ q })} />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Dépensé ce mois', value: money(stats.total), hint: `${stats.count} dépense(s)` },
                    { label: 'Par rapport au mois dernier', value: change === null ? '—' : `${change > 0 ? '+' : ''}${change} %`, tone: change !== null && change > 10 ? 'bad' : 'ok' },
                    { label: 'Part du chiffre d’affaires', value: share === null ? '—' : `${share} %`, hint: `CA du mois : ${money(stats.revenue)}`, tone: share !== null && share > 70 ? 'bad' : undefined },
                ]}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Panel title="Par catégorie" className="lg:col-span-1">
                    {byCategory.length === 0 ? (
                        <p className="text-sm text-gray-500">Aucune dépense ce mois-ci.</p>
                    ) : (
                        <ul className="space-y-2.5">
                            {byCategory.map((c) => (
                                <li key={c.key}>
                                    <button type="button" className="w-full text-left" onClick={() => visit({ category: filters.category === c.key ? null : c.key })}>
                                        <span className="flex justify-between text-sm">
                                            <span className={filters.category === c.key ? 'font-semibold' : ''}>{c.label}</span>
                                            <span className="font-semibold">{money(c.total)}</span>
                                        </span>
                                        <span className="mt-1 block h-2 rounded-full bg-gray-100 dark:bg-white/5">
                                            <span className="block h-2 rounded-full bg-gradient-to-r from-rose-400 to-red-600" style={{ width: `${(c.total * 100) / max}%` }} />
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel className="lg:col-span-2">
                    {expenses.data.length === 0 ? (
                        <Empty title="Aucune dépense">Notez chaque dépense en 10 secondes, avec la photo du reçu si vous l’avez.</Empty>
                    ) : (
                        <div className="mdash-tbl">
                            <table className="mpage-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Dépense</th>
                                        <th>Catégorie</th>
                                        <th className="num">Montant</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {expenses.data.map((e) => (
                                        <tr key={e.id}>
                                            <td>{formatDay(e.spent_on)}</td>
                                            <td>
                                                <span className="font-medium">{e.label}</span>
                                                <span className="block text-xs text-gray-500">{[e.vehicle, e.supplier, e.method_label, e.user_name].filter(Boolean).join(' · ')}</span>
                                            </td>
                                            <td>{e.category_label}</td>
                                            <td className="num font-semibold">{money(e.amount)}</td>
                                            <td>
                                                <span className="mpage-rowlinks">
                                                    {e.has_receipt && (
                                                        <a href={`/expenses/${e.id}/receipt`} target="_blank" rel="noreferrer">
                                                            Reçu
                                                        </a>
                                                    )}
                                                    <button type="button" onClick={() => setEditing(e)}>
                                                        Modifier
                                                    </button>
                                                    <button type="button" onClick={() => confirm('Supprimer cette dépense ?') && router.delete(`/expenses/${e.id}`, { preserveScroll: true })}>
                                                        Supprimer
                                                    </button>
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pager page={expenses} onPage={(page) => visit({ page })} unit="dépenses" />
                </Panel>
            </div>

            {editing && <ExpenseModal expense={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />}
        </Page>
    );
}

ExpensesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ExpensesIndex;
