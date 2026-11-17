import { useForm, usePage } from '@inertiajs/react';

import { Empty, Page, Panel } from '../../components/page/Page';
import AppLayout from '../../layout/AppLayout';
import { Field, field, money } from './shared';

/* Comptes entreprise : tarif négocié appliqué aux devis, historique des locations. */

interface Account {
    id: number;
    customer_id: number;
    name: string;
    phone: string | null;
    discount_percent: number;
    day_rate: number | null;
    notes: string | null;
    bookings: number;
    revenue: number;
    last: string | null;
}

interface Props {
    accounts: Account[];
    companies: { id: number; name: string; company_name: string | null }[];
    [key: string]: unknown;
}

function LocationAccounts() {
    const { accounts, companies } = usePage<Props>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ customer_id: '', discount_percent: '10', day_rate: '', notes: '' });

    return (
        <Page title="Comptes entreprise" subtitle="Tarif négocié appliqué automatiquement à leurs devis">
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Panel title="Entreprises" className="lg:col-span-2">
                    {accounts.length === 0 ? (
                        <Empty title="Aucun compte entreprise">Ajoutez une entreprise cliente et son tarif négocié.</Empty>
                    ) : (
                        <div className="mdash-tbl">
                            <table className="mpage-table">
                                <thead>
                                    <tr>
                                        <th>Entreprise</th>
                                        <th>Tarif négocié</th>
                                        <th className="num">Locations</th>
                                        <th className="num">CA</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {accounts.map((a) => (
                                        <tr key={a.id}>
                                            <td>
                                                <a href={`/customers/${a.customer_id}`} className="font-medium underline-offset-2 hover:underline">
                                                    {a.name}
                                                </a>
                                                {a.notes && <span className="block text-xs text-gray-500">{a.notes}</span>}
                                            </td>
                                            <td>{a.day_rate ? `${money(a.day_rate)} / jour` : `-${a.discount_percent} %`}</td>
                                            <td className="num">{a.bookings}</td>
                                            <td className="num">{money(a.revenue)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
                <Panel title="Ajouter ou modifier">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            post('/location/accounts', { preserveScroll: true, onSuccess: () => reset() });
                        }}
                        className="space-y-3 text-sm"
                    >
                        <Field label="Client" error={errors.customer_id}>
                            <select className={field} value={data.customer_id} onChange={(e) => setData('customer_id', e.target.value)} required>
                                <option value="">—</option>
                                {[...accounts.map((a) => ({ id: a.customer_id, name: a.name })), ...companies.map((c) => ({ id: c.id, name: c.company_name || c.name }))].map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Remise (%)">
                            <input type="number" min={0} max={90} className={field} value={data.discount_percent} onChange={(e) => setData('discount_percent', e.target.value)} />
                        </Field>
                        <Field label="ou prix fixe par jour (FCFA)">
                            <input type="number" min={0} className={field} value={data.day_rate} onChange={(e) => setData('day_rate', e.target.value)} />
                        </Field>
                        <Field label="Note">
                            <input className={field} value={data.notes} onChange={(e) => setData('notes', e.target.value)} placeholder="ex. facturation fin de mois" />
                        </Field>
                        <button type="submit" className="mdash-btn brand" disabled={processing}>
                            Enregistrer
                        </button>
                    </form>
                    <p className="mt-3 text-xs text-gray-500">Les clients de type entreprise apparaissent ici ; marquez une fiche « entreprise » depuis Fiches clients.</p>
                </Panel>
            </div>
        </Page>
    );
}

LocationAccounts.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LocationAccounts;
