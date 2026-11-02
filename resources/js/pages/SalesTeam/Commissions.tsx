import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, StatStrip, formatDay } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';

/*
 * Primes des commerciaux : dues quand le client apporté fait son 2e paiement
 * d'abonnement. Export Excel mensuel pour la paie.
 */

interface PageProps {
    month: string;
    rows: { user_id: number; user: string; customer_id: number; customer: string; plan: string | null; second_payment_at: string; amount: number }[];
    totals: { user: string; count: number; amount: number }[];
    canExport: boolean;
    [key: string]: unknown;
}

const shift = (month: string, n: number) => {
    const d = new Date(`${month}-01T12:00:00`);
    d.setMonth(d.getMonth() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};

function Commissions() {
    const { month, rows, totals, canExport } = usePage<PageProps>().props;
    const label = new Date(`${month}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });

    return (
        <Page
            title="Primes des commerciaux"
            subtitle={`${label} · dues au 2e paiement du client apporté (Paramètres › Prospects)`}
            actions={
                <>
                    <button type="button" className="mdash-btn ghost" onClick={() => router.get('/commissions', { month: shift(month, -1) })} aria-label="Mois précédent">
                        ‹
                    </button>
                    <button type="button" className="mdash-btn ghost" onClick={() => router.get('/commissions', { month: shift(month, 1) })} aria-label="Mois suivant">
                        ›
                    </button>
                    {canExport && (
                        <a href={`/commissions/export?month=${month}`} className="mdash-btn brand">
                            Export Excel (paie)
                        </a>
                    )}
                </>
            }
        >
            <StatStrip items={totals.map((t) => ({ label: t.user, value: `${formatNumber(t.amount)} F`, hint: `${t.count} client(s)` }))} />
            <Panel>
                {rows.length === 0 ? (
                    <Empty title="Aucune prime ce mois-ci">Une prime naît quand un client apporté par un commercial paie son 2e mois d’abonnement.</Empty>
                ) : (
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Commercial</th>
                                    <th>Client apporté</th>
                                    <th>Formule</th>
                                    <th>2e paiement</th>
                                    <th className="num">Prime</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((r) => (
                                    <tr key={`${r.customer_id}-${r.second_payment_at}`}>
                                        <td>{r.user}</td>
                                        <td>
                                            <Link href={`/customers/${r.customer_id}`} className="text-brand-text hover:underline">
                                                {r.customer}
                                            </Link>
                                        </td>
                                        <td>{r.plan}</td>
                                        <td>{formatDay(r.second_payment_at)}</td>
                                        <td className="num">{formatNumber(r.amount)} F</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </Page>
    );
}

Commissions.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default Commissions;
