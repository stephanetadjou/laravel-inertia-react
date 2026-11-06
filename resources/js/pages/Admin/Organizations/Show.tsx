import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../../layout/AppLayout';
import { Empty, formatDay, Page, Panel, StatStrip } from '../../../components/page/Page';
import { AdminOrganization, fieldClasses, Option, PlatformLogRow, StatusPill } from '../shared';

/*
 * Back-office plateforme › Fiche entreprise : chiffres, utilisateurs,
 * essai, offre, activation, suspension et journal des actions NENTIX.
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
    stores: ManagedStore[];
    platform_whatsapp_ready: boolean;
    logs: PlatformLogRow[];
    options: { plans: Option[] };
    errors: Record<string, string>;
    [key: string]: unknown;
}

interface ManagedStore {
    id: number;
    name: string;
    whatsapp_managed: boolean;
    business_account_id: string | null;
    phone_number_id: string | null;
    verified_name: string | null;
    display_phone_number: string | null;
    checked_at: string | null;
}

/* WhatsApp géré par NENTIX : le client a partagé son compte WhatsApp avec le portefeuille NENTIX. */
function ManagedWhatsAppRow({ organizationId, store }: { organizationId: number; store: ManagedStore }) {
    const form = useForm({
        managed: true,
        business_account_id: store.business_account_id ?? '',
        phone_number_id: store.phone_number_id ?? '',
    });
    const url = `/admin/organizations/${organizationId}/stores/${store.id}/whatsapp`;

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, managed: true }));
        form.put(url, { preserveScroll: true });
    };

    return (
        <form onSubmit={save} className="space-y-2 border-b border-gray-100 p-5 last:border-0 dark:border-gray-800">
            <div className="flex items-center justify-between gap-2">
                <b>{store.name}</b>
                {store.whatsapp_managed ? <span className="mdash-pill ok">Géré par NENTIX</span> : <span className="mdash-pill">Géré par l’entreprise</span>}
            </div>
            <div className="grid gap-2 sm:grid-cols-2">
                <input
                    value={form.data.business_account_id}
                    onChange={(e) => form.setData('business_account_id', e.target.value)}
                    className={fieldClasses()}
                    placeholder="WABA ID"
                    aria-label={`WABA ID de ${store.name}`}
                    inputMode="numeric"
                />
                <input
                    value={form.data.phone_number_id}
                    onChange={(e) => form.setData('phone_number_id', e.target.value)}
                    className={fieldClasses()}
                    placeholder="Phone Number ID"
                    aria-label={`Phone Number ID de ${store.name}`}
                    inputMode="numeric"
                />
            </div>
            {(form.errors.business_account_id || form.errors.phone_number_id) && (
                <p className="text-error-500">{form.errors.business_account_id ?? form.errors.phone_number_id}</p>
            )}
            {store.checked_at && (
                <p className="mpage-muted">
                    Dernier test : {store.verified_name ?? '—'} ({store.display_phone_number ?? '?'}), {formatDay(store.checked_at, true)}
                </p>
            )}
            <div className="flex gap-2">
                <button type="submit" className="mdash-btn brand" disabled={form.processing}>
                    {store.whatsapp_managed ? 'Mettre à jour' : 'Gérer par NENTIX'}
                </button>
                {store.whatsapp_managed && (
                    <button type="button" className="mdash-btn ghost" onClick={() => router.put(url, { managed: false }, { preserveScroll: true })}>
                        Rendre la main à l’entreprise
                    </button>
                )}
            </div>
        </form>
    );
}

function OrganizationShow() {
    const { organization: o, users, stores, platform_whatsapp_ready, logs, options, errors } = usePage<PageProps>().props;
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

                <Panel title="WhatsApp géré par NENTIX" sub="Compte WhatsApp du client partagé avec le portefeuille NENTIX" className="c12">
                    {!platform_whatsapp_ready && (
                        <p className="px-5 pt-4 text-sm text-warning-600">MENENTIA_WHATSAPP_TOKEN n’est pas renseigné sur le serveur : les numéros gérés ne pourront pas envoyer.</p>
                    )}
                    {stores.length === 0 ? <Empty title="Aucun point de vente" /> : stores.map((s) => <ManagedWhatsAppRow key={s.id} organizationId={o.id} store={s} />)}
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

                <Panel title="Journal" sub="Actions de l’équipe NENTIX sur cette entreprise" className="c5">
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
