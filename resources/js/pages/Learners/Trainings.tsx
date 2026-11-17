import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import { Modal } from '../../components/ui/modal';
import { field } from './shared';

/*
 * Apprenants › Formations : ce que le centre enseigne (heures de leçons
 * individuelles, séances collectives, durée conseillée, examens à réussir,
 * formule payée en plusieurs fois, cours collectifs comptés).
 */

interface TrainingRow {
    id: number;
    name: string;
    description: string | null;
    hours_planned: number;
    sessions_planned: number;
    duration_weeks: number | null;
    exams: string[];
    plan_id: number | null;
    plan: string | null;
    class_ids: number[];
    classes: string[];
    active: boolean;
    active_count: number;
    completed_count: number;
}

interface PageProps {
    trainings: TrainingRow[];
    plans: { id: number; name: string }[];
    classes: { id: number; name: string; training_id: number | null }[];
    [key: string]: unknown;
}

function TrainingModal({ training, onClose }: { training: TrainingRow | null; onClose: () => void }) {
    const { plans, classes } = usePage<PageProps>().props;
    const { data, setData, post, put, transform, processing, errors } = useForm({
        name: training?.name ?? '',
        description: training?.description ?? '',
        hours_planned: String(training?.hours_planned ?? 20),
        sessions_planned: String(training?.sessions_planned ?? 0),
        duration_weeks: training?.duration_weeks ? String(training.duration_weeks) : '',
        exams: (training?.exams ?? []).join(', '),
        plan_id: training?.plan_id ? String(training.plan_id) : '',
        class_ids: training?.class_ids ?? ([] as number[]),
        active: training?.active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onClose() };
        transform((d) => ({
            ...d,
            exams: d.exams
                .split(',')
                .map((s) => s.trim())
                .filter(Boolean),
        }));
        if (training) put(`/trainings/${training.id}`, options);
        else post('/trainings', options);
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">{training ? 'Modifier la formation' : 'Nouvelle formation'}</h2>
                <label className="block">
                    <span className="mb-1 block font-medium">Nom</span>
                    <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="ex. Permis B, Anglais niveau 1" />
                    {errors.name && <span className="text-xs text-error-500">{errors.name}</span>}
                </label>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label>
                        <span className="mb-1 block font-medium">Heures de leçons</span>
                        <input type="number" min={0} className={field} value={data.hours_planned} onChange={(e) => setData('hours_planned', e.target.value)} />
                        <span className="mt-1 block text-xs text-gray-500">Leçons individuelles</span>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Séances collectives</span>
                        <input type="number" min={0} className={field} value={data.sessions_planned} onChange={(e) => setData('sessions_planned', e.target.value)} />
                        <span className="mt-1 block text-xs text-gray-500">Cours en groupe</span>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Durée (semaines)</span>
                        <input type="number" min={1} className={field} value={data.duration_weeks} onChange={(e) => setData('duration_weeks', e.target.value)} />
                        <span className="mt-1 block text-xs text-gray-500">Sert à repérer le retard</span>
                    </label>
                </div>
                <label className="block">
                    <span className="mb-1 block font-medium">Examens, dans l’ordre</span>
                    <input className={field} value={data.exams} onChange={(e) => setData('exams', e.target.value)} placeholder="ex. Code, Conduite" />
                    <span className="mt-1 block text-xs text-gray-500">Séparés par des virgules. Tous réussis = parcours terminé.</span>
                </label>
                {plans.length > 0 && (
                    <label className="block">
                        <span className="mb-1 block font-medium">Formule de paiement</span>
                        <select className={field} value={data.plan_id} onChange={(e) => setData('plan_id', e.target.value)}>
                            <option value="">Aucune</option>
                            {plans.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </select>
                        <span className="mt-1 block text-xs text-gray-500">Proposée à l’inscription, avec paiement en plusieurs fois.</span>
                    </label>
                )}
                {classes.length > 0 && (
                    <fieldset>
                        <legend className="mb-1 block font-medium">Cours collectifs comptés</legend>
                        <div className="flex flex-wrap gap-3">
                            {classes.map((c) => (
                                <label key={c.id} className="flex items-center gap-1.5">
                                    <input
                                        type="checkbox"
                                        checked={data.class_ids.includes(c.id)}
                                        onChange={(e) => setData('class_ids', e.target.checked ? [...data.class_ids, c.id] : data.class_ids.filter((id) => id !== c.id))}
                                    />
                                    {c.name}
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}
                <label className="block">
                    <span className="mb-1 block font-medium">Description</span>
                    <textarea className={field} rows={2} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                </label>
                {training && (
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                        Proposée aux nouvelles inscriptions
                    </label>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing || !data.name}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function TrainingsIndex() {
    const { trainings } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<TrainingRow | null | undefined>(undefined);

    return (
        <Page
            title="Formations"
            subtitle="Heures prévues, examens et formule de chaque formation"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setEditing(null)}>
                    <PlusIcon /> Nouvelle formation
                </button>
            }
        >
            <Panel>
                {trainings.length === 0 ? (
                    <Empty title="Aucune formation">Créez votre première formation (ex. Permis B : 20 h de conduite, 30 séances de code, examens Code puis Conduite).</Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Formation</th>
                                    <th>Contenu</th>
                                    <th>Examens</th>
                                    <th>Formule</th>
                                    <th className="r">En cours</th>
                                    <th className="r">Terminés</th>
                                    <th className="r" />
                                </tr>
                            </thead>
                            <tbody>
                                {trainings.map((t) => (
                                    <tr key={t.id} className={t.active ? '' : 'opacity-50'}>
                                        <td className="cell-main">
                                            <b>{t.name}</b>
                                            {t.description && <span className="block text-xs text-gray-500">{t.description}</span>}
                                        </td>
                                        <td className="wrap">
                                            {[
                                                t.hours_planned ? `${t.hours_planned} h de leçons` : null,
                                                t.sessions_planned ? `${t.sessions_planned} séances` : null,
                                                t.duration_weeks ? `${t.duration_weeks} semaines` : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || '—'}
                                            {t.classes.length > 0 && <span className="block text-xs text-gray-500">Cours : {t.classes.join(', ')}</span>}
                                        </td>
                                        <td>{t.exams.join(' → ') || '—'}</td>
                                        <td>{t.plan ?? '—'}</td>
                                        <td className="r num">{t.active_count}</td>
                                        <td className="r num">{t.completed_count}</td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setEditing(t)}>
                                                    Modifier
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
            {editing !== undefined && <TrainingModal training={editing} onClose={() => setEditing(undefined)} />}
        </Page>
    );
}

TrainingsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default TrainingsIndex;
