import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../../layout/AppLayout';
import { Empty, formatDay, Page, Panel, StatStrip } from '../../../components/page/Page';
import { AdminOrganization, fieldClasses, Option, PlatformLogRow, StatusPill } from '../shared';

/*
 * Back-office plateforme › Fiche entreprise : chiffres, utilisateurs,
 * essai, offre, activation, suspension et journal des actions Menentia.
 */

interface PageProps {
    organization: AdminOrganization & {
        slug: string;
        suspended_at: string | null;
        suspension_reason: string | null;
        whatsapp_api_enabled: boolean;
        brands_count: number;
        stores_count: number;
        purchases_count: number;
        appointments_count: number;
    };
    users: { id: number; name: string; email: string; role: string | null; created_at: string | null }[];
    logs: PlatformLogRow[];
    options: { plans: Option[] };
    errors: Record<string, string>;
    [key: string]: unknown;
}

function OrganizationShow() {
    const { organization: o, users, logs, options, errors } = usePage<PageProps>().props;
    const base = `/admin/organizations/${o.id}`;
    const [days, setDays] = useState(14);
    const suspendForm = useForm({ reason: '' });

    const suspend: FormEventHandler = (e) => {
        e.preventDefault();
        suspendForm.post(`${base}/suspend`, { preserveScroll: true, onSuccess: () => suspendForm.reset() });
    };

    return (
        <Page
            title={o.name}
            subtitle={
                <>
                    {o.offer} · {o.sector_label} · inscrite le {formatDay(o.created_at)} · <StatusPill organization={o} />
                </>
            }
            actions={
                <Link href="/admin/organizations" className="mdash-btn ghost">
                    ← Entreprises
                </Link>
            }
        >
            <StatStrip
                items={[
                    { label: 'Utilisateurs', value: o.users_count },
                    { label: 'Clients', value: o.customers_count },
                    { label: 'Marques / points de vente', value: `${o.brands_count} / ${o.stores_count}` },
                    { label: 'Ventes', value: o.purchases_count },
                    { label: 'Rendez-vous', value: o.appointments_count },
                    { label: 'Dernière activité', value: o.last_activity ? formatDay(o.last_activity, true) : 'Jamais' },
                ]}
            />

            <div className="mdash-grid">
                <Panel title="Offre et essai" className="c7">
                    <div className="space-y-5 p-5 text-sm">
                        <label className="block">
                            <span className="mb-1 block font-medium">Plan</span>
                            <select
                                className={fieldClasses()}
                                value={o.plan}
                                onChange={(e) => router.put(`${base}/plan`, { plan: e.target.value }, { preserveScroll: true })}
                            >
                                {options.plans.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </label>

                        {o.status !== 'active' && (
                            <div>
                                <span className="mb-1 block font-medium">
                                    Fin d’essai : {formatDay(o.trial_ends_at)} {o.trial_expired && <span className="mdash-pill warn">terminé</span>}
                                </span>
                                <div className="flex items-center gap-2">
                                    <input type="number" min={1} max={90} value={days} onChange={(e) => setDays(Number(e.target.value))} className={`${fieldClasses()} max-w-24`} aria-label="Nombre de jours" />
                                    <button type="button" className="mdash-btn ghost" onClick={() => router.post(`${base}/trial`, { days }, { preserveScroll: true })}>
                                        Prolonger l’essai
                                    </button>
                                </div>
                                {errors.days && <p className="mt-1 text-error-500">{errors.days}</p>}
                            </div>
                        )}

                        {o.status === 'trial' && (
                            <button type="button" className="mdash-btn brand" onClick={() => router.post(`${base}/activate`, {}, { preserveScroll: true })}>
                                Activer l’abonnement
                            </button>
                        )}
                    </div>
                </Panel>

                <Panel title={o.status === 'suspended' ? 'Entreprise suspendue' : 'Suspendre l’entreprise'} className="c5">
                    <div className="p-5 text-sm">
                        {o.status === 'suspended' ? (
                            <>
                                <p className="mb-3">
                                    Depuis le {formatDay(o.suspended_at, true)} : <b>{o.suspension_reason}</b>. Ses utilisateurs voient la page « compte suspendu ».
                                </p>
                                <button type="button" className="mdash-btn brand" onClick={() => router.post(`${base}/reactivate`, {}, { preserveScroll: true })}>
                                    Réactiver
                                </button>
                            </>
                        ) : (
                            <form onSubmit={suspend} className="space-y-3">
                                <p className="mpage-muted">Ses utilisateurs ne pourront plus ouvrir le CRM. Les données sont conservées.</p>
                                <input
                                    value={suspendForm.data.reason}
                                    onChange={(e) => suspendForm.setData('reason', e.target.value)}
                                    className={fieldClasses()}
                                    placeholder="Motif (ex. abonnement impayé)"
                                    aria-label="Motif de la suspension"
                                />
                                {suspendForm.errors.reason && <p className="text-error-500">{suspendForm.errors.reason}</p>}
                                <button type="submit" className="mdash-btn ghost" disabled={suspendForm.processing}>
                                    Suspendre
                                </button>
                            </form>
                        )}
                    </div>
                </Panel>

                <Panel title="Utilisateurs" className="c7">
                    {users.length === 0 ? (
                        <Empty title="Aucun utilisateur" />
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nom</th>
                                        <th>Email</th>
                                        <th>Rôle</th>
                                        <th>Créé le</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {users.map((u) => (
                                        <tr key={u.id}>
                                            <td className="cell-main">{u.name}</td>
                                            <td>{u.email}</td>
                                            <td>{u.role ?? '—'}</td>
                                            <td>{formatDay(u.created_at)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel title="Journal" sub="Actions de l’équipe Menentia sur cette entreprise" className="c5">
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

OrganizationShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OrganizationShow;
