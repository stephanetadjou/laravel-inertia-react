import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, StatStrip } from '../../components/page/Page';

/*
 * Analyses › Sources : d'où viennent vos clients (Pub Facebook, Pub
 * Instagram, Direct) et combien ils coûtent. Prospects, rendez-vous, ventes
 * et CA par source et par mois ; le budget pub saisi donne le coût par
 * nouveau client.
 */

interface Row {
    source: string;
    month: string;
    prospects: number;
    appointments: number;
    customers: number;
    sales: number;
    revenue: number;
    budget: number;
    cost_per_customer: number | null;
}

interface PageProps {
    months: string[];
    sources: { key: string; label: string }[];
    rows: Row[];
    currency: string;
    canEditBudgets: boolean;
    [key: string]: unknown;
}

const nf = new Intl.NumberFormat('fr-FR');
const monthLabel = (m: string) => new Date(`${m}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'short', year: '2-digit' });
const PAID = ['facebook_ad', 'instagram_ad'];

function SourcesReport() {
    const { months, sources, rows, currency, canEditBudgets } = usePage<PageProps>().props;
    const [month, setMonth] = useState(months[months.length - 1]);
    const money = (n: number) => `${nf.format(n)} ${currency}`;
    const of = (source: string, m: string) => rows.find((r) => r.source === source && r.month === m)!;
    const monthRows = sources.map((s) => ({ ...of(s.key, month), label: s.label }));
    const totals = monthRows.reduce((t, r) => ({ prospects: t.prospects + r.prospects, customers: t.customers + r.customers, revenue: t.revenue + r.revenue, budget: t.budget + r.budget }), { prospects: 0, customers: 0, revenue: 0, budget: 0 });
    const paidCustomers = monthRows.filter((r) => PAID.includes(r.source)).reduce((n, r) => n + r.customers, 0);

    const form = useForm({
        budgets: months.flatMap((m) => PAID.map((source) => ({ source, month: m, amount: String(of(source, m)?.budget || '') }))),
    });
    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ budgets: d.budgets.map((b) => ({ ...b, amount: b.amount === '' ? 0 : Number(b.amount) })) }));
        form.put('/sources/budgets', { preserveScroll: true });
    };
    const setBudget = (source: string, m: string, amount: string) =>
        form.setData('budgets', form.data.budgets.map((b) => (b.source === source && b.month === m ? { ...b, amount } : b)));

    const noData = rows.every((r) => r.prospects === 0 && r.sales === 0);

    return (
        <Page
            title="Sources"
            subtitle="D’où viennent vos clients, et combien vous coûte chaque nouveau client"
            filters={
                <label className="flex items-center gap-2 text-sm">
                    Mois
                    <select value={month} onChange={(e) => setMonth(e.target.value)} className="rounded-md border border-gray-200 px-2 py-1 dark:border-gray-700 dark:bg-gray-900">
                        {[...months].reverse().map((m) => (
                            <option key={m} value={m}>
                                {monthLabel(m)}
                            </option>
                        ))}
                    </select>
                </label>
            }
        >
            <StatStrip
                items={[
                    { label: 'Prospects du mois', value: nf.format(totals.prospects) },
                    { label: 'Nouveaux clients', value: nf.format(totals.customers) },
                    { label: 'CA du mois', value: money(totals.revenue) },
                    { label: 'Coût par client (pubs)', value: totals.budget > 0 && paidCustomers > 0 ? money(Math.round(totals.budget / paidCustomers)) : '—' },
                ]}
            />

            {noData ? (
                <Panel>
                    <Empty title="Pas encore de source enregistrée">
                        Quand un client écrit sur WhatsApp après avoir cliqué sur une pub Facebook ou Instagram (« Click to WhatsApp »), NENTIX note la pub sur sa fiche. Les autres arrivent en « Direct ».
                    </Empty>
                </Panel>
            ) : (
                <Panel title={`Par source · ${monthLabel(month)}`} className="c12">
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    <th className="num">Prospects</th>
                                    <th className="num">RDV</th>
                                    <th className="num">Nouveaux clients</th>
                                    <th className="num">Ventes</th>
                                    <th className="num">CA</th>
                                    <th className="num">Budget pub</th>
                                    <th className="num">Coût par client</th>
                                </tr>
                            </thead>
                            <tbody>
                                {monthRows.map((r) => (
                                    <tr key={r.source}>
                                        <td className="cell-main">{r.label}</td>
                                        <td className="num">{nf.format(r.prospects)}</td>
                                        <td className="num">{nf.format(r.appointments)}</td>
                                        <td className="num">{nf.format(r.customers)}</td>
                                        <td className="num">{nf.format(r.sales)}</td>
                                        <td className="num">{money(r.revenue)}</td>
                                        <td className="num">{PAID.includes(r.source) ? money(r.budget) : '—'}</td>
                                        <td className="num">{r.cost_per_customer !== null ? money(r.cost_per_customer) : '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}

            <div className="mdash-grid mt-4">
                <Panel title="Évolution sur 6 mois" sub="Prospects arrivés, par source" className="c7">
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Source</th>
                                    {months.map((m) => (
                                        <th key={m} className="num">
                                            {monthLabel(m)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {sources.map((s) => (
                                    <tr key={s.key}>
                                        <td className="cell-main">{s.label}</td>
                                        {months.map((m) => (
                                            <td key={m} className="num">
                                                {nf.format(of(s.key, m).prospects)}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
                {canEditBudgets && (
                    <Panel title="Budget pub" sub={`Montant dépensé par mois (${currency})`} className="c5">
                        <form onSubmit={save} className="space-y-3 p-5 text-sm">
                            <div className="mdash-tbl">
                                <table className="mpage-table">
                                    <thead>
                                        <tr>
                                            <th>Mois</th>
                                            <th>Facebook</th>
                                            <th>Instagram</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {[...months].reverse().map((m) => (
                                            <tr key={m}>
                                                <td>{monthLabel(m)}</td>
                                                {PAID.map((source) => (
                                                    <td key={source}>
                                                        <input
                                                            inputMode="numeric"
                                                            value={form.data.budgets.find((b) => b.source === source && b.month === m)?.amount ?? ''}
                                                            onChange={(e) => setBudget(source, m, e.target.value.replace(/\D/g, ''))}
                                                            className="w-28 rounded-md border border-gray-200 px-2 py-1 text-right dark:border-gray-700 dark:bg-gray-900"
                                                            aria-label={`Budget ${source === 'facebook_ad' ? 'Facebook' : 'Instagram'} ${monthLabel(m)}`}
                                                        />
                                                    </td>
                                                ))}
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            <button type="submit" className="mdash-btn brand" disabled={form.processing}>
                                Enregistrer les budgets
                            </button>
                        </form>
                    </Panel>
                )}
            </div>
        </Page>
    );
}

SourcesReport.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default SourcesReport;
