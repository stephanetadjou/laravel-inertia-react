import { useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import CustomerPicker, { PickedCustomer } from '../../components/common/CustomerPicker';
import { Modal } from '../../components/ui/modal';

/*
 * Suivi des apprenants (module « formation ») : types, barre de progression
 * et formulaire d'inscription, partagés par la liste, le détail d'un
 * parcours et l'onglet « Formation » de la fiche client.
 */

export interface LearnerRow {
    id: number;
    customer_id: number;
    customer_name: string | null;
    training_id: number;
    training: string | null;
    store: string | null;
    staff_member_id: number | null;
    staff: string | null;
    started_on: string;
    target_on: string | null;
    status: 'active' | 'paused' | 'completed' | 'dropped';
    status_label: string;
    hours_done: number;
    hours_total: number;
    hours_booked: number;
    lessons_done: number;
    no_shows: number;
    sessions_done: number;
    sessions_planned: number;
    percent: number;
    last_activity: string | null;
    next_lesson: string | null;
    next_exam: { type: string; date: string; attempt: number } | null;
    exams_passed: string[];
    exams_failed: number;
    alert: string | null;
}

export interface ExamRow {
    id: number;
    learner_training_id: number;
    type: string;
    exam_on: string;
    exam_time: string | null;
    attempt: number;
    result: 'scheduled' | 'passed' | 'failed' | 'absent';
    result_label: string;
    score: string | null;
    notes: string | null;
}

export interface TrainingOption {
    id: number;
    name: string;
    hours_planned: number;
    sessions_planned: number;
    duration_weeks: number | null;
    has_plan: boolean;
}

export interface EnrollOptions {
    trainingOptions: TrainingOption[];
    stores: { id: number; name: string }[];
    staff: { id: number; name: string; store_id: number }[];
    hasPlans: boolean;
}

export const LEARNER_PILL: Record<LearnerRow['status'], string> = {
    active: 'info',
    paused: 'warn',
    completed: 'ok',
    dropped: 'idle',
};
export const EXAM_PILL: Record<ExamRow['result'], string> = {
    scheduled: 'info',
    passed: 'ok',
    failed: 'bad',
    absent: 'warn',
};

export const field =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

/** Barre de progression (%) avec la couleur du rythme : vert, ou ambre si une alerte est levée. */
export function Progress({ percent, alert = false }: { percent: number; alert?: boolean }) {
    return (
        <div className="flex items-center gap-2" title={`${percent} %`}>
            <div className="h-2 w-24 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                <div className={`h-full rounded-full ${alert ? 'bg-amber-500' : 'bg-emerald-500'}`} style={{ width: `${Math.min(100, Math.max(0, percent))}%` }} />
            </div>
            <span className="num text-xs text-gray-600 dark:text-gray-400">{percent} %</span>
        </div>
    );
}

/** « 12,5 h / 20 h » et, s'il y en a, « 8 / 20 séances ». */
export function hoursLabel(r: Pick<LearnerRow, 'hours_done' | 'hours_total' | 'sessions_done' | 'sessions_planned'>): string {
    const parts: string[] = [];
    if (r.hours_total > 0) parts.push(`${r.hours_done.toLocaleString('fr-FR')} h / ${r.hours_total} h`);
    if (r.sessions_planned > 0) parts.push(`${r.sessions_done} / ${r.sessions_planned} séances`);
    return parts.join(' · ') || '—';
}

/** Inscription à une formation : apprenant, formation, référent, début, formule payée en N fois. */
export function EnrollModal({ options, customer: fixed, onClose }: { options: EnrollOptions; customer?: PickedCustomer; onClose: () => void }) {
    const [customer, setCustomer] = useState<PickedCustomer | null>(fixed ?? null);
    const first = options.trainingOptions[0];
    const { data, setData, post, processing, errors } = useForm({
        customer_id: fixed ? String(fixed.id) : '',
        training_id: first ? String(first.id) : '',
        store_id: String(options.stores[0]?.id ?? ''),
        staff_member_id: '',
        started_on: new Date().toISOString().slice(0, 10),
        target_on: '',
        extra_hours: '0',
        with_plan: true,
        installments: '3',
        notes: '',
    });
    const training = options.trainingOptions.find((t) => String(t.id) === data.training_id);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/learners', { preserveScroll: true, onSuccess: () => onClose() });
    };

    if (options.trainingOptions.length === 0) {
        return (
            <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
                <h2 className="text-lg font-semibold">Aucune formation</h2>
                <p className="mt-2 text-sm text-gray-600">Créez d’abord une formation (heures prévues, examens, formule) dans Apprenants › Formations.</p>
                <div className="mt-4 flex justify-end">
                    <a href="/trainings" className="mdash-btn brand">
                        Créer une formation
                    </a>
                </div>
            </Modal>
        );
    }

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Inscrire un apprenant</h2>
                {!fixed && (
                    <label className="block">
                        <span className="mb-1 block font-medium">Apprenant</span>
                        <CustomerPicker
                            value={customer}
                            onChange={(c) => {
                                setCustomer(c);
                                setData('customer_id', c ? String(c.id) : '');
                            }}
                        />
                        {errors.customer_id && <span className="text-xs text-error-500">{errors.customer_id}</span>}
                    </label>
                )}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label className="sm:col-span-2">
                        <span className="mb-1 block font-medium">Formation</span>
                        <select className={field} value={data.training_id} onChange={(e) => setData('training_id', e.target.value)}>
                            {options.trainingOptions.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name}
                                    {t.hours_planned ? ` — ${t.hours_planned} h` : ''}
                                    {t.sessions_planned ? ` — ${t.sessions_planned} séances` : ''}
                                </option>
                            ))}
                        </select>
                    </label>
                    {options.stores.length > 1 && (
                        <label>
                            <span className="mb-1 block font-medium">Établissement</span>
                            <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                {options.stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    <label>
                        <span className="mb-1 block font-medium">Formateur référent</span>
                        <select className={field} value={data.staff_member_id} onChange={(e) => setData('staff_member_id', e.target.value)}>
                            <option value="">Aucun</option>
                            {options.staff
                                .filter((s) => s.store_id === Number(data.store_id))
                                .map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                        </select>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Début</span>
                        <input type="date" className={field} value={data.started_on} onChange={(e) => setData('started_on', e.target.value)} />
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Objectif de fin</span>
                        <input type="date" className={field} value={data.target_on} min={data.started_on} onChange={(e) => setData('target_on', e.target.value)} />
                        <span className="mt-1 block text-xs text-gray-500">{training?.duration_weeks ? `Vide = début + ${training.duration_weeks} semaines.` : 'Facultatif.'}</span>
                        {errors.target_on && <span className="text-xs text-error-500">{errors.target_on}</span>}
                    </label>
                    {(training?.hours_planned ?? 0) > 0 && (
                        <label>
                            <span className="mb-1 block font-medium">Heures en plus</span>
                            <input type="number" min={0} className={field} value={data.extra_hours} onChange={(e) => setData('extra_hours', e.target.value)} />
                        </label>
                    )}
                </div>
                {options.hasPlans && training?.has_plan && (
                    <div className="rounded-lg bg-gray-50 p-3 dark:bg-white/[0.03]">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={data.with_plan} onChange={(e) => setData('with_plan', e.target.checked)} />
                            <span>Créer la formule et son échéancier</span>
                        </label>
                        {data.with_plan && (
                            <label className="mt-2 flex items-center gap-2">
                                <span>Payée en</span>
                                <select className={`${field} w-24`} value={data.installments} onChange={(e) => setData('installments', e.target.value)}>
                                    {[1, 2, 3, 4, 5, 6].map((n) => (
                                        <option key={n} value={n}>
                                            {n} fois
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </div>
                )}
                <label className="block">
                    <span className="mb-1 block font-medium">Note</span>
                    <textarea className={field} rows={2} value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                </label>
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing || !data.customer_id || !data.training_id}>
                        {processing ? 'Inscription…' : 'Inscrire'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
