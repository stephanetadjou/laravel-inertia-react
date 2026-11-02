import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, ReactNode, useState } from 'react';

import { formatNumber } from '../../components/dashboard/Charts';
import { stageInfo } from '../../components/dashboard/stages';

/*
 * Onglets des modules sur la fiche client (à la manière d'Odoo) : la vue
 * d'ensemble reste le premier onglet ; chaque module actif ajoute le sien
 * (personnes suivies, échéances, abonnements, présences). Un module
 * décoché ne reçoit rien du serveur, donc aucun onglet.
 */

interface BeneficiaryRow {
    id: number;
    name: string;
    age: number | null;
    relation: string | null;
    stage: string;
    churn_risk: string | null;
    total_spent: number;
    last_purchase_at: string | null;
}

export interface ModuleProps {
    customer: { id: number; name: string; phone_e164: string | null };
    beneficiaryModule?: {
        label: string;
        labels: string;
        relations: Record<string, string>;
        stores: { id: number; name: string }[];
        minor_needs_responsible: boolean;
    } | null;
    responsible?: { id: number; name: string; phone_e164: string | null; relation: string | null } | null;
    beneficiaries?: BeneficiaryRow[];
    healthNote?: { visible: boolean; text: string | null; has_note: boolean };
    canEdit: boolean;
    [key: string]: unknown;
}

export interface ModuleTab {
    key: string;
    label: string;
    render: () => ReactNode;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export function useModuleTabs(): ModuleTab[] {
    const props = usePage<ModuleProps>().props;
    const tabs: ModuleTab[] = [];

    if (props.beneficiaryModule) {
        const count = props.beneficiaries?.length ?? 0;
        tabs.push({
            key: 'beneficiaries',
            label: props.responsible && count === 0 ? 'Responsable' : `Personnes suivies${count ? ` (${count})` : ''}`,
            render: () => <BeneficiariesTab />,
        });
    }

    return tabs;
}

/** Barre d'onglets de la fiche : « Vue d'ensemble » puis un onglet par module actif. */
export function CustomerTabs({ tabs, current, onChange }: { tabs: ModuleTab[]; current: string; onChange: (key: string) => void }) {
    if (tabs.length === 0) return null;

    return (
        <nav className="mcust-tabs" role="tablist" aria-label="Sections de la fiche">
            {[{ key: 'overview', label: 'Vue d’ensemble' }, ...tabs].map((t) => (
                <button key={t.key} type="button" role="tab" aria-selected={current === t.key} className={current === t.key ? 'on' : ''} onClick={() => onChange(t.key)}>
                    {t.label}
                </button>
            ))}
        </nav>
    );
}

function BeneficiariesTab() {
    const { customer, beneficiaryModule, responsible, beneficiaries = [], healthNote, canEdit } = usePage<ModuleProps>().props;
    const [adding, setAdding] = useState(false);
    if (!beneficiaryModule) return null;

    return (
        <div className="mdash-grid">
            {responsible && (
                <section className="mdash-panel c6">
                    <div className="mdash-ph">
                        <h2>Responsable</h2>
                        <span className="mdash-sub">Les messages lui sont adressés</span>
                    </div>
                    <p className="text-sm">
                        <Link href={`/customers/${responsible.id}`} className="font-semibold text-brand-text hover:underline">
                            {responsible.name}
                        </Link>
                        {responsible.relation && <span className="text-gray-500"> · {responsible.relation}</span>}
                    </p>
                    <p className="mono mt-1 text-sm text-gray-500">{responsible.phone_e164 ?? 'Sans numéro'}</p>
                </section>
            )}

            {healthNote && (healthNote.visible || healthNote.has_note) && <HealthNotePanel customerId={customer.id} note={healthNote} />}

            {(!responsible || beneficiaries.length > 0) && (
                <section className="mdash-panel c12">
                    <div className="mdash-ph">
                        <div>
                            <h2>Personnes suivies</h2>
                            <span className="mdash-sub">
                                {beneficiaryModule.labels} suivis pour {customer.name} : chacun a sa fiche, les messages partent à {customer.name}.
                            </span>
                        </div>
                        {canEdit && (
                            <button type="button" className="mdash-btn brand sm" onClick={() => setAdding(!adding)}>
                                {adding ? 'Fermer' : `Ajouter un ${beneficiaryModule.label.toLowerCase()}`}
                            </button>
                        )}
                    </div>

                    {adding && <AddBeneficiaryForm customerId={customer.id} onDone={() => setAdding(false)} />}

                    {beneficiaries.length === 0 ? (
                        <p className="text-sm text-gray-500">Aucune personne suivie pour l’instant.</p>
                    ) : (
                        <div className="mdash-tbl">
                        <table>
                            <thead>
                                <tr>
                                    <th>Nom</th>
                                    <th>Âge</th>
                                    <th>Lien</th>
                                    <th>Étape</th>
                                    <th className="num">Achats</th>
                                </tr>
                            </thead>
                            <tbody>
                                {beneficiaries.map((b) => {
                                    const stage = stageInfo(b.stage);
                                    return (
                                        <tr key={b.id}>
                                            <td>
                                                <Link href={`/customers/${b.id}`} className="font-medium text-brand-text hover:underline">
                                                    {b.name}
                                                </Link>
                                            </td>
                                            <td>{b.age !== null ? `${b.age} ans` : '—'}</td>
                                            <td>{b.relation ?? '—'}</td>
                                            <td>
                                                <span className={`mdash-pill ${stage.pill}`}>{stage.label}</span>
                                                {b.churn_risk && <span className="mdash-pill bad ml-1">Départ possible</span>}
                                            </td>
                                            <td className="num">{formatNumber(b.total_spent)} F</td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        </div>
                    )}
                </section>
            )}
        </div>
    );
}

function AddBeneficiaryForm({ customerId, onDone }: { customerId: number; onDone: () => void }) {
    const { beneficiaryModule } = usePage<ModuleProps>().props;
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        birthdate: '',
        gender: '',
        relation: 'parent',
        phone: '',
        health_note: '',
        store_id: beneficiaryModule?.stores.length === 1 ? String(beneficiaryModule.stores[0].id) : '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(`/customers/${customerId}/beneficiaries`, { preserveScroll: true, onSuccess: onDone });
    };

    return (
        <form onSubmit={submit} className="mb-4 grid grid-cols-1 gap-3 rounded-lg border border-gray-200 p-3 text-sm sm:grid-cols-3 dark:border-gray-700">
            <label className="sm:col-span-2">
                <span className="mb-1 block font-medium">Nom et prénom</span>
                <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                {errors.name && <span className="text-xs text-error-500">{errors.name}</span>}
            </label>
            <label>
                <span className="mb-1 block font-medium">Date de naissance</span>
                <input type="date" className={field} value={data.birthdate} onChange={(e) => setData('birthdate', e.target.value)} />
                {errors.birthdate && <span className="text-xs text-error-500">{errors.birthdate}</span>}
            </label>
            <label>
                <span className="mb-1 block font-medium">Lien avec le responsable</span>
                <select className={field} value={data.relation} onChange={(e) => setData('relation', e.target.value)}>
                    {Object.entries(beneficiaryModule?.relations ?? {}).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
            <label>
                <span className="mb-1 block font-medium">Sexe</span>
                <select className={field} value={data.gender} onChange={(e) => setData('gender', e.target.value)}>
                    <option value="">—</option>
                    <option value="F">Fille / femme</option>
                    <option value="H">Garçon / homme</option>
                </select>
            </label>
            <label>
                <span className="mb-1 block font-medium">Téléphone (facultatif)</span>
                <input className={field} value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder="sans numéro : on écrit au responsable" />
                {errors.phone && <span className="text-xs text-error-500">{errors.phone}</span>}
            </label>
            {(beneficiaryModule?.stores.length ?? 0) > 1 && (
                <label>
                    <span className="mb-1 block font-medium">Établissement</span>
                    <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                        <option value="">Celui du responsable</option>
                        {beneficiaryModule?.stores.map((s) => (
                            <option key={s.id} value={s.id}>
                                {s.name}
                            </option>
                        ))}
                    </select>
                </label>
            )}
            <label className="sm:col-span-3">
                <span className="mb-1 block font-medium">Note de santé (accès restreint)</span>
                <textarea className={field} rows={2} value={data.health_note} onChange={(e) => setData('health_note', e.target.value)} placeholder="allergies, asthme, contre-indications…" />
            </label>
            <div className="flex justify-end sm:col-span-3">
                <button type="submit" className="mdash-btn brand" disabled={processing}>
                    {processing ? 'Enregistrement…' : 'Ajouter'}
                </button>
            </div>
        </form>
    );
}

function HealthNotePanel({ customerId, note }: { customerId: number; note: { visible: boolean; text: string | null; has_note: boolean } }) {
    const [editing, setEditing] = useState(false);
    const [text, setText] = useState(note.text ?? '');

    return (
        <section className="mdash-panel c6">
            <div className="mdash-ph">
                <div>
                    <h2>Note de santé</h2>
                    <span className="mdash-sub">Visible par la direction et le coach de la personne</span>
                </div>
                {note.visible && !editing && (
                    <button type="button" className="mdash-btn ghost sm" onClick={() => setEditing(true)}>
                        Modifier
                    </button>
                )}
            </div>
            {!note.visible ? (
                <p className="text-sm text-gray-500">Une note existe, réservée à la direction et au coach.</p>
            ) : editing ? (
                <div className="space-y-2">
                    <textarea className={field} rows={3} value={text} onChange={(e) => setText(e.target.value)} />
                    <div className="flex justify-end gap-2">
                        <button type="button" className="mdash-btn ghost sm" onClick={() => setEditing(false)}>
                            Annuler
                        </button>
                        <button
                            type="button"
                            className="mdash-btn brand sm"
                            onClick={() => router.put(`/customers/${customerId}/health-note`, { health_note: text }, { preserveScroll: true, onSuccess: () => setEditing(false) })}
                        >
                            Enregistrer
                        </button>
                    </div>
                </div>
            ) : (
                <p className="text-sm whitespace-pre-line">{note.text || 'Aucune note.'}</p>
            )}
        </section>
    );
}
