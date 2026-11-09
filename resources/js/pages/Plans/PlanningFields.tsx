import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';

/*
 * Planning des séances d'un abonnement : jours et heures, durée, séance
 * suivie par un intervenant (coach) ou en autonomie. Chaque séance arrive
 * dans l'agenda de l'entreprise.
 */

export interface PlanningValue {
    planning: { weekday: number; time: string }[];
    session_minutes: string;
    coached: boolean;
    staff_member_id: string;
}

export interface StaffOption {
    id: number;
    name: string;
    store_id: number;
}

const DAYS = [
    [1, 'Lun'],
    [2, 'Mar'],
    [3, 'Mer'],
    [4, 'Jeu'],
    [5, 'Ven'],
    [6, 'Sam'],
    [7, 'Dim'],
] as const;

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export const emptyPlanning = (): PlanningValue => ({ planning: [], session_minutes: '60', coached: false, staff_member_id: '' });

export function PlanningFields({
    value,
    onChange,
    staff,
    storeId,
    errors = {},
}: {
    value: PlanningValue;
    onChange: (v: PlanningValue) => void;
    staff: StaffOption[];
    storeId?: number | null;
    errors?: Record<string, string>;
}) {
    const slot = (day: number) => value.planning.find((s) => s.weekday === day);
    const toggle = (day: number) =>
        onChange({ ...value, planning: slot(day) ? value.planning.filter((s) => s.weekday !== day) : [...value.planning, { weekday: day, time: value.planning[0]?.time ?? '18:00' }].sort((a, b) => a.weekday - b.weekday) });
    const setTime = (day: number, time: string) => onChange({ ...value, planning: value.planning.map((s) => (s.weekday === day ? { ...s, time } : s)) });
    const coaches = staff.filter((s) => !storeId || s.store_id === storeId);

    return (
        <fieldset className="space-y-3 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <legend className="px-1 font-medium">Séances dans l’agenda (facultatif)</legend>
            <p className="text-xs text-gray-500">Jours et heures où le client vient : chaque séance remplit l’agenda pour les 8 prochaines semaines, prolongé automatiquement.</p>
            <div className="flex flex-wrap gap-2">
                {DAYS.map(([day, label]) => {
                    const s = slot(day);
                    return (
                        <div key={day} className={`flex flex-col items-center gap-1 rounded-lg border px-2 py-1.5 ${s ? 'border-brand-500 bg-brand-50 dark:bg-white/10' : 'border-gray-200 dark:border-gray-700'}`}>
                            <label className="flex items-center gap-1 text-xs font-medium">
                                <input type="checkbox" checked={!!s} onChange={() => toggle(day)} />
                                {label}
                            </label>
                            {s && <input type="time" value={s.time} onChange={(e) => setTime(day, e.target.value)} className="w-[5.5rem] rounded border border-gray-300 px-1 py-0.5 text-xs dark:border-gray-700 dark:bg-gray-900" aria-label={`Heure du ${label}`} />}
                        </div>
                    );
                })}
            </div>
            {errors.planning && <p className="text-xs text-error-500">{errors.planning}</p>}
            {value.planning.length > 0 && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <label>
                        <span className="mb-1 block text-xs font-medium">Durée (min)</span>
                        <input type="number" min={15} max={480} step={15} value={value.session_minutes} onChange={(e) => onChange({ ...value, session_minutes: e.target.value })} className={field} />
                    </label>
                    <label>
                        <span className="mb-1 block text-xs font-medium">Suivi</span>
                        <select value={value.coached ? '1' : '0'} onChange={(e) => onChange({ ...value, coached: e.target.value === '1', staff_member_id: e.target.value === '1' ? value.staff_member_id : '' })} className={field}>
                            <option value="0">En autonomie</option>
                            <option value="1">Suivi par un coach</option>
                        </select>
                    </label>
                    {value.coached && (
                        <label>
                            <span className="mb-1 block text-xs font-medium">Par qui ?</span>
                            <select value={value.staff_member_id} onChange={(e) => onChange({ ...value, staff_member_id: e.target.value })} className={field} required>
                                <option value="">Choisir…</option>
                                {coaches.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                            {coaches.length === 0 && <span className="mt-1 block text-xs text-gray-500">Ajoutez vos coachs dans Paramètres › Intervenants.</span>}
                            {errors.staff_member_id && <span className="text-xs text-error-500">{errors.staff_member_id}</span>}
                        </label>
                    )}
                </div>
            )}
        </fieldset>
    );
}

/** Modifier le planning d'un abonnement existant. */
export function PlanningModal({
    subscription,
    staff,
    onClose,
}: {
    subscription: { id: number; plan: string | null; planning: { weekday: number; time: string }[]; session_minutes: number | null; coached: boolean; staff_member_id: number | null; store_id?: number };
    staff: StaffOption[];
    onClose: () => void;
}) {
    const form = useForm<PlanningValue>({
        planning: subscription.planning,
        session_minutes: String(subscription.session_minutes ?? 60),
        coached: subscription.coached,
        staff_member_id: subscription.staff_member_id ? String(subscription.staff_member_id) : '',
    });
    const save: FormEventHandler = (e) => {
        e.preventDefault();
        form.put(`/subscriptions/${subscription.id}/planning`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={save} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">Planning · {subscription.plan}</h2>
                <PlanningFields value={form.data} onChange={(v) => form.setData(v)} staff={staff} storeId={subscription.store_id} errors={form.errors as Record<string, string>} />
                <div className="flex justify-end gap-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={form.processing}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}
