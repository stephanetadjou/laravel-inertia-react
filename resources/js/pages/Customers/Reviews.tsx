import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, formatDay, Page, Pager, Paginated, Panel, Seg } from '../../components/page/Page';
import { money } from '../../components/families/families';

interface Review {
    id: number;
    reason: string;
    phone: string;
    incoming_name: string | null;
    store: string | null;
    created_at: string | null;
    customer: { id: number; name: string; purchases_count: number; total_spent: number; last_purchase_at: string | null } | null;
    purchases: { id: number; date: string; amount: number; receipt_number: string | null }[];
}

interface PageProps {
    status: 'pending' | 'attached' | 'separate';
    pendingCount: number;
    reviews: Paginated<Review>;
    [key: string]: unknown;
}

function Reviews() {
    const { status, pendingCount, reviews } = usePage<PageProps>().props;

    function decide(review: Review, decision: 'attached' | 'separate') {
        router.post(`/customers/a-verifier/${review.id}`, { decision }, { preserveScroll: true });
    }

    return (
        <Page
            title="À vérifier"
            subtitle={
                <>
                    <Link href="/customers" className="mdash-link">
                        Fiches clients
                    </Link>{' '}
                    › ventes importées avec un numéro connu mais un autre nom
                </>
            }
            filters={
                <Seg
                    label="Statut"
                    value={status}
                    options={[
                        { key: 'pending', label: `À traiter (${formatNumber(pendingCount)})` },
                        { key: 'attached', label: 'Rattachées' },
                        { key: 'separate', label: 'Gardées séparées' },
                    ]}
                    onChange={(key) => router.get('/customers/a-verifier', key === 'pending' ? {} : { status: key }, { preserveState: true })}
                />
            }
        >
            <div className="mpage-note">
                NENTIX ne rattache jamais d’office une vente quand le nom diffère trop de celui de la fiche, ou quand le numéro est partagé (famille, standard). Rattachez
                si c’est la même personne ; sinon gardez la vente séparée : elle reste dans le chiffre d’affaires sans être attribuée à ce client.
            </div>

            <Panel>
                {reviews.data.length === 0 ? (
                    <Empty title={status === 'pending' ? 'Rien à vérifier' : 'Aucune décision pour ce statut'} />
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Numéro</th>
                                    <th>Nom dans le fichier</th>
                                    <th>Fiche existante</th>
                                    <th>Ventes en attente</th>
                                    <th>Motif</th>
                                    {status === 'pending' && <th className="r">Décision</th>}
                                </tr>
                            </thead>
                            <tbody>
                                {reviews.data.map((r) => (
                                    <tr key={r.id}>
                                        <td className="mono">{r.phone}</td>
                                        <td className="cell-main">
                                            <b>{r.incoming_name ?? '—'}</b>
                                            <span>{r.store}</span>
                                        </td>
                                        <td className="cell-main">
                                            {r.customer ? (
                                                <>
                                                    <Link href={`/customers/${r.customer.id}`}>
                                                        <b>{r.customer.name}</b>
                                                    </Link>
                                                    <span>
                                                        {formatNumber(r.customer.purchases_count)} achat(s) · {money(r.customer.total_spent)}
                                                    </span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="wrap">
                                            {r.purchases.map((p) => (
                                                <div key={p.id}>
                                                    <Link href={`/purchases/${p.id}`} className="mdash-link">
                                                        {formatDay(p.date)} · {money(p.amount)}
                                                        {p.receipt_number ? ` · n° ${p.receipt_number}` : ''}
                                                    </Link>
                                                </div>
                                            ))}
                                        </td>
                                        <td>
                                            <span className="mdash-pill warn">{r.reason}</span>
                                        </td>
                                        {status === 'pending' && (
                                            <td className="r">
                                                <span className="mpage-rowlinks">
                                                    <button type="button" onClick={() => decide(r, 'attached')}>
                                                        Même personne : rattacher
                                                    </button>
                                                    <button type="button" onClick={() => decide(r, 'separate')}>
                                                        Autre personne
                                                    </button>
                                                </span>
                                            </td>
                                        )}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={reviews} onPage={(n) => router.get('/customers/a-verifier', { status: status === 'pending' ? undefined : status, page: n }, { preserveScroll: true })} unit="rapprochements" />
            </Panel>
        </Page>
    );
}

Reviews.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default Reviews;
