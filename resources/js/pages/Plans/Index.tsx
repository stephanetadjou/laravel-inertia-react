import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Pager, Paginated, Panel, PlusIcon, Seg, formatDay, useQueryFilters } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';
import { Modal } from '../../components/ui/modal';
import SubscriptionModal, { PlanOption, SubscriptionFormOptions } from './SubscriptionModal';
import { PlanningModal, StaffOption } from './PlanningFields';

/*
 * Ventes › Formules : les formules de l'entreprise (mensualité, saison,
 * carte de séances…) et les abonnements en cours ou qui se terminent bientôt.
 */

export interface SubscriptionRow {
    id: number;
    plan: string | null;
    customer: { id: number; name: string | null };
    beneficiary: { id: number; name: string } | null;
    starts_on: string;
    ends_on: string | null;
    status: 'active' | 'ended' | 'cancelled';
    status_label: string;
    sessions_left: number | null;
    store_id?: number;
    planning: { weekday: number; time: string }[];
    planning_label: string | null;
    session_minutes: number | null;
    coached: boolean;
    staff_member_id: number | null;
    staff_name: string | null;
    options: string[];
    paid: number;
    total: number;
    overdue: number;
}

interface PageProps extends SubscriptionFormOptions {
    plans: (PlanOption & { active_count: number })[];
    subscriptions: Paginated<SubscriptionRow>;
    filter: 'current' | 'ending' | 'all';
    counts: { current: number; ending: number };
    catalog: { id: number; name: string }[];
    periods: Record<string, string>;
    canManage: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export const SUB_PILL: Record<SubscriptionRow['status'], string> = { active: 'ok', ended: 'idle', cancelled: 'bad' };

type Line = { label: string; amount: string; recurring?: boolean };

function PlanModal({ plan, onClose }: { plan: PlanOption | null; onClose: () => void }) {
    const { catalog, periods } = usePage<PageProps>().props;
    const { data, setData, post, put, processing, errors } = useForm({
        name: plan?.name ?? '',
        catalog_item_id: plan?.catalog_item_id ? String(plan.catalog_item_id) : '',
        price: plan ? String(plan.price) : '',
        period: plan?.period ?? 'month',
        sessions_count: plan?.sessions_count ? String(plan.sessions_count) : '',
        fees: (plan?.fees ?? []).map((f) => ({ label: f.label, amount: String(f.amount) })) as Line[],
        options: (plan?.options ?? []).map((o) => ({ label: o.label, amount: String(o.amount), recurring: o.recurring })) as Line[],
        description: plan?.description ?? '',
        active: plan?.active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (plan) put(`/plans/${plan.id}`, opts);
        else post('/plans', opts);
    };

    const lines = (key: 'fees' | 'options', title: string, hint: string, example: string) => (
        <fieldset className="space-y-2">
            <legend className="font-medium">{title}</legend>
            <span className="block text-xs text-gray-500">{hint}</span>
            {data[key].map((line, i) => (
                <div key={i} className="flex items-center gap-2">
                    <input className={field} placeholder={example} value={line.label} onChange={(e) => setData(key, data[key].map((l, j) => (j === i ? { ...l, label: e.target.value } : l)))} />
                    <input type="number" min={0} className={`${field} w-32`} placeholder="FCFA" value={line.amount} onChange={(e) => setData(key, data[key].map((l, j) => (j === i ? { ...l, amount: e.target.value } : l)))} />
                    {key === 'options' && (
                        <label className="flex shrink-0 items-center gap-1 text-xs">
                            <input type="checkbox" checked={!!line.recurring} onChange={(e) => setData(key, data[key].map((l, j) => (j === i ? { ...l, recurring: e.target.checked } : l)))} />
                            chaque mois
                        </label>
                    )}
                    <button type="button" className="mdash-btn ghost sm" aria-label="Retirer" onClick={() => setData(key, data[key].filter((_, j) => j !== i))}>
                        ×
                    </button>
                </div>
            ))}
            <button type="button" className="text-xs text-brand-text hover:underline" onClick={() => setData(key, [...data[key], { label: '', amount: '', recurring: false }])}>
                + Ajouter
            </button>
        </fieldset>
    );

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">{plan ? 'Modifier la formule' : 'Nouvelle formule'}</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label className="sm:col-span-2">
                        <span className="mb-1 block font-medium">Nom</span>
                        <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="ex. Judo enfants — mensuel" required />
                        {errors.name && <span className="text-xs text-error-500">{errors.name}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Période</span>
                        <select className={field} value={data.period} onChange={(e) => setData('period', e.target.value as PlanOption['period'])}>
                            {Object.entries(periods).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Prix (FCFA){data.period === 'month' ? ' par mois' : data.period === 'season' ? ' de la saison' : ''}</span>
                        <input type="number" min={0} className={field} value={data.price} onChange={(e) => setData('price', e.target.value)} required />
                        {errors.price && <span className="text-xs text-error-500">{errors.price}</span>}
                    </label>
                    {data.period === 'sessions' && (
                        <label>
                            <span className="mb-1 block font-medium">Nombre de séances</span>
                            <input type="number" min={1} className={field} value={data.sessions_count} onChange={(e) => setData('sessions_count', e.target.value)} required />
                        </label>
                    )}
                    <label>
                        <span className="mb-1 block font-medium">Activité (facultatif)</span>
                        <select className={field} value={data.catalog_item_id} onChange={(e) => setData('catalog_item_id', e.target.value)}>
                            <option value="">—</option>
                            {catalog.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <span className="mt-1 block text-xs text-gray-500">range les paiements dans la bonne catégorie</span>
                    </label>
                </div>
                {lines('fees', 'Frais uniques', 'payés une fois, au début de l’abonnement', 'ex. Inscription, Assurance')}
                {lines('options', 'Options', 'au choix de chaque client ; « chaque mois » s’ajoute à chaque mensualité', 'ex. Transport, Kimono')}
                {plan && (
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                        Formule proposée (décochez pour l’archiver)
                    </label>
                )}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        {processing ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

export function SubscriptionsTable({ rows, showMember = true, staff, hasAgenda = false }: { rows: SubscriptionRow[]; showMember?: boolean; staff?: StaffOption[]; hasAgenda?: boolean }) {
    const [planning, setPlanning] = useState<SubscriptionRow | null>(null);
    return (
        <div className="mdash-tbl">
            <table className="mpage-table">
                <thead>
                    <tr>
                        {showMember && <th>Abonné</th>}
                        <th>Formule</th>
                        <th>Période</th>
                        <th className="num">Payé</th>
                        <th>Statut</th>
                        <th />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((s) => (
                        <tr key={s.id}>
                            {showMember && (
                                <td>
                                    <Link href={`/customers/${s.beneficiary?.id ?? s.customer.id}`} className="font-medium text-brand-text hover:underline">
                                        {s.beneficiary?.name ?? s.customer.name}
                                    </Link>
                                    {s.beneficiary && <span className="block text-xs text-gray-500">payé par {s.customer.name}</span>}
                                </td>
                            )}
                            <td>
                                {s.plan}
                                {s.options.length > 0 && <span className="block text-xs text-gray-500">+ {s.options.join(', ')}</span>}
                            </td>
                            <td>
                                {formatDay(s.starts_on)} → {s.ends_on ? formatDay(s.ends_on) : 'sans fin'}
                                {s.sessions_left !== null && <span className="block text-xs text-gray-500">{s.sessions_left} séance(s) restante(s)</span>}
                                {s.planning_label && (
                                    <span className="block text-xs text-gray-500">
                                        {s.planning_label} · {s.coached ? `suivi par ${s.staff_name ?? 'un coach'}` : 'en autonomie'}
                                    </span>
                                )}
                            </td>
                            <td className="num">
                                {formatNumber(s.paid)} / {formatNumber(s.total)} F
                                {s.overdue > 0 && <span className="block text-xs text-error-500">{s.overdue} tranche(s) en retard</span>}
                            </td>
                            <td>
                                <span className={`mdash-pill ${SUB_PILL[s.status]}`}>{s.status_label}</span>
                            </td>
                            <td>
                                {s.status === 'active' && hasAgenda && (
                                    <button type="button" className="mdash-btn ghost sm mr-1" onClick={() => setPlanning(s)}>
                                        Planning
                                    </button>
                                )}
                                {s.status === 'active' && (
                                    <button
                                        type="button"
                                        className="mdash-btn ghost sm"
                                        onClick={() => confirm('Arrêter cet abonnement ? Les tranches non payées ne seront plus réclamées.') && router.post(`/subscriptions/${s.id}/cancel`, {}, { preserveScroll: true })}
                                    >
                                        Arrêter
                                    </button>
                                )}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
            {planning && <PlanningModal subscription={planning} staff={staff ?? []} onClose={() => setPlanning(null)} />}
        </div>
    );
}

function PlansIndex() {
    const props = usePage<PageProps>().props;
    const { plans, subscriptions, filter, counts, canManage } = props;
    const [editing, setEditing] = useState<PlanOption | null | undefined>(undefined);
    const [subscribing, setSubscribing] = useState(false);
    const visit = useQueryFilters('/plans', { show: filter });

    return (
        <Page
            title="Formules et abonnements"
            subtitle="Vos formules, et qui est abonné à quoi"
            actions={
                <>
                    {canManage && (
                        <button type="button" className="mdash-btn ghost" onClick={() => setEditing(null)}>
                            <PlusIcon />
                            Nouvelle formule
                        </button>
                    )}
                    <button type="button" className="mdash-btn brand" onClick={() => setSubscribing(true)}>
                        <PlusIcon />
                        Nouvel abonnement
                    </button>
                </>
            }
        >
            <Panel title="Formules" sub="Prix, période, frais d’inscription et options">
                {plans.length === 0 ? (
                    <Empty title="Aucune formule pour l’instant">
                        Une formule, c’est ce que vous vendez : « Natation enfants, 15 000 F par mois, inscription 10 000 F ». {canManage ? 'Créez la première avec « Nouvelle formule ».' : ''}
                    </Empty>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {plans.map((p) => (
                            <div key={p.id} className={`rounded-xl border p-3 text-sm ${p.active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 opacity-60'}`}>
                                <div className="flex items-start justify-between gap-2">
                                    <b>{p.name}</b>
                                    {canManage && (
                                        <button type="button" className="text-xs text-brand-text hover:underline" onClick={() => setEditing(p)}>
                                            Modifier
                                        </button>
                                    )}
                                </div>
                                <div className="mt-1 text-base font-semibold">{p.price_label}</div>
                                {p.fees.length > 0 && <div className="text-xs text-gray-500">+ {p.fees.map((f) => `${f.label} ${formatNumber(f.amount)} F`).join(', ')}</div>}
                                {p.options.length > 0 && <div className="text-xs text-gray-500">Options : {p.options.map((o) => `${o.label} ${formatNumber(o.amount)} F`).join(', ')}</div>}
                                <div className="mt-2 text-xs text-gray-500">{p.active ? `${p.active_count} abonnement(s) en cours` : 'Archivée'}</div>
                            </div>
                        ))}
                    </div>
                )}
            </Panel>

            <Panel
                title="Abonnements"
                actions={
                    <Seg
                        label="Afficher"
                        value={filter}
                        options={[
                            { key: 'current', label: `En cours (${counts.current})` },
                            { key: 'ending', label: `Finissent dans 30 j (${counts.ending})` },
                            { key: 'all', label: 'Tous' },
                        ]}
                        onChange={(show) => visit({ show })}
                    />
                }
            >
                {subscriptions.data.length === 0 ? <Empty title="Aucun abonnement">Abonnez un client avec « Nouvel abonnement » : son échéancier est préparé tout seul.</Empty> : <SubscriptionsTable rows={subscriptions.data} staff={props.staff} hasAgenda={props.hasAgenda} />}
                <Pager page={subscriptions} onPage={(page) => visit({ page })} unit="abonnements" />
            </Panel>

            {editing !== undefined && <PlanModal plan={editing} onClose={() => setEditing(undefined)} />}
            {subscribing && <SubscriptionModal options={props} onClose={() => setSubscribing(false)} />}
        </Page>
    );
}

PlansIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default PlansIndex;
