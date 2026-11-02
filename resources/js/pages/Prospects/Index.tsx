import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, Seg, SelectFilter, useQueryFilters } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';

/*
 * Clients › Prospects : chaque prospect avance d'étape jusqu'au premier
 * achat (le cycle de vie client prend ensuite le relais). Tableau par étape
 * (Kanban) ou liste ; en rouge, ceux dont la relance est en retard.
 */

export interface Stage {
    key: string;
    label: string;
    kind: 'a_contacter' | 'en_discussion' | 'en_essai' | 'gagne' | 'perdu';
    delay_days: number;
}

export interface ProspectCard {
    id: number;
    name: string;
    company: string | null;
    phone: string | null;
    stage: string;
    since: string | null;
    days: number;
    late: boolean;
    lost_reason: string | null;
    owner: string | null;
    owner_id: number | null;
    whatsapp_url: string | null;
}

interface PageProps {
    stages: Stage[];
    prospects: ProspectCard[];
    filters: { owner: number | null; q: string; late: boolean; view: 'kanban' | 'list' };
    owners: { id: number; name: string }[];
    stores: { id: number; name: string }[];
    canAssign: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

/** Choix de l'étape ; « perdu » demande la raison. */
export function StageSelect({ prospect, stages }: { prospect: { id: number; stage: string }; stages: Stage[] }) {
    return (
        <select
            aria-label="Changer d’étape"
            className="w-full rounded-md border border-gray-200 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900"
            value={prospect.stage}
            onClick={(e) => e.stopPropagation()}
            onChange={(e) => {
                const stage = stages.find((s) => s.key === e.target.value);
                const reason = stage?.kind === 'perdu' ? window.prompt('Pourquoi ce prospect est-il perdu ? (prix, concurrent, pas le bon moment…)') : null;
                if (stage?.kind === 'perdu' && !reason) return;
                router.post(`/prospects/${prospect.id}/stage`, { stage: e.target.value, lost_reason: reason }, { preserveScroll: true });
            }}
        >
            {stages.map((s) => (
                <option key={s.key} value={s.key}>
                    {s.label}
                </option>
            ))}
        </select>
    );
}

function NewProspectModal({ onClose }: { onClose: () => void }) {
    const { stages, owners, stores, canAssign } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        company_name: '',
        phone: '',
        store_id: stores[0] ? String(stores[0].id) : '',
        owner_user_id: '',
        referral_code: '',
        stage: stages[0]?.key ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/prospects', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-lg p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Nouveau prospect</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label>
                        <span className="mb-1 block font-medium">Nom du contact</span>
                        <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        {errors.name && <span className="text-xs text-error-500">{errors.name}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Entreprise (facultatif)</span>
                        <input className={field} value={data.company_name} onChange={(e) => setData('company_name', e.target.value)} />
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Téléphone WhatsApp</span>
                        <input className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} required />
                        {errors.phone && <span className="text-xs text-error-500">{errors.phone}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Étape</span>
                        <select className={field} value={data.stage} onChange={(e) => setData('stage', e.target.value)}>
                            {stages
                                .filter((s) => s.kind !== 'gagne' && s.kind !== 'perdu')
                                .map((s) => (
                                    <option key={s.key} value={s.key}>
                                        {s.label}
                                    </option>
                                ))}
                        </select>
                    </label>
                    {canAssign && (
                        <label>
                            <span className="mb-1 block font-medium">Commercial</span>
                            <select className={field} value={data.owner_user_id} onChange={(e) => setData('owner_user_id', e.target.value)}>
                                <option value="">Moi</option>
                                {owners.map((o) => (
                                    <option key={o.id} value={o.id}>
                                        {o.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {canAssign && (
                        <label>
                            <span className="mb-1 block font-medium">ou code commercial</span>
                            <input className={field} value={data.referral_code} onChange={(e) => setData('referral_code', e.target.value)} placeholder="ex. K7P2QX" />
                            {errors.referral_code && <span className="text-xs text-error-500">{errors.referral_code}</span>}
                        </label>
                    )}
                    {stores.length > 1 && (
                        <label>
                            <span className="mb-1 block font-medium">Établissement</span>
                            <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                </div>
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        {processing ? 'Ajout…' : 'Ajouter'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function Card({ p, stages }: { p: ProspectCard; stages: Stage[] }) {
    return (
        <div className={`rounded-lg border bg-white p-2.5 text-sm shadow-sm dark:bg-gray-900 ${p.late ? 'border-error-300' : 'border-gray-200 dark:border-gray-700'}`}>
            <Link href={`/customers/${p.id}`} className="block font-semibold text-brand-text hover:underline">
                {p.company ?? p.name}
            </Link>
            {p.company && <span className="block text-xs text-gray-500">{p.name}</span>}
            <span className={`mt-1 block text-xs ${p.late ? 'font-medium text-error-500' : 'text-gray-500'}`}>
                {p.days} j dans l’étape{p.late ? ' · à relancer' : ''}
                {p.owner ? ` · ${p.owner}` : ''}
            </span>
            {p.lost_reason && <span className="block text-xs text-gray-500">Raison : {p.lost_reason}</span>}
            <div className="mt-2 flex items-center gap-1.5">
                <div className="grow">
                    <StageSelect prospect={p} stages={stages} />
                </div>
                {p.whatsapp_url && (
                    <a href={p.whatsapp_url} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost sm" title="Écrire sur WhatsApp" aria-label="Écrire sur WhatsApp">
                        <WhatsAppIcon />
                    </a>
                )}
            </div>
        </div>
    );
}

function ProspectsIndex() {
    const { stages, prospects, filters, owners } = usePage<PageProps>().props;
    const [creating, setCreating] = useState(false);
    const visit = useQueryFilters('/prospects', { ...filters, view: filters.view === 'kanban' ? null : filters.view, late: filters.late ? 1 : null });
    const late = prospects.filter((p) => p.late).length;

    return (
        <Page
            title="Prospects"
            subtitle={`${prospects.length} fiche(s) suivie(s) · ${late} relance(s) en retard`}
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setCreating(true)}>
                    <PlusIcon />
                    Nouveau prospect
                </button>
            }
            filters={
                <>
                    <Seg
                        label="Affichage"
                        value={filters.view}
                        options={[
                            { key: 'kanban', label: 'Tableau' },
                            { key: 'list', label: 'Liste' },
                        ]}
                        onChange={(view) => visit({ view: view === 'kanban' ? null : view })}
                    />
                    {owners.length > 0 && <SelectFilter label="Commercial" value={filters.owner} options={owners.map((o) => ({ value: o.id, label: o.name }))} allLabel="Toute l’équipe" onChange={(owner) => visit({ owner })} />}
                    <Seg
                        label="Relances"
                        value={filters.late ? 'late' : 'all'}
                        options={[
                            { key: 'all', label: 'Toutes' },
                            { key: 'late', label: `En retard (${late})` },
                        ]}
                        onChange={(v) => visit({ late: v === 'late' ? 1 : null })}
                    />
                    <SearchFilter value={filters.q} placeholder="Nom, entreprise ou numéro" onSearch={(q) => visit({ q })} />
                </>
            }
        >
            {prospects.length === 0 && !filters.q && !filters.late ? (
                <Panel>
                    <Empty title="Aucun prospect pour l’instant">
                        Ajoutez les entreprises ou personnes que vous démarchez avec « Nouveau prospect » : chaque étape a un délai de relance, et les retards arrivent dans « À faire ».
                    </Empty>
                </Panel>
            ) : filters.view === 'kanban' ? (
                <div className="flex gap-3 overflow-x-auto pb-4">
                    {stages.map((s) => {
                        const cards = prospects.filter((p) => p.stage === s.key);
                        return (
                            <section key={s.key} className="w-64 shrink-0 rounded-xl bg-gray-100/70 p-2 dark:bg-white/5" aria-label={s.label}>
                                <h2 className="mb-2 flex items-center justify-between px-1 text-sm font-semibold">
                                    {s.label}
                                    <span className="text-xs font-normal text-gray-500">
                                        {cards.length}
                                        {s.delay_days > 0 ? ` · relance ${s.delay_days} j` : ''}
                                    </span>
                                </h2>
                                <div className="space-y-2">
                                    {cards.map((p) => (
                                        <Card key={p.id} p={p} stages={stages} />
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                </div>
            ) : (
                <Panel>
                    <div className="mdash-tbl">
                        <table className="mpage-table">
                            <thead>
                                <tr>
                                    <th>Prospect</th>
                                    <th>Étape</th>
                                    <th className="num">Depuis</th>
                                    <th>Commercial</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {prospects.map((p) => (
                                    <tr key={p.id}>
                                        <td>
                                            <Link href={`/customers/${p.id}`} className="font-medium text-brand-text hover:underline">
                                                {p.company ?? p.name}
                                            </Link>
                                            {p.company && <span className="block text-xs text-gray-500">{p.name}</span>}
                                        </td>
                                        <td className="w-48">
                                            <StageSelect prospect={p} stages={stages} />
                                        </td>
                                        <td className={`num ${p.late ? 'font-medium text-error-500' : ''}`}>{p.days} j</td>
                                        <td>{p.owner ?? '—'}</td>
                                        <td>
                                            {p.whatsapp_url && (
                                                <a href={p.whatsapp_url} target="_blank" rel="noopener noreferrer" className="mdash-btn ghost sm">
                                                    <WhatsAppIcon />
                                                    WhatsApp
                                                </a>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}

            {creating && <NewProspectModal onClose={() => setCreating(false)} />}
        </Page>
    );
}

ProspectsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ProspectsIndex;
