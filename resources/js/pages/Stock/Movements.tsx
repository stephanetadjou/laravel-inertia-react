import { usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Pager, Paginated, Panel, formatDay, useQueryFilters } from '../../components/page/Page';

/* Stock › Mouvements : ventes, annulations, entrées et inventaires. */

interface Movement {
    id: number;
    at: string;
    product: string | null;
    quantity: number;
    quantity_after: number;
    reason: string;
    note: string | null;
    user: string | null;
}

function StockMovements() {
    const { movements } = usePage<{ movements: Paginated<Movement> }>().props;
    const visit = useQueryFilters('/stock/movements', {});

    return (
        <Page title="Mouvements de stock" subtitle="Chaque vente, entrée et inventaire">
            <Panel>
                {movements.data.length === 0 ? (
                    <Empty title="Aucun mouvement" />
                ) : (
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Produit</th>
                                    <th>Motif</th>
                                    <th className="num">Mouvement</th>
                                    <th className="num">Stock après</th>
                                </tr>
                            </thead>
                            <tbody>
                                {movements.data.map((m) => (
                                    <tr key={m.id}>
                                        <td>{formatDay(m.at, true)}</td>
                                        <td>{m.product}</td>
                                        <td>
                                            {m.reason}
                                            {(m.note || m.user) && <span className="block text-xs text-gray-500">{[m.note, m.user].filter(Boolean).join(' · ')}</span>}
                                        </td>
                                        <td className={`num font-semibold ${m.quantity < 0 ? 'text-red-600' : 'text-emerald-700'}`}>{m.quantity > 0 ? `+${m.quantity}` : m.quantity}</td>
                                        <td className="num">{m.quantity_after}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={movements} onPage={(page) => visit({ page })} unit="mouvements" />
            </Panel>
        </Page>
    );
}

StockMovements.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default StockMovements;
