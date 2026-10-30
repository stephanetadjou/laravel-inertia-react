import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { formatDay, Page, Panel, StatStrip } from '../../components/page/Page';
import { money } from '../../components/families/families';

interface Line {
    id: number;
    name: string;
    article_code: string | null;
    family: string | null;
    raw_family: string | null;
    quantity: number;
    unit_price: number;
    discount_amount: number;
    amount: number;
    properties: Record<string, string> | null;
}

interface Ticket {
    id: number;
    receipt_number: string | null;
    purchased_at: string;
    type: 'sale' | 'return';
    status: 'valid' | 'cancelled';
    store: string;
    brand: string | null;
    amount: number;
    subtotal: number | null;
    discount_amount: number;
    quantity: number;
    payment_method: string | null;
    properties: Record<string, string> | null;
    customer: { id: number; name: string; phone: string; type: string } | null;
    buyer_name: string | null;
    customer_code: string | null;
    batch: { id: number; filename: string } | null;
    original: { id: number; receipt_number: string | null; purchased_at: string } | null;
    returns: { id: number; receipt_number: string | null; purchased_at: string; amount: number }[];
    cancelled_at: string | null;
    cancel_reason: string | null;
    lines: Line[];
}

interface PageProps {
    ticket: Ticket;
    canCorrect: boolean;
    [key: string]: unknown;
}

function PurchaseShow() {
    const { ticket, canCorrect } = usePage<PageProps>().props;
    const errors = usePage<{ errors: Record<string, string> }>().props.errors;
    const [reason, setReason] = useState('');
    const title = `${ticket.type === 'return' ? 'Retour' : 'Ticket'} ${ticket.receipt_number ? `n° ${ticket.receipt_number}` : `#${ticket.id}`}`;

    return (
        <Page
            title={title}
            subtitle={
                <>
                    <Link href="/purchases" className="mdash-link">
                        Ventes
                    </Link>{' '}
                    › {formatDay(ticket.purchased_at)} · {ticket.store}
                    {ticket.brand ? ` (${ticket.brand})` : ''}
                </>
            }
            actions={
                <>
                    {ticket.type === 'return' && <span className="mdash-pill warn">Retour</span>}
                    {ticket.status === 'cancelled' ? <span className="mdash-pill bad">Annulé</span> : <span className="mdash-pill ok">Valide</span>}
                </>
            }
        >
            {ticket.status === 'cancelled' && (
                <div className="mdash-flash bad">
                    Ticket annulé le {formatDay(ticket.cancelled_at, true)} : {ticket.cancel_reason}. Il n’entre plus dans les chiffres.
                </div>
            )}

            <StatStrip
                items={[
                    { label: 'Montant net', value: money(ticket.amount) },
                    { label: 'Remises', value: money(ticket.discount_amount) },
                    { label: 'Articles', value: ticket.quantity },
                    { label: 'Paiement', value: ticket.payment_method ?? '—' },
                ]}
            />

            <div className="mdash-grid">
                <Panel title="Client" className="c4">
                    <dl className="mpage-dl">
                        {ticket.customer ? (
                            <>
                                <div className="mpage-dl-row">
                                    <dt>Fiche</dt>
                                    <dd>
                                        <Link href={`/customers/${ticket.customer.id}`} className="mdash-link">
                                            {ticket.customer.name}
                                        </Link>
                                        {ticket.customer.type === 'business' && <span className="mdash-pill info"> Entreprise</span>}
                                    </dd>
                                </div>
                                <div className="mpage-dl-row">
                                    <dt>Téléphone</dt>
                                    <dd className="mono">{ticket.customer.phone}</dd>
                                </div>
                            </>
                        ) : (
                            <div className="mpage-dl-row">
                                <dt>Vente anonyme</dt>
                                <dd>{ticket.buyer_name ? `${ticket.buyer_name} (sans numéro valide)` : 'Aucun numéro de téléphone valide'}</dd>
                            </div>
                        )}
                        {ticket.customer_code && (
                            <div className="mpage-dl-row">
                                <dt>Code client</dt>
                                <dd className="mono">{ticket.customer_code}</dd>
                            </div>
                        )}
                        {ticket.batch && (
                            <div className="mpage-dl-row">
                                <dt>Import</dt>
                                <dd>
                                    <Link href={`/sales-imports/${ticket.batch.id}`} className="mdash-link">
                                        n° {ticket.batch.id} · {ticket.batch.filename}
                                    </Link>
                                </dd>
                            </div>
                        )}
                        {ticket.original && (
                            <div className="mpage-dl-row">
                                <dt>Ticket d’origine</dt>
                                <dd>
                                    <Link href={`/purchases/${ticket.original.id}`} className="mdash-link">
                                        {ticket.original.receipt_number ?? `#${ticket.original.id}`} du {formatDay(ticket.original.purchased_at)}
                                    </Link>
                                </dd>
                            </div>
                        )}
                        {ticket.returns.map((r) => (
                            <div key={r.id} className="mpage-dl-row">
                                <dt>Retour</dt>
                                <dd>
                                    <Link href={`/purchases/${r.id}`} className="mdash-link">
                                        {r.receipt_number ?? `#${r.id}`} du {formatDay(r.purchased_at)} ({money(r.amount)})
                                    </Link>
                                </dd>
                            </div>
                        ))}
                        {Object.entries(ticket.properties ?? {}).map(([k, v]) => (
                            <div key={k} className="mpage-dl-row">
                                <dt>{k.replace(/_/g, ' ')}</dt>
                                <dd>{v}</dd>
                            </div>
                        ))}
                    </dl>
                </Panel>

                <Panel title="Lignes du ticket" className="c8">
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Article</th>
                                    <th>Famille</th>
                                    <th className="r">Qté</th>
                                    <th className="r">Prix</th>
                                    <th className="r">Remise</th>
                                    <th className="r">Montant</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ticket.lines.map((l) => (
                                    <tr key={l.id}>
                                        <td className="cell-main">
                                            <b>{l.name}</b>
                                            {l.article_code && <span className="mono">{l.article_code}</span>}
                                        </td>
                                        <td>
                                            {l.family ?? (
                                                <span className="mdash-pill warn" title="Libellé à ranger dans « À classer »">
                                                    {l.raw_family ?? 'À classer'}
                                                </span>
                                            )}
                                        </td>
                                        <td className="r num">{formatNumber(l.quantity)}</td>
                                        <td className="r num">{formatNumber(l.unit_price)}</td>
                                        <td className="r num">{l.discount_amount ? formatNumber(l.discount_amount) : '—'}</td>
                                        <td className="r num">{money(l.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                {canCorrect && (
                    <Panel title="Correction" sub="Une vente n’est jamais supprimée : elle est annulée, avec son motif, et l’action est journalisée." className="c12">
                        {ticket.status === 'cancelled' ? (
                            <div className="mpage-form-actions" style={{ padding: '0 18px 18px', justifyContent: 'flex-start' }}>
                                <button type="button" className="mdash-btn ghost" onClick={() => router.post(`/purchases/${ticket.id}/restore`, {}, { preserveScroll: true })}>
                                    Rétablir ce ticket
                                </button>
                            </div>
                        ) : (
                            <div className="mpage-form" style={{ padding: '0 18px 18px' }}>
                                <div className="mpage-field">
                                    <label htmlFor="reason">Motif de l’annulation</label>
                                    <input id="reason" type="text" value={reason} placeholder="ex. ticket saisi deux fois en caisse" onChange={(e) => setReason(e.target.value)} />
                                    {errors?.reason && <span className="err">{errors.reason}</span>}
                                </div>
                                <div className="mpage-form-actions" style={{ justifyContent: 'flex-start' }}>
                                    <button
                                        type="button"
                                        className="mdash-btn ghost"
                                        disabled={!reason.trim()}
                                        onClick={() => router.post(`/purchases/${ticket.id}/cancel`, { reason }, { preserveScroll: true })}
                                    >
                                        Annuler ce ticket
                                    </button>
                                </div>
                            </div>
                        )}
                    </Panel>
                )}
            </div>
        </Page>
    );
}

PurchaseShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default PurchaseShow;
