import { router, usePage } from '@inertiajs/react';

import { Empty, Page, Panel, StatStrip, useQueryFilters } from '../../components/page/Page';
import AppLayout from '../../layout/AppLayout';
import ShareLink from '../../components/share/ShareLink';
import { WhatsAppBanner, money } from './shared';

/*
 * Tableau de bord du loueur : occupation et CA par véhicule, demandes et
 * conversion, raisons de perte, cautions, soldes, immobilisations,
 * échéances, et relances WhatsApp à faire.
 */

interface VehicleRow {
    id: number;
    name: string;
    status: string;
    status_label: string;
    occupancy: number;
    days: number;
    revenue: number;
    costs: number;
    profit: number;
    by_month: Record<string, number>;
}

interface FollowUp {
    key: string;
    kind: string;
    kind_label: string;
    id: number;
    customer: string;
    detail: string;
    wa_url: string | null;
}

interface Props {
    board: {
        month: string;
        month_label: string;
        months: { key: string; label: string }[];
        vehicles: VehicleRow[];
        occupancy: number;
        revenue: number;
        requests: { total: number; won: number; lost: number; open: number; conversion: number | null; lost_reasons: { key: string; label: string; count: number }[]; sources: { key: string; label: string; count: number }[] };
        deposits: { count: number; amount: number };
        rides: { count: number; done: number; revenue: number };
        ride_requests: number;
        balances: number;
        immobilized: string[];
        alerts: { vehicle: string; deadlines: { label: string; due: string; late: boolean }[] }[];
    };
    followUps: FollowUp[];
    automatic: boolean;
    share: { url: string; rideUrl: string; name: string };
    whatsappUrl?: string | null;
    [key: string]: unknown;
}

function shift(month: string, delta: number) {
    const [y, m] = month.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function LocationDashboard() {
    const { board, followUps, automatic, whatsappUrl, share } = usePage<Props>().props;
    const visit = useQueryFilters('/location/dashboard', { month: board.month });
    const maxLost = Math.max(1, ...board.requests.lost_reasons.map((r) => r.count));

    const done = (f: FollowUp) => router.post(`/location/follow-ups/${f.kind}/${f.id}`, {}, { preserveScroll: true });

    return (
        <Page
            title="Location"
            subtitle={board.month_label}
            filters={
                <span className="inline-flex items-center gap-1">
                    <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ month: shift(board.month, -1) })} aria-label="Mois précédent">
                        ‹
                    </button>
                    <input type="month" className="rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900" value={board.month} onChange={(e) => visit({ month: e.target.value })} aria-label="Mois" />
                    <button type="button" className="mdash-btn ghost sm" onClick={() => visit({ month: shift(board.month, 1) })} aria-label="Mois suivant">
                        ›
                    </button>
                </span>
            }
        >
            <WhatsAppBanner url={whatsappUrl} />
            {board.ride_requests > 0 && (
                <a href="/location/requests" className="mdash-flash bad block">
                    {board.ride_requests} demande(s) de course à traiter : affectez un véhicule et un chauffeur.
                </a>
            )}
            <StatStrip
                items={[
                    { label: 'Occupation de la flotte', value: `${board.occupancy} %`, tone: board.occupancy < 40 ? 'warn' : 'ok' },
                    { label: 'CA des locations', value: money(board.revenue), hint: 'réservations du mois' },
                    { label: 'Demandes reçues', value: board.requests.total, hint: `${board.requests.open} en cours` },
                    { label: 'Taux de conversion', value: board.requests.conversion === null ? '—' : `${board.requests.conversion} %`, hint: `${board.requests.won} gagnée(s), ${board.requests.lost} perdue(s)` },
                    { label: 'Cautions en cours', value: money(board.deposits.amount), hint: `${board.deposits.count} réservation(s)` },
                    { label: 'Soldes à encaisser', value: money(board.balances), tone: board.balances > 0 ? 'warn' : undefined },
                    { label: 'Courses et transferts', value: board.rides.count, hint: `${board.rides.done} terminée(s) · ${money(board.rides.revenue)}` },
                ]}
            />

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Panel title="À relancer sur WhatsApp" sub={automatic ? 'Envoyées toutes seules quand le client a écrit dans les 24 h ; sinon, à envoyer ici.' : 'Envoyez depuis votre téléphone, puis marquez fait.'} className="lg:col-span-2">
                    {followUps.length === 0 ? (
                        <Empty title="Rien à relancer">Devis sans réponse, rappels de la veille et remerciements apparaîtront ici.</Empty>
                    ) : (
                        <ul className="divide-y divide-gray-100 dark:divide-white/5">
                            {followUps.map((f) => (
                                <li key={f.key} className="flex flex-wrap items-center justify-between gap-2 py-2.5 text-sm">
                                    <span>
                                        <span className="font-medium">{f.customer}</span> · <span className="text-gray-500">{f.kind_label}</span>
                                        <span className="block text-xs text-gray-500">{f.detail}</span>
                                    </span>
                                    <span className="flex gap-2">
                                        {f.wa_url && (
                                            <a href={f.wa_url} target="_blank" rel="noreferrer" className="mdash-btn brand sm" onClick={() => setTimeout(() => done(f), 300)}>
                                                WhatsApp
                                            </a>
                                        )}
                                        <button type="button" className="mdash-btn ghost sm" onClick={() => done(f)}>
                                            Fait
                                        </button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </Panel>

                <Panel title="Raisons de perte">
                    {board.requests.lost_reasons.length === 0 ? (
                        <p className="text-sm text-gray-500">Aucune demande perdue ce mois-ci.</p>
                    ) : (
                        <ul className="space-y-2.5">
                            {board.requests.lost_reasons.map((r) => (
                                <li key={r.key}>
                                    <span className="flex justify-between text-sm">
                                        <span>{r.label}</span>
                                        <b>{r.count}</b>
                                    </span>
                                    <span className="mt-1 block h-2 rounded-full bg-gray-100 dark:bg-white/5">
                                        <span className="block h-2 rounded-full bg-gradient-to-r from-rose-400 to-red-600" style={{ width: `${(r.count * 100) / maxLost}%` }} />
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {board.requests.sources.length > 0 && (
                        <p className="mt-4 text-xs text-gray-500">Sources : {board.requests.sources.map((s) => `${s.label} ${s.count}`).join(' · ')}</p>
                    )}
                </Panel>
            </div>

            <Panel title="Par véhicule" sub="Occupation du mois et chiffre d’affaires des 6 derniers mois">
                {board.vehicles.length === 0 ? (
                    <Empty title="Aucun véhicule">Ajoutez vos véhicules dans l’onglet Véhicules.</Empty>
                ) : (
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Véhicule</th>
                                    <th>Occupation</th>
                                    <th className="num">Dépenses du mois</th>
                                    <th className="num">Bénéfice du mois</th>
                                    {board.months.map((m) => (
                                        <th key={m.key} className="num">
                                            {m.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {board.vehicles.map((v) => (
                                    <tr key={v.id}>
                                        <td>
                                            <span className="font-medium">{v.name}</span>
                                            {v.status !== 'available' && <span className="block text-xs text-amber-600">{v.status_label}</span>}
                                        </td>
                                        <td>
                                            <span className="flex items-center gap-2">
                                                <span className="block h-2 w-20 rounded-full bg-gray-100 dark:bg-white/5">
                                                    <span className="block h-2 rounded-full bg-gradient-to-r from-sky-400 to-cyan-600" style={{ width: `${v.occupancy}%` }} />
                                                </span>
                                                {v.occupancy} %
                                            </span>
                                        </td>
                                        <td className="num">{v.costs ? money(v.costs) : '—'}</td>
                                        <td className={`num font-semibold ${v.profit < 0 ? 'text-error-600' : ''}`}>{money(v.profit)}</td>
                                        {board.months.map((m) => (
                                            <td key={m.key} className="num">
                                                {v.by_month[m.key] ? money(v.by_month[m.key]) : '—'}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Panel title="Véhicules immobilisés">
                    {board.immobilized.length === 0 ? <p className="text-sm text-gray-500">Aucun véhicule en entretien ou en panne.</p> : <p className="text-sm">{board.immobilized.join(' · ')}</p>}
                </Panel>
                <Panel title="Échéances (assurance, visite, vidange)">
                    {board.alerts.length === 0 ? (
                        <p className="text-sm text-gray-500">Rien dans les 30 prochains jours.</p>
                    ) : (
                        <ul className="space-y-1 text-sm">
                            {board.alerts.flatMap((a) =>
                                a.deadlines.map((d) => (
                                    <li key={`${a.vehicle}-${d.label}`} className={d.late ? 'text-error-600' : ''}>
                                        {a.vehicle} : {d.label} {d.late ? 'dépassé' : 'avant le'} {d.due}
                                    </li>
                                )),
                            )}
                        </ul>
                    )}
                </Panel>
            </div>
            <Panel title="Partager mon lien" sub="À mettre dans votre statut WhatsApp, vos réseaux sociaux et sur vos cartes">
                <div className="grid gap-5 lg:grid-cols-2">
                    <ShareLink url={share.url} label="Lien de votre entreprise" message={`Louez, réservez une course ou demandez un devis chez ${share.name} :`} filename="lien-entreprise.png" />
                    <ShareLink url={share.rideUrl} label="Lien des courses et transferts" message={`Besoin d’une course ou d’un transfert aéroport ? ${share.name} :`} filename="lien-courses.png" />
                </div>
            </Panel>
        </Page>
    );
}

LocationDashboard.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LocationDashboard;
