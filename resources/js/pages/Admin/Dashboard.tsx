import { Link, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Empty, formatDay, Page, Panel, StatStrip } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';
import { AdminOrganization, PlatformLogRow } from './shared';

/*
 * Back-office plateforme › Tableau de bord : entreprises par statut et par
 * secteur, inscriptions des 30 derniers jours, essais qui se terminent.
 */

interface PageProps {
    statuses: { key: string; label: string; total: number }[];
    trialExpired: number;
    total: number;
    sectors: { sector: string | null; label: string; total: number }[];
    signups: { day: string; total: number }[];
    signupsTotal: number;
    trialsEnding: AdminOrganization[];
    newLeads: number;
    logs: PlatformLogRow[];
    [key: string]: unknown;
}

function AdminDashboard() {
    const { statuses, trialExpired, total, sectors, signups, signupsTotal, trialsEnding, newLeads, logs } = usePage<PageProps>().props;
    const max = Math.max(1, ...signups.map((s) => s.total));
    const count = (key: string) => statuses.find((s) => s.key === key)?.total ?? 0;

    return (
        <Page title="Plateforme" subtitle="Toutes les entreprises inscrites sur NENTIX">
            <StatStrip
                items={[
                    { label: 'Entreprises', value: total },
                    { label: 'En essai', value: count('trial'), hint: trialExpired > 0 ? `dont ${trialExpired} essai(s) terminé(s)` : undefined, tone: trialExpired > 0 ? 'warn' : undefined },
                    { label: 'Abonnées', value: count('active'), tone: 'ok' },
                    { label: 'Suspendues', value: count('suspended'), tone: count('suspended') > 0 ? 'bad' : undefined },
                    { label: 'Inscriptions (30 j)', value: signupsTotal },
                    { label: 'Démos à traiter', value: newLeads, tone: newLeads > 0 ? 'warn' : undefined },
                ]}
            />

            <div className="mdash-grid">
                <Panel title="Inscriptions des 30 derniers jours" sub={`${formatNumber(signupsTotal)} entreprise(s)`} className="c8">
                    <div className="flex h-40 items-end gap-1 px-4 pb-4" role="img" aria-label="Inscriptions par jour">
                        {signups.map((s) => (
                            <div key={s.day} className="flex h-full flex-1 flex-col items-center justify-end" title={`${formatDay(s.day)} : ${s.total}`}>
                                <div className="w-full rounded-t bg-brand-500" style={{ height: `${(s.total / max) * 100}%`, minHeight: s.total > 0 ? 4 : 1, opacity: s.total > 0 ? 1 : 0.25 }} />
                            </div>
                        ))}
                    </div>
                </Panel>

                <Panel title="Par secteur" className="c4">
                    {sectors.length === 0 ? (
                        <Empty title="Aucune entreprise" />
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <tbody>
                                    {sectors.map((s) => (
                                        <tr key={s.sector ?? 'none'}>
                                            <td>
                                                <Link href={`/admin/organizations${s.sector ? `?sector=${s.sector}` : ''}`} className="mdash-link">
                                                    {s.label}
                                                </Link>
                                            </td>
                                            <td className="r num">{formatNumber(s.total)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel title="Essais qui se terminent dans 7 jours" className="c7">
                    {trialsEnding.length === 0 ? (
                        <Empty title="Aucun essai ne se termine cette semaine" />
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Entreprise</th>
                                        <th>Offre</th>
                                        <th>Fin d’essai</th>
                                        <th className="r">Clients</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {trialsEnding.map((o) => (
                                        <tr key={o.id}>
                                            <td className="cell-main">
                                                <Link href={`/admin/organizations/${o.id}`} className="mdash-link">
                                                    <b>{o.name}</b>
                                                </Link>
                                            </td>
                                            <td>
                                                {o.offer} · {o.plan_label}
                                            </td>
                                            <td>{formatDay(o.trial_ends_at)}</td>
                                            <td className="r num">{formatNumber(o.customers_count)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel title="Journal de la plateforme" sub="Dernières actions de l’équipe NENTIX" className="c5">
                    {logs.length === 0 ? (
                        <Empty title="Aucune action pour l’instant" />
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <tbody>
                                    {logs.map((log) => (
                                        <tr key={log.id}>
                                            <td>{formatDay(log.at, true)}</td>
                                            <td>
                                                <b>{log.action}</b>
                                                {log.organization && (
                                                    <>
                                                        {' · '}
                                                        <Link href={`/admin/organizations/${log.organization_id}`} className="mdash-link">
                                                            {log.organization}
                                                        </Link>
                                                    </>
                                                )}
                                                {log.description && <div className="mpage-muted">{log.description}</div>}
                                            </td>
                                            <td className="mpage-muted">{log.user ?? 'Système'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            </div>
        </Page>
    );
}

AdminDashboard.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AdminDashboard;

