import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';
import CustomerPicker, { PickedCustomer } from '../../components/common/CustomerPicker';
import { money } from './shared';

/* Devis ou facture : client, lignes (catalogue ou texte libre), remise, dates. */

interface Line {
    label: string;
    quantity: number | string;
    unit_price: number | string;
    catalog_item_id: number | null;
}

interface PageProps {
    type: 'quote' | 'invoice';
    customer: { id: number; name: string; phone_e164: string | null } | null;
    document: null | {
        id: number;
        number: string;
        store_id: number;
        title: string | null;
        issued_on: string;
        due_on: string | null;
        valid_until: string | null;
        discount_amount: number;
        notes: string | null;
        lines: Line[];
    };
    stores: { id: number; name: string }[];
    catalog: { id: number; name: string; price: number | null; type: string }[];
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const emptyLine = (): Line => ({ label: '', quantity: 1, unit_price: '', catalog_item_id: null });

function InvoiceEdit() {
    const { type, customer: initial, document: doc, stores, catalog } = usePage<PageProps>().props;
    const quote = type === 'quote';
    const [customer, setCustomer] = useState<PickedCustomer | null>(initial ? { id: initial.id, name: initial.name, phone: initial.phone_e164 } : null);
    const { data, setData, post, put, processing, errors } = useForm({
        type,
        customer_id: initial ? String(initial.id) : '',
        store_id: doc ? String(doc.store_id) : stores.length === 1 ? String(stores[0].id) : '',
        title: doc?.title ?? '',
        issued_on: doc?.issued_on ?? new Date().toISOString().slice(0, 10),
        due_on: doc?.due_on ?? '',
        valid_until: doc?.valid_until ?? '',
        discount_amount: doc ? String(doc.discount_amount || '') : '',
        notes: doc?.notes ?? '',
        lines: doc?.lines?.length ? doc.lines : [emptyLine()],
    });

    const lineTotal = (l: Line) => Math.round(Number(l.quantity || 0) * Number(l.unit_price || 0));
    const subtotal = data.lines.reduce((sum, l) => sum + lineTotal(l), 0);
    const total = Math.max(0, subtotal - Number(data.discount_amount || 0));

    const setLine = (index: number, patch: Partial<Line>) => setData('lines', data.lines.map((l, i) => (i === index ? { ...l, ...patch } : l)));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (doc) put(`/invoices/${doc.id}`);
        else post('/invoices');
    };

    const err = errors as Record<string, string | undefined>;

    return (
        <Page
            title={doc ? `${quote ? 'Devis' : 'Facture'} ${doc.number}` : quote ? 'Nouveau devis' : 'Nouvelle facture'}
            subtitle={quote ? 'Votre client le reçoit sur WhatsApp et l’accepte en un clic' : 'Le paiement s’ajoutera à la fiche client et à la caisse du jour'}
            actions={
                <Link href={doc ? `/invoices/${doc.id}` : `/invoices?type=${type}`} className="mdash-btn ghost">
                    Annuler
                </Link>
            }
        >
            <form onSubmit={submit} className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Panel title="Lignes">
                        <div className="space-y-2">
                            {data.lines.map((line, index) => (
                                <div key={index} className="grid grid-cols-12 items-start gap-2 rounded-xl bg-gray-50 p-2 dark:bg-white/[0.03]">
                                    <div className="col-span-12 sm:col-span-6">
                                        <input
                                            className={field}
                                            list="nx-catalog"
                                            placeholder="Prestation, produit ou description"
                                            value={line.label}
                                            onChange={(e) => {
                                                const item = catalog.find((c) => c.name === e.target.value);
                                                setLine(index, item ? { label: item.name, catalog_item_id: item.id, unit_price: line.unit_price || item.price || '' } : { label: e.target.value, catalog_item_id: null });
                                            }}
                                            required
                                        />
                                        {err[`lines.${index}.label`] && <span className="text-xs text-error-500">{err[`lines.${index}.label`]}</span>}
                                    </div>
                                    <input className={`${field} col-span-3 sm:col-span-1`} type="number" min="0.01" step="0.01" aria-label="Quantité" value={line.quantity} onChange={(e) => setLine(index, { quantity: e.target.value })} required />
                                    <input className={`${field} col-span-5 sm:col-span-2`} type="number" min="0" aria-label="Prix unitaire (FCFA)" placeholder="Prix" value={line.unit_price} onChange={(e) => setLine(index, { unit_price: e.target.value })} required />
                                    <span className="col-span-3 self-center text-right text-sm font-semibold sm:col-span-2">{money(lineTotal(line))}</span>
                                    <button
                                        type="button"
                                        className="col-span-1 self-center text-lg text-gray-400 hover:text-red-600 disabled:opacity-30"
                                        onClick={() => setData('lines', data.lines.filter((_, i) => i !== index))}
                                        disabled={data.lines.length === 1}
                                        aria-label="Retirer la ligne"
                                    >
                                        ×
                                    </button>
                                </div>
                            ))}
                            <datalist id="nx-catalog">
                                {catalog.map((c) => (
                                    <option key={c.id} value={c.name} />
                                ))}
                            </datalist>
                            <button type="button" className="mdash-btn ghost sm" onClick={() => setData('lines', [...data.lines, emptyLine()])}>
                                + Ajouter une ligne
                            </button>
                            {err.lines && <span className="block text-xs text-error-500">{err.lines}</span>}
                        </div>

                        <div className="mt-4 ml-auto w-full max-w-xs space-y-1 text-sm">
                            <div className="flex justify-between">
                                <span>Sous-total</span>
                                <span>{money(subtotal)}</span>
                            </div>
                            <label className="flex items-center justify-between gap-2">
                                <span>Remise</span>
                                <input className={`${field} w-32 text-right`} type="number" min="0" value={data.discount_amount} onChange={(e) => setData('discount_amount', e.target.value)} />
                            </label>
                            <div className="flex justify-between border-t border-gray-200 pt-2 text-lg font-bold dark:border-gray-700">
                                <span>Total</span>
                                <span>{money(total)}</span>
                            </div>
                        </div>
                    </Panel>

                    <Panel title="Message pour le client">
                        <textarea className={field} rows={3} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="Conditions, délais, moyens de paiement (Airtel Money, Moov Money…)" />
                    </Panel>
                </div>

                <div className="space-y-4">
                    <Panel title="Client">
                        {doc ? (
                            <p className="font-semibold">{initial?.name}</p>
                        ) : (
                            <>
                                <CustomerPicker
                                    value={customer}
                                    onChange={(c) => {
                                        setCustomer(c);
                                        setData('customer_id', c ? String(c.id) : '');
                                    }}
                                />
                                {errors.customer_id && <span className="text-xs text-error-500">{errors.customer_id}</span>}
                            </>
                        )}
                    </Panel>
                    <Panel title="Détails">
                        <div className="space-y-3 text-sm">
                            <label className="block">
                                <span className="mb-1 block font-medium">Objet</span>
                                <input className={field} value={data.title} onChange={(e) => setData('title', e.target.value)} placeholder="ex. Lunettes progressives, Mariage du 12/12" />
                            </label>
                            <label className="block">
                                <span className="mb-1 block font-medium">Date</span>
                                <input type="date" className={field} value={data.issued_on} onChange={(e) => setData('issued_on', e.target.value)} />
                            </label>
                            {quote ? (
                                <label className="block">
                                    <span className="mb-1 block font-medium">Valable jusqu’au</span>
                                    <input type="date" className={field} value={data.valid_until} onChange={(e) => setData('valid_until', e.target.value)} />
                                    <span className="text-xs text-gray-500">Par défaut : 30 jours.</span>
                                </label>
                            ) : (
                                <label className="block">
                                    <span className="mb-1 block font-medium">À payer avant le</span>
                                    <input type="date" className={field} value={data.due_on} onChange={(e) => setData('due_on', e.target.value)} />
                                    <span className="text-xs text-gray-500">Par défaut : 15 jours.</span>
                                </label>
                            )}
                            {stores.length > 1 && (
                                <label className="block">
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
                            {errors.store_id && <span className="text-xs text-error-500">{errors.store_id}</span>}
                        </div>
                    </Panel>
                    <button type="submit" className="mdash-btn brand w-full justify-center" disabled={processing || !data.customer_id}>
                        {processing ? 'Enregistrement…' : doc ? 'Enregistrer' : quote ? 'Créer le devis' : 'Créer la facture'}
                    </button>
                </div>
            </form>
        </Page>
    );
}

InvoiceEdit.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default InvoiceEdit;
