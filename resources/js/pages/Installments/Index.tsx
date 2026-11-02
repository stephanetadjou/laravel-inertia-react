import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Pager, Paginated, Panel, PlusIcon, SearchFilter, Seg, SelectFilter, StatStrip, formatDay, useQueryFilters } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';
import { Modal } from '../../components/ui/modal';
import CustomerPicker, { PickedCustomer } from '../../components/common/CustomerPicker';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';

/*
 * Ventes › Échéances : tranches à payer, en retard ou payées. Encaisser une
 * tranche crée une vente (CA, fiche client, fidélité) et prépare son reçu ;
 * « Payer en plusieurs fois » découpe un montant en tranches mensuelles.
 */

export interface InstallmentRow {
    id: number;
    schedule_id: number;
    number: number;
    label: string;
    schedule_label: string;
    amount: number;
    due_on: string;
    paid_at: string | null;
    status: 'due' | 'overdue' | 'paid';
    status_label: string;
    customer: { id: number; name: string | null };
    beneficiary: { id: number; name: string } | null;
    store_name: string | null;
    whatsapp_url: string | null;
}

interface PageProps {
    filters: { status: string; q: string; store: number | null };
    stats: { overdue_count: number; overdue_amount: number; due_month: number; paid_month: number };
    installments: Paginated<InstallmentRow>;
    stores: { id: number; name: string }[];
    catalog: { id: number; name: string; price: number | null; type: string }[];
    paymentMethods: Record<string, string>;
    dueDay: number;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export const STATUS_PILL: Record<InstallmentRow['status'], string> = { due: 'info', overdue: 'bad', paid: 'ok' };

/** Bouton « Encaisser » : moyen de paiement puis vente créée ; « Reçu » une fois payée. */
export function PayButton({ row, paymentMethods }: { row: InstallmentRow; paymentMethods: Record<string, string> }) {
    const [open, setOpen] = useState(false);
    const [method, setMethod] = useState('cash');

    if (row.status === 'paid') {
        return (
            <a href={`/installments/${row.id}/receipt`} target="_blank" rel="noreferrer" className="mdash-btn ghost sm">
                Reçu
            </a>
        );
    }

    if (!open) {
        return (
            <button type="button" className="mdash-btn brand sm" onClick={() => setOpen(true)}>
                Encaisser
            </button>
        );
    }

    return (
        <span className="inline-flex items-center gap-1">
            <select aria-label="Moyen de paiement" className="rounded-md border border-gray-300 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900" value={method} onChange={(e) => setMethod(e.target.value)}>
                {Object.entries(paymentMethods).map(([key, label]) => (
                    <option key={key} value={key}>
                        {label}
                    </option>
                ))}
            </select>
            <button type="button" className="mdash-btn brand sm" onClick={() => router.post(`/installments/${row.id}/pay`, { payment_method: method }, { preserveScroll: true })}>
                OK
            </button>
            <button type="button" className="mdash-btn ghost sm" onClick={() => setOpen(false)} aria-label="Annuler">
                ×
            </button>
        </span>
    );
}

function NewScheduleModal({ onClose }: { onClose: () => void }) {
    const { stores, catalog, paymentMethods, dueDay } = usePage<PageProps>().props;
    const [customer, setCustomer] = useState<PickedCustomer | null>(null);
    const next = new Date();
    next.setDate(dueDay);
    if (next < new Date()) next.setMonth(next.getMonth() + 1);
    const { data, setData, post, processing, errors } = useForm({
        customer_id: '',
        store_id: stores.length === 1 ? String(stores[0].id) : '',
        label: '',
        catalog_item_id: '',
        total: '',
        count: '3',
        first_due_on: next.toISOString().slice(0, 10),
        pay_first_now: false,
        payment_method: 'cash',
    });
    const part = Number(data.total) > 0 && Number(data.count) > 0 ? Math.floor(Number(data.total) / Number(data.count)) : 0;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/payment-schedules', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Payer en plusieurs fois</h2>
                <label className="block">
                    <span className="mb-1 block font-medium">Client</span>
                    <CustomerPicker
                        value={customer}
                        onChange={(c) => {
                            setCustomer(c);
                            setData('customer_id', c ? String(c.id) : '');
                        }}
                    />
                    <span className="mt-1 block text-xs text-gray-500">Une personne suivie (enfant…) : son responsable devient le payeur.</span>
                    {errors.customer_id && <span className="text-xs text-error-500">{errors.customer_id}</span>}
                </label>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label>
                        <span className="mb-1 block font-medium">Objet</span>
                        <input className={field} value={data.label} onChange={(e) => setData('label', e.target.value)} placeholder="ex. Kimono et licence" required />
                        {errors.label && <span className="text-xs text-error-500">{errors.label}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Prestation ou produit (facultatif)</span>
                        <select
                            className={field}
                            value={data.catalog_item_id}
                            onChange={(e) => {
                                const item = catalog.find((c) => String(c.id) === e.target.value);
                                setData((d) => ({ ...d, catalog_item_id: e.target.value, label: d.label || item?.name || '', total: d.total || (item?.price ? String(item.price) : '') }));
                            }}
                        >
                            <option value="">—</option>
                            {catalog.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Montant total (FCFA)</span>
                        <input type="number" min={1} className={field} value={data.total} onChange={(e) => setData('total', e.target.value)} required />
                        {errors.total && <span className="text-xs text-error-500">{errors.total}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Nombre de tranches</span>
                        <input type="number" min={1} max={36} className={field} value={data.count} onChange={(e) => setData('count', e.target.value)} required />
                        {part > 0 && <span className="mt-1 block text-xs text-gray-500">≈ {formatNumber(part)} FCFA par mois</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Première échéance</span>
                        <input type="date" className={field} value={data.first_due_on} onChange={(e) => setData('first_due_on', e.target.value)} required />
                    </label>
                    {stores.length > 1 && (
                        <label>
                            <span className="mb-1 block font-medium">Établissement</span>
                            <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} required>
                                <option value="">—</option>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                            {errors.store_id && <span className="text-xs text-error-500">{errors.store_id}</span>}
                        </label>
                    )}
                </div>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={data.pay_first_now} onChange={(e) => setData('pay_first_now', e.target.checked)} />
                    Première tranche payée maintenant
                    {data.pay_first_now && (
                        <select className="ml-2 rounded-md border border-gray-300 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900" value={data.payment_method} onChange={(e) => setData('payment_method', e.target.value)}>
                            {Object.entries(paymentMethods).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    )}
                </label>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing || !data.customer_id}>
                        {processing ? 'Création…' : 'Créer l’échéancier'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function InstallmentsIndex() {
    const { filters, stats, installments, stores, paymentMethods } = usePage<PageProps>().props;
    const [creating, setCreating] = useState(false);
    const visit = useQueryFilters('/installments', filters);

    return (
        <Page
            title="Échéances"
            subtitle="Tranches à encaisser, retards et reçus"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setCreating(true)}>
                    <PlusIcon />
                    Payer en plusieurs fois
                </button>
            }
            filters={
                <>
                    <Seg
                        label="Tranches"
                        value={filters.status}
                        options={[
                            { key: 'open', label: 'À encaisser' },
                            { key: 'overdue', label: 'En retard' },
                            { key: 'due', label: 'À venir' },
                            { key: 'paid', label: 'Payées' },
                        ]}
                        onChange={(status) => visit({ status })}
                    />
                    {stores.length > 1 && <SelectFilter label="Établissement" value={filters.store} options={stores.map((s) => ({ value: s.id, label: s.name }))} allLabel="Tous" onChange={(store) => visit({ store })} />}
                    <SearchFilter value={filters.q} placeholder="Client, enfant ou objet" onSearch={(q) => visit({ q })} />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'En retard', value: `${formatNumber(stats.overdue_amount)} F`, hint: `${stats.overdue_count} tranche(s)`, tone: stats.overdue_count ? 'bad' : undefined },
                    { label: 'À encaisser ce mois', value: `${formatNumber(stats.due_month)} F` },
                    { label: 'Encaissé ce mois', value: `${formatNumber(stats.paid_month)} F`, tone: 'ok' },
                ]}
            />

            <Panel>
                {installments.data.length === 0 ? (
                    <Empty title="Aucune tranche">Créez un échéancier avec « Payer en plusieurs fois », ou depuis un abonnement.</Empty>
                ) : (
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Échéance</th>
                                    <th>Client</th>
                                    <th>Objet</th>
                                    <th className="num">Montant</th>
                                    <th>Statut</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {installments.data.map((row) => (
                                    <tr key={row.id}>
                                        <td>{formatDay(row.due_on)}</td>
                                        <td>
                                            <Link href={`/customers/${row.customer.id}`} className="font-medium text-brand-text hover:underline">
                                                {row.customer.name}
                                            </Link>
                                            {row.beneficiary && <span className="block text-xs text-gray-500">pour {row.beneficiary.name}</span>}
                                        </td>
                                        <td>
                                            {row.label}
                                            <span className="block text-xs text-gray-500">tranche {row.number}{stores.length > 1 && row.store_name ? ` · ${row.store_name}` : ''}</span>
                                        </td>
                                        <td className="num">{formatNumber(row.amount)} F</td>
                                        <td>
                                            <span className={`mdash-pill ${STATUS_PILL[row.status]}`}>{row.status_label}</span>
                                            {row.paid_at && <span className="block text-xs text-gray-500">le {formatDay(row.paid_at)}</span>}
                                        </td>
                                        <td>
                                            <span className="mpage-rowlinks">
                                                {row.whatsapp_url && (
                                                    <a href={row.whatsapp_url} target="_blank" rel="noopener noreferrer" title="Rappeler par WhatsApp">
                                                        <WhatsAppIcon />
                                                        Rappeler
                                                    </a>
                                                )}
                                                <PayButton row={row} paymentMethods={paymentMethods} />
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={installments} onPage={(page) => visit({ page })} unit="tranches" />
            </Panel>

            {creating && <NewScheduleModal onClose={() => setCreating(false)} />}
        </Page>
    );
}

InstallmentsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default InstallmentsIndex;
