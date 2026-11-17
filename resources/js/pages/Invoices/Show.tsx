import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel, formatDay } from '../../components/page/Page';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
import { DocumentRow, money, STATUS_TONE } from './shared';

/*
 * Un devis ou une facture, avec le client à côté : son parcours, ce qu'il
 * a dépensé, ses autres documents ouverts, et WhatsApp à portée de main.
 */

interface Doc extends DocumentRow {
    notes: string | null;
    subtotal: number;
    discount_amount: number;
    valid_until: string | null;
    accepted_at: string | null;
    accepted_by_name: string | null;
    viewed_at: string | null;
    sent_at: string | null;
    public_url: string;
    share_url: string | null;
    quote: { id: number; number: string } | null;
    children: { id: number; number: string; type_label: string; total: number; status_label: string }[];
    has_deposit: boolean;
    lines: { label: string; quantity: number; unit_price: number; amount: number }[];
    payments: { id: number; amount: number; method: string | null; paid_at: string }[];
}

interface PageProps {
    document: Doc;
    customerCard: {
        id: number;
        name: string;
        phone_e164: string | null;
        stage: string | null;
        total_spent: number;
        purchases_count: number;
        last_purchase_at: string | null;
        open_documents: { id: number; number: string; type_label: string; total: number; status_label: string }[];
    };
    paymentMethods: Record<string, string>;
    automation: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function PaymentForm({ doc, methods }: { doc: Doc; methods: Record<string, string> }) {
    const { data, setData, post, processing, errors, reset } = useForm({ amount: String(doc.balance), method: 'cash', paid_on: new Date().toISOString().slice(0, 10) });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/invoices/${doc.id}/payments`, { preserveScroll: true, onSuccess: () => reset() });
    };

    return (
        <form onSubmit={submit} className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-4">
            <input className={field} type="number" min={1} max={doc.balance} value={data.amount} onChange={(e) => setData('amount', e.target.value)} aria-label="Montant reçu" required />
            <select className={field} value={data.method} onChange={(e) => setData('method', e.target.value)} aria-label="Moyen de paiement">
                {Object.entries(methods).map(([k, l]) => (
                    <option key={k} value={k}>
                        {l}
                    </option>
                ))}
            </select>
            <input className={field} type="date" value={data.paid_on} onChange={(e) => setData('paid_on', e.target.value)} aria-label="Date du paiement" />
            <button type="submit" className="mdash-btn brand justify-center" disabled={processing}>
                Encaisser
            </button>
            {errors.amount && <span className="text-xs text-error-500 sm:col-span-4">{errors.amount}</span>}
        </form>
    );
}

function InvoiceShow() {
    const { document: doc, customerCard: c, paymentMethods, automation } = usePage<PageProps>().props;
    const quote = doc.type === 'quote';
    const [deposit, setDeposit] = useState('30');
    const post = (path: string, data = {}) => router.post(`/invoices/${doc.id}/${path}`, data, { preserveScroll: true });
    const share = () => doc.share_url && post('sent');

    const steps = [
        { label: 'Créé', at: doc.issued_on, done: true },
        { label: 'Envoyé par WhatsApp', at: doc.sent_at, done: !!doc.sent_at },
        { label: 'Ouvert par le client', at: doc.viewed_at, done: !!doc.viewed_at },
        quote ? { label: doc.status === 'refused' ? 'Refusé' : 'Accepté', at: doc.accepted_at, done: ['accepted', 'invoiced', 'refused'].includes(doc.status) } : { label: 'Payée', at: doc.payments.at(-1)?.paid_at ?? null, done: doc.status === 'paid' },
    ];

    return (
        <Page
            title={`${doc.type_label} ${doc.number}`}
            subtitle={
                <>
                    <span className={`mdash-pill ${STATUS_TONE[doc.status] ?? 'info'}`}>{doc.status_label}</span> {doc.title && <span className="ml-1">{doc.title}</span>}
                </>
            }
            actions={
                <>
                    {doc.share_url && (
                        <a href={doc.share_url} target="_blank" rel="noopener noreferrer" onClick={share} className="mdash-btn brand">
                            <WhatsAppIcon />
                            {doc.sent_at ? 'Renvoyer' : 'Envoyer'} par WhatsApp
                        </a>
                    )}
                    <a href={`/invoices/${doc.id}/print`} target="_blank" rel="noreferrer" className="mdash-btn ghost">
                        Imprimer / PDF
                    </a>
                    {doc.editable && (
                        <Link href={`/invoices/${doc.id}/edit`} className="mdash-btn ghost">
                            Modifier
                        </Link>
                    )}
                </>
            }
        >
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Panel>
                        <ol className="mb-5 grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {steps.map((s) => (
                                <li key={s.label} className={`rounded-xl px-3 py-2 text-xs ${s.done ? 'bg-emerald-50 text-emerald-800' : 'bg-gray-50 text-gray-500 dark:bg-white/5'}`}>
                                    <span className="block font-semibold">{s.done ? '✓ ' : ''}{s.label}</span>
                                    {s.done && s.at && <span>{formatDay(s.at)}</span>}
                                </li>
                            ))}
                        </ol>
                        <div className="mdash-tbl">
                            <table className="mpage-table">
                                <thead>
                                    <tr>
                                        <th>Désignation</th>
                                        <th className="num">Qté</th>
                                        <th className="num">Prix</th>
                                        <th className="num">Montant</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {doc.lines.map((l, i) => (
                                        <tr key={i}>
                                            <td>{l.label}</td>
                                            <td className="num">{l.quantity}</td>
                                            <td className="num">{money(l.unit_price)}</td>
                                            <td className="num">{money(l.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="mt-3 ml-auto max-w-xs space-y-1 text-sm">
                            {doc.discount_amount > 0 && (
                                <>
                                    <div className="flex justify-between">
                                        <span>Sous-total</span>
                                        <span>{money(doc.subtotal)}</span>
                                    </div>
                                    <div className="flex justify-between">
                                        <span>{doc.kind === 'balance' ? 'Remise et acomptes' : 'Remise'}</span>
                                        <span>− {money(doc.discount_amount)}</span>
                                    </div>
                                </>
                            )}
                            <div className="flex justify-between border-t border-gray-200 pt-2 text-lg font-bold dark:border-gray-700">
                                <span>Total</span>
                                <span>{money(doc.total)}</span>
                            </div>
                            {!quote && doc.paid_amount > 0 && (
                                <div className="flex justify-between font-semibold text-emerald-700">
                                    <span>Reste à payer</span>
                                    <span>{money(doc.balance)}</span>
                                </div>
                            )}
                        </div>
                        {doc.notes && <p className="mt-4 text-sm whitespace-pre-line text-gray-600">{doc.notes}</p>}
                        {doc.accepted_by_name && (
                            <p className="mt-3 text-sm text-emerald-700">
                                Accepté en ligne par <b>{doc.accepted_by_name}</b>.
                            </p>
                        )}
                    </Panel>

                    {quote && ['draft', 'sent', 'expired'].includes(doc.status) && (
                        <Panel title="Réponse du client">
                            <p className="mb-3 text-sm text-gray-500">Votre client peut accepter en ligne depuis le lien WhatsApp. S’il vous répond autrement, notez-le ici.</p>
                            <div className="flex flex-wrap gap-2">
                                <button type="button" className="mdash-btn brand" onClick={() => post('accept')}>
                                    Le client accepte
                                </button>
                                <button type="button" className="mdash-btn ghost" onClick={() => post('refuse')}>
                                    Le client refuse
                                </button>
                                {doc.reminder_url && (
                                    <a href={doc.reminder_url} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost" onClick={() => post('reminded')}>
                                        <WhatsAppIcon />
                                        Relancer
                                    </a>
                                )}
                            </div>
                        </Panel>
                    )}

                    {quote && ['accepted', 'invoiced'].includes(doc.status) && (
                        <Panel title="Facturer ce devis">
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                {!doc.has_deposit && (
                                    <>
                                        <span>Acompte de</span>
                                        <input className={`${field} w-20`} type="number" min={1} max={99} value={deposit} onChange={(e) => setDeposit(e.target.value)} aria-label="Pourcentage d’acompte" />
                                        <span>%</span>
                                        <button type="button" className="mdash-btn ghost" onClick={() => post('convert', { deposit_percent: Number(deposit) })}>
                                            Facturer l’acompte
                                        </button>
                                        <span className="text-gray-400">ou</span>
                                    </>
                                )}
                                <button type="button" className="mdash-btn brand" onClick={() => post('convert')}>
                                    {doc.has_deposit ? 'Facturer le solde' : 'Facturer en totalité'}
                                </button>
                            </div>
                        </Panel>
                    )}

                    {!quote && ['open', 'partial', 'overdue'].includes(doc.status) && (
                        <Panel title="Enregistrer un paiement" sub="Le paiement s’ajoute à la fiche du client et à la caisse du jour.">
                            <PaymentForm doc={doc} methods={paymentMethods} />
                            <div className="mt-3 flex flex-wrap gap-2">
                                {doc.reminder_url && (
                                    <a href={doc.reminder_url} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost sm" onClick={() => post('reminded')}>
                                        <WhatsAppIcon />
                                        Relancer le client
                                    </a>
                                )}
                                {doc.paid_amount === 0 && (
                                    <button type="button" className="mdash-btn ghost sm" onClick={() => confirm('Annuler cette facture ?') && post('cancel')}>
                                        Annuler la facture
                                    </button>
                                )}
                            </div>
                            {!automation && <p className="mt-2 text-xs text-gray-500">Relance automatique des impayés : disponible à partir de l’offre Solo.</p>}
                        </Panel>
                    )}

                    {doc.payments.length > 0 && (
                        <Panel title="Paiements reçus">
                            <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                                {doc.payments.map((p) => (
                                    <li key={p.id} className="flex justify-between py-2">
                                        <span>
                                            {formatDay(p.paid_at)} · {p.method ?? 'Non précisé'}
                                        </span>
                                        <b>{money(p.amount)}</b>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}

                    {(doc.children.length > 0 || doc.quote) && (
                        <Panel title="Documents liés">
                            <ul className="space-y-1 text-sm">
                                {doc.quote && (
                                    <li>
                                        <Link href={`/invoices/${doc.quote.id}`} className="text-brand-text hover:underline">
                                            Devis {doc.quote.number}
                                        </Link>
                                    </li>
                                )}
                                {doc.children.map((child) => (
                                    <li key={child.id}>
                                        <Link href={`/invoices/${child.id}`} className="text-brand-text hover:underline">
                                            {child.type_label} {child.number}
                                        </Link>{' '}
                                        · {money(child.total)} · {child.status_label}
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                </div>

                <aside className="space-y-4">
                    <Panel title="Le client">
                        <Link href={`/customers/${c.id}`} className="text-lg font-semibold text-brand-text hover:underline">
                            {c.name}
                        </Link>
                        {c.stage && <span className="ml-2 mdash-pill info">{c.stage}</span>}
                        <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
                            <div className="rounded-xl bg-gray-50 p-2.5 dark:bg-white/5">
                                <dt className="text-xs text-gray-500">Dépensé chez vous</dt>
                                <dd className="font-semibold">{money(c.total_spent)}</dd>
                            </div>
                            <div className="rounded-xl bg-gray-50 p-2.5 dark:bg-white/5">
                                <dt className="text-xs text-gray-500">Visites</dt>
                                <dd className="font-semibold">{c.purchases_count}</dd>
                            </div>
                        </dl>
                        {c.last_purchase_at && <p className="mt-2 text-xs text-gray-500">Dernier achat le {formatDay(c.last_purchase_at)}</p>}
                        {c.phone_e164 && (
                            <a href={`https://wa.me/${c.phone_e164.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost sm mt-3">
                                <WhatsAppIcon />
                                Écrire sur WhatsApp
                            </a>
                        )}
                    </Panel>
                    {c.open_documents.length > 0 && (
                        <Panel title="Ses autres documents ouverts">
                            <ul className="space-y-1.5 text-sm">
                                {c.open_documents.map((d) => (
                                    <li key={d.id} className="flex justify-between gap-2">
                                        <Link href={`/invoices/${d.id}`} className="text-brand-text hover:underline">
                                            {d.type_label} {d.number}
                                        </Link>
                                        <span className="text-gray-500">{money(d.total)}</span>
                                    </li>
                                ))}
                            </ul>
                        </Panel>
                    )}
                    <Link href={`/invoices/create?type=${quote ? 'quote' : 'invoice'}&customer=${c.id}`} className="mdash-btn ghost w-full justify-center">
                        + {quote ? 'Nouveau devis' : 'Nouvelle facture'} pour ce client
                    </Link>
                </aside>
            </div>
        </Page>
    );
}

InvoiceShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default InvoiceShow;
