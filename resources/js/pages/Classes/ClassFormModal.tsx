import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Modal } from '../../components/ui/modal';

/* Création / modification d'un cours régulier : activité, lieu, coach, jour, heure, places. */

export interface ClassRow {
    id: number;
    name: string;
    store_id: number;
    store_name: string | null;
    catalog_item_id: number | null;
    activity: string | null;
    staff_member_id: number | null;
    coach: string | null;
    weekday: number;
    start_time: string;
    end_time: string;
    schedule: string;
    capacity: number;
    enrolled: number;
    starts_on: string;
    ends_on: string | null;
    active: boolean;
}

export interface ClassFormOptions {
    stores: { id: number; name: string }[];
    coaches: { id: number; name: string; store_id: number }[];
    activities: { id: number; name: string }[];
    weekdays: Record<string, string>;
    defaultCapacity: number;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

export default function ClassFormModal({ groupClass, options, onClose }: { groupClass: ClassRow | null; options: ClassFormOptions; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: groupClass?.name ?? '',
        store_id: groupClass ? String(groupClass.store_id) : options.stores.length === 1 ? String(options.stores[0].id) : '',
        catalog_item_id: groupClass?.catalog_item_id ? String(groupClass.catalog_item_id) : '',
        staff_member_id: groupClass?.staff_member_id ? String(groupClass.staff_member_id) : '',
        weekday: groupClass ? String(groupClass.weekday) : '6',
        start_time: groupClass?.start_time ?? '10:00',
        end_time: groupClass?.end_time ?? '11:00',
        capacity: String(groupClass?.capacity ?? options.defaultCapacity),
        starts_on: groupClass?.starts_on ?? new Date().toISOString().slice(0, 10),
        ends_on: groupClass?.ends_on ?? '',
        active: groupClass?.active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const opts = { preserveScroll: true, onSuccess: onClose };
        if (groupClass) put(`/classes/${groupClass.id}`, opts);
        else post('/classes', opts);
    };

    const coaches = options.coaches.filter((c) => !data.store_id || c.store_id === Number(data.store_id));

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">{groupClass ? 'Modifier le cours' : 'Nouveau cours'}</h2>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label className="sm:col-span-2">
                        <span className="mb-1 block font-medium">Nom du cours</span>
                        <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="ex. Judo 6-9 ans" required />
                        {errors.name && <span className="text-xs text-error-500">{errors.name}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Lieu</span>
                        <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} required>
                            <option value="">—</option>
                            {options.stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        {errors.store_id && <span className="text-xs text-error-500">{errors.store_id}</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Coach</span>
                        <select className={field} value={data.staff_member_id} onChange={(e) => setData('staff_member_id', e.target.value)}>
                            <option value="">—</option>
                            {coaches.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        {options.coaches.length === 0 && <span className="mt-1 block text-xs text-gray-500">Ajoutez vos coachs dans Paramètres › Intervenants.</span>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Activité</span>
                        <select className={field} value={data.catalog_item_id} onChange={(e) => setData('catalog_item_id', e.target.value)}>
                            <option value="">—</option>
                            {options.activities.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Places</span>
                        <input type="number" min={1} className={field} value={data.capacity} onChange={(e) => setData('capacity', e.target.value)} />
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Jour</span>
                        <select className={field} value={data.weekday} onChange={(e) => setData('weekday', e.target.value)}>
                            {Object.entries(options.weekdays).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <div className="grid grid-cols-2 gap-2">
                        <label>
                            <span className="mb-1 block font-medium">Début</span>
                            <input type="time" className={field} value={data.start_time} onChange={(e) => setData('start_time', e.target.value)} required />
                        </label>
                        <label>
                            <span className="mb-1 block font-medium">Fin</span>
                            <input type="time" className={field} value={data.end_time} onChange={(e) => setData('end_time', e.target.value)} required />
                        </label>
                        {errors.end_time && <span className="col-span-2 text-xs text-error-500">{errors.end_time}</span>}
                    </div>
                    <label>
                        <span className="mb-1 block font-medium">Premier cours le</span>
                        <input type="date" className={field} value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} />
                    </label>
                    <label>
                        <span className="mb-1 block font-medium">Dernier cours (facultatif)</span>
                        <input type="date" className={field} value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} />
                    </label>
                </div>
                {groupClass && (
                    <label className="flex items-center gap-2">
                        <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                        Cours actif (décochez pour l’arrêter)
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
