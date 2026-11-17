import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, SelectFilter, StatStrip, useQueryFilters } from '../../components/page/Page';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';

/*
 * Application « Caisse » : la journée de l'établissement. Ce qui est entré
 * (par moyen de paiement), les clients servis à remercier, les dépenses en
 * espèces, puis la clôture du soir avec l'écart de caisse.
 */

interface PageProps {
    day: string;
    dayLabel: string;
    isToday: boolean;
    store: { id: number; name: string };
    stores: { id: number; name: string }[];
    totals: { in: number; out: number; count: number; customers: number; new_customers: number; cash_in: number; cash_out: number; opening: number; cash_expected: number };
    byMethod: { method: string; label: string; total: number; count: number }[];
    sales: { id: number; time: string; amount: number; label: string; source: string; method: string; customer: { id: number; name: string } | null }[];
    expenses: { id: number; amount: number; label: string; category: string; method: string }[];
    customers: { id: number; name: string; total: number; visits: number; is_new: boolean; thanks_url: string | null }[];
    closing: null | { opening_float: number; cash_expected: number; cash_counted: number; difference: number; note: string | null; closed_at: string; closed_by: string | null };
    week: { day: string; label: string; total: number }[];
    hasExpenses: boolean;
    canReopen: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const money = (n: number) => `${new Intl.NumberFormat('fr-FR').format(n)} F`;

function shiftDay(day: string, delta: number): string {
    const d = new Date(`${day}T12:00:00`);
    d.setDate(d.getDate() + delta);
    return d.toISOString().slice(0, 10);
}

function CloseForm() {
    const { day, store, totals } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({ store_id: store.id, day, opening_float: String(totals.opening), cash_counted: '', note: '' });
    const expected = Number(data.opening_float || 0) + totals.cash_in - totals.cash_out;
    const diff = data.cash_counted === '' ? null : Number(data.cash_counted) - expected;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/cash/close', { preserveScroll: true });
    };

    return (
        <form onSubmit={submit} className="space-y-3 text-sm">
            <label className="block">
                <span className="mb-1 block font-medium">Fond de caisse du matin</span>
                <input className={field} type="number" min={0} value={data.opening_float} onChange={(e) => setData('opening_float', e.target.value)} />
            </label>
            <div className="rounded-xl bg-gray-50 p-3 dark:bg-white/5">
                <div className="flex justify-between">
                    <span>+ Espèces reçues</span>
                    <span>{money(totals.cash_in)}</span>
                </div>
                <div className="flex justify-between">
                    <span>− Dépenses en espèces</span>
                    <span>{money(totals.cash_out)}</span>
                </div>
                <div className="mt-1 flex justify-between border-t border-gray-200 pt-1 font-semibold dark:border-gray-700">
                    <span>Espèces attendues</span>
                    <span>{money(expected)}</span>
                </div>
            </div>
            <label className="block">
                <span className="mb-1 block font-medium">Espèces comptées dans la caisse</span>
                <input className={field} type="number" min={0} value={data.cash_counted} onChange={(e) => setData('cash_counted', e.target.value)} required />
                {errors.cash_counted && <span className="text-xs text-error-500">{errors.cash_counted}</span>}
            </label>
            {diff !== null && (
                <p className={`rounded-lg px-3 py-2 font-semibold ${diff === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'}`}>
                    {diff === 0 ? 'Aucun écart : parfait !' : `Écart de ${diff > 0 ? '+' : ''}${money(diff)}`}
                </p>
            )}
            <textarea className={field} rows={2} placeholder="Remarque (facultatif)" value={data.note} onChange={(e) => setData('note', e.target.value)} />
            <button type="submit" className="mdash-btn brand w-full justify-center" disabled={processing}>
                Clôturer la journée
            </button>
        </form>
    );
}

function CashIndex() {
    const { day, dayLabel, isToday, store, stores, totals, byMethod, sales, expenses, customers, closing, week, hasExpenses, canReopen } = usePage<PageProps>().props;
    const visit = useQueryFilters('/cash', { day, store: store.id });
    const maxWeek = Math.max(1, ...week.map((w) => w.total));

    return (
        <Page
            title={isToday ? 'Caisse du jour' : 'Caisse'}
            subtitle={`${dayLabel} · ${store.name}`}
            filters={
                <>
                    <span className="inline-flex items-center gap-1">
                        <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ day: shiftDay(day, -1) })} aria-label="Jour précédent">
                            ‹
                        </button>
                        <input type="date" className="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900" value={day} max={new Date().toISOString().slice(0, 10)} onChange={(e) => visit({ day: e.target.value })} aria-label="Jour" />
                        {!isToday && (
                            <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ day: shiftDay(day, 1) })} aria-label="Jour suivant">
                                ›
                            </button>
                        )}
                    </span>
                    {stores.length > 1 && <SelectFilter label="Établissement" value={store.id} options={stores.map((s) => ({ value: s.id, label: s.name }))} allLabel="—" onChange={(s) => visit({ store: s })} />}
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Encaissé', value: money(totals.in), hint: `${totals.count} vente(s)`, tone: 'ok' },
                    { label: 'Clients servis', value: totals.customers, hint: totals.new_customers ? `dont ${totals.new_customers} nouveau(x)` : undefined },
                    ...(hasExpenses ? [{ label: 'Dépenses', value: money(totals.out) }] : []),
                    { label: 'Espèces attendues', value: money(totals.cash_expected) },
                ]}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <div className="space-y-4 lg:col-span-2">
                    <Panel title="Par moyen de paiement">
                        {byMethod.length === 0 ? (
                            <p className="text-sm text-gray-500">Aucun encaissement pour ce jour.</p>
                        ) : (
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                                {byMethod.map((m) => (
                                    <div key={m.method} className="rounded-xl border border-gray-100 bg-gray-50 p-3 dark:border-gray-800 dark:bg-white/5">
                                        <span className="block text-xs text-gray-500">{m.label}</span>
                                        <span className="block text-lg font-bold">{money(m.total)}</span>
                                        <span className="text-xs text-gray-500">{m.count} vente(s)</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </Panel>

                    <Panel title="Clients servis" sub="Un merci sur WhatsApp le jour même fait revenir vos clients.">
                        {customers.length === 0 ? (
                            <Empty title="Pas encore de client aujourd’hui" />
                        ) : (
                            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                {customers.map((c) => (
                                    <li key={c.id} className="flex flex-wrap items-center gap-3 py-2.5 text-sm">
                                        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 font-semibold text-blue-700">{c.name.charAt(0).toUpperCase()}</span>
                                        <span className="min-w-0 flex-1">
                                            <Link href={`/customers/${c.id}`} className="font-medium text-brand-text hover:underline">
                                                {c.name}
                                            </Link>
                                            {c.is_new && <span className="ml-2 mdash-pill ok">Nouveau</span>}
                                            <span className="block text-xs text-gray-500">{c.visits} visite(s) au total</span>
                                        </span>
                                        <b>{money(c.total)}</b>
                                        {c.thanks_url && (
                                            <a href={c.thanks_url} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost sm">
                                                <WhatsAppIcon />
                                                Remercier
                                            </a>
                                        )}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Panel>

                    <Panel title="Ventes du jour">
                        {sales.length === 0 ? (
                            <p className="text-sm text-gray-500">Aucune vente.</p>
                        ) : (
                            <div className="mdash-tbl">
                                <table className="mpage-table">
                                    <thead>
                                        <tr>
                                            <th>Heure</th>
                                            <th>Client</th>
                                            <th>Objet</th>
                                            <th>Paiement</th>
                                            <th className="num">Montant</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {sales.map((s) => (
                                            <tr key={s.id}>
                                                <td>{s.time}</td>
                                                <td>{s.customer ? s.customer.name : '—'}</td>
                                                <td>
                                                    {s.label}
                                                    <span className="block text-xs text-gray-500">{s.source}</span>
                                                </td>
                                                <td>{s.method}</td>
                                                <td className="num font-semibold">{money(s.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>

                    {hasExpenses && (
                        <Panel title="Dépenses du jour" actions={<Link href="/expenses" className="text-sm font-semibold text-brand-text">Ouvrir Dépenses →</Link>}>
                            {expenses.length === 0 ? (
                                <p className="text-sm text-gray-500">Aucune dépense.</p>
                            ) : (
                                <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                                    {expenses.map((e) => (
                                        <li key={e.id} className="flex justify-between py-2">
                                            <span>
                                                {e.label}
                                                <span className="block text-xs text-gray-500">
                                                    {e.category} · {e.method}
                                                </span>
                                            </span>
                                            <b>− {money(e.amount)}</b>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Panel>
                    )}
                </div>

                <aside className="space-y-4">
                    <Panel title={closing ? 'Journée clôturée' : 'Clôture du soir'}>
                        {closing ? (
                            <div className="space-y-2 text-sm">
                                <p>
                                    Clôturée par <b>{closing.closed_by ?? '—'}</b>.
                                </p>
                                <div className="flex justify-between">
                                    <span>Espèces attendues</span>
                                    <span>{money(closing.cash_expected)}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span>Espèces comptées</span>
                                    <span>{money(closing.cash_counted)}</span>
                                </div>
                                <p className={`rounded-lg px-3 py-2 font-semibold ${closing.difference === 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800'}`}>
                                    {closing.difference === 0 ? 'Aucun écart' : `Écart : ${closing.difference > 0 ? '+' : ''}${money(closing.difference)}`}
                                </p>
                                {closing.note && <p className="text-gray-500">{closing.note}</p>}
                                {canReopen && (
                                    <button type="button" className="mdash-btn ghost sm" onClick={() => confirm('Rouvrir cette journée ?') && router.post('/cash/reopen', { store_id: store.id, day }, { preserveScroll: true })}>
                                        Rouvrir la journée
                                    </button>
                                )}
                            </div>
                        ) : (
                            <CloseForm />
                        )}
                    </Panel>

                    <Panel title="7 derniers jours">
                        <div className="flex h-32 items-end gap-1.5">
                            {week.map((w) => (
                                <button key={w.day} type="button" className="flex flex-1 flex-col items-center gap-1" onClick={() => visit({ day: w.day })} title={`${w.label} : ${money(w.total)}`}>
                                    <span className={`block w-full rounded-t-md ${w.day === day ? 'bg-teal-600' : 'bg-teal-200'}`} style={{ height: `${Math.max(4, (w.total * 100) / maxWeek)}px` }} />
                                    <span className="text-[10.5px] text-gray-500">{w.label}</span>
                                </button>
                            ))}
                        </div>
                    </Panel>
                </aside>
            </div>
        </Page>
    );
}

CashIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CashIndex;
