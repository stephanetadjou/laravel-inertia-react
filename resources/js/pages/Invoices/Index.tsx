import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Pager, Paginated, Panel, PlusIcon, SearchFilter, Seg, StatStrip, formatDay, useQueryFilters } from '../../components/page/Page';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
import { DocumentRow, money, STATUS_TONE } from './shared';

/*
 * Application « Factures » : Devis et Factures, chaque ligne rattachée à un
 * client, avec la relance WhatsApp prête quand il faut relancer.
 */

interface PageProps {
    type: 'quote' | 'invoice';
    filters: { status: string; q: string };
    stats: { quotes_pending: number; quotes_pending_count: number; unpaid: number; overdue_count: number; cashed_month: number; conversion: number | null };
    documents: Paginated<DocumentRow>;
    [key: string]: unknown;
}

function InvoicesIndex() {
    const { type, filters, stats, documents } = usePage<PageProps>().props;
    const quotes = type === 'quote';
    const visit = useQueryFilters('/invoices', { ...filters, type });

    return (
        <Page
            title={quotes ? 'Devis' : 'Factures'}
            subtitle={quotes ? 'Envoyés par WhatsApp, acceptés en un clic par vos clients' : 'Ce que vos clients vous doivent, et ce qu’ils ont payé'}
            actions={
                <Link href={`/invoices/create?type=${type}`} className="mdash-btn brand">
                    <PlusIcon />
                    {quotes ? 'Nouveau devis' : 'Nouvelle facture'}
                </Link>
            }
            filters={
                <>
                    <Seg
                        label="Statut"
                        value={filters.status}
                        options={
                            quotes
                                ? [
                                      { key: 'all', label: 'Tous' },
                                      { key: 'pending', label: 'En attente' },
                                      { key: 'accepted', label: 'Acceptés' },
                                      { key: 'lost', label: 'Refusés ou expirés' },
                                  ]
                                : [
                                      { key: 'all', label: 'Toutes' },
                                      { key: 'unpaid', label: 'À payer' },
                                      { key: 'overdue', label: 'En retard' },
                                      { key: 'paid', label: 'Payées' },
                                  ]
                        }
                        onChange={(status) => visit({ status, type })}
                    />
                    <SearchFilter value={filters.q} placeholder="Client, numéro ou objet" onSearch={(q) => visit({ q, type })} />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Devis en attente', value: money(stats.quotes_pending), hint: `${stats.quotes_pending_count} devis` },
                    { label: 'Taux d’acceptation (90 j)', value: stats.conversion === null ? '—' : `${stats.conversion} %` },
                    { label: 'Reste à encaisser', value: money(stats.unpaid), hint: stats.overdue_count ? `${stats.overdue_count} en retard` : undefined, tone: stats.overdue_count ? 'bad' : undefined },
                    { label: 'Encaissé ce mois', value: money(stats.cashed_month), tone: 'ok' },
                ]}
            />

            <Panel>
                {documents.data.length === 0 ? (
                    <Empty title={quotes ? 'Aucun devis' : 'Aucune facture'}>
                        {quotes ? 'Créez un devis, envoyez-le par WhatsApp : votre client l’accepte en un clic.' : 'Facturez un devis accepté, ou créez une facture directement.'}
                    </Empty>
                ) : (
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Numéro</th>
                                    <th>Client</th>
                                    <th>Objet</th>
                                    <th>{quotes ? 'Date' : 'Échéance'}</th>
                                    <th className="num">Montant</th>
                                    <th>Statut</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {documents.data.map((row) => (
                                    <tr key={row.id} className="cursor-pointer" onClick={() => router.visit(`/invoices/${row.id}`)}>
                                        <td>
                                            <span className="font-semibold">{row.number}</span>
                                            {row.kind !== 'full' && <span className="block text-xs text-gray-500">{row.type_label}</span>}
                                        </td>
                                        <td>
                                            <Link href={`/customers/${row.customer.id}`} onClick={(e) => e.stopPropagation()} className="font-medium text-brand-text hover:underline">
                                                {row.customer.name}
                                            </Link>
                                        </td>
                                        <td>{row.title ?? '—'}</td>
                                        <td>{formatDay(quotes ? row.issued_on : row.due_on)}</td>
                                        <td className="num">
                                            {money(row.total)}
                                            {!quotes && row.paid_amount > 0 && row.balance > 0 && <span className="block text-xs text-gray-500">reste {money(row.balance)}</span>}
                                        </td>
                                        <td>
                                            <span className={`mdash-pill ${STATUS_TONE[row.status] ?? 'info'}`}>{row.status_label}</span>
                                        </td>
                                        <td onClick={(e) => e.stopPropagation()}>
                                            {row.reminder_url && (
                                                <a
                                                    href={row.reminder_url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="mpage-rowlinks"
                                                    onClick={() => router.post(`/invoices/${row.id}/reminded`, {}, { preserveScroll: true, preserveState: true })}
                                                    title="Relancer par WhatsApp"
                                                >
                                                    <WhatsAppIcon />
                                                    Relancer
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={documents} onPage={(page) => visit({ page, type })} unit={quotes ? 'devis' : 'factures'} />
            </Panel>
        </Page>
    );
}

InvoicesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default InvoicesIndex;
