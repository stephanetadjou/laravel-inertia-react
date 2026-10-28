import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

/*
 * Paramètres › Intervenants : les personnes qui reçoivent les clients
 * (coiffeuse, praticien, coach…), leurs horaires, leurs prestations et leur
 * présence sur la page de réservation.
 */

type Hours = Record<string, { open: string; close: string } | null>;

interface StaffRow {
    id: number;
    name: string;
    store_id: number;
    store_name: string;
    user_id: number | null;
    color: string | null;
    own_hours: boolean;
    hours: Hours;
    service_ids: number[];
    bookable_online: boolean;
    active: boolean;
}

interface PageProps {
    staff: StaffRow[];
    stores: { id: number; name: string; hours: Hours }[];
    services: { id: number; name: string }[];
    users: { id: number; name: string }[];
    colors: string[];
    [key: string]: unknown;
}

const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function hoursSummary(hours: Hours): string {
    const open = Object.entries(hours).filter(([, v]) => v !== null);
    if (open.length === 0) return 'Aucun jour';
    const first = open[0][1]!;
    const same = open.every(([, v]) => v!.open === first.open && v!.close === first.close);
    const days = open.map(([d]) => DAYS[Number(d) - 1].slice(0, 3)).join(', ');
    return same ? `${days} · ${first.open.replace(':', 'h')}–${first.close.replace(':', 'h')}` : days;
}

function StaffModal({ member, onClose }: { member: StaffRow | null; onClose: () => void }) {
    const { stores, services, users, colors } = usePage<PageProps>().props;
    const firstStore = stores[0];
    const { data, setData, post, put, processing, errors } = useForm({
        name: member?.name ?? '',
        store_id: String(member?.store_id ?? firstStore?.id ?? ''),
        user_id: member?.user_id ? String(member.user_id) : '',
        color: member?.color ?? colors[0],
        own_hours: member?.own_hours ?? false,
        hours: member?.hours ?? firstStore?.hours ?? {},
        service_ids: member?.service_ids ?? ([] as number[]),
        bookable_online: member?.bookable_online ?? true,
    });

    const storeHours = stores.find((s) => s.id === Number(data.store_id))?.hours ?? {};

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (member) put(`/settings/staff/${member.id}`, options);
        else post('/settings/staff', options);
    };

    function setDay(day: number, value: { open: string; close: string } | null) {
        setData('hours', { ...data.hours, [day]: value });
    }

    function toggleService(id: number) {
        setData('service_ids', data.service_ids.includes(id) ? data.service_ids.filter((s) => s !== id) : [...data.service_ids, id]);
    }

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-xl p-6">
            <h3 className="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">{member ? `Modifier ${member.name}` : 'Nouvel intervenant'}</h3>
            <form onSubmit={submit} className="space-y-4 text-sm">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label>
                        <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Nom affiché aux clients</span>
                        <input value={data.name} onChange={(e) => setData('name', e.target.value)} className={fieldClasses()} placeholder="ex. Grâce M." autoFocus />
                        {errors.name && <p className="mt-1 text-xs text-error-500">{errors.name}</p>}
                    </label>
                    <label>
                        <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Établissement</span>
                        <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={fieldClasses()}>
                            {stores.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label>
                        <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Compte utilisateur (facultatif)</span>
                        <select value={data.user_id} onChange={(e) => setData('user_id', e.target.value)} className={fieldClasses()}>
                            <option value="">Aucun</option>
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <fieldset>
                        <legend className="mb-1 font-medium text-gray-700 dark:text-gray-300">Couleur dans l'agenda</legend>
                        <div className="flex flex-wrap gap-1.5">
                            {colors.map((c) => (
                                <button
                                    key={c}
                                    type="button"
                                    aria-label={`Couleur ${c}`}
                                    aria-pressed={data.color === c}
                                    onClick={() => setData('color', c)}
                                    className={`h-7 w-7 rounded-full border-2 ${data.color === c ? 'border-gray-900 dark:border-white' : 'border-transparent'}`}
                                    style={{ background: c }}
                                />
                            ))}
                        </div>
                    </fieldset>
                </div>

                <fieldset>
                    <legend className="mb-1.5 font-medium text-gray-700 dark:text-gray-300">Horaires</legend>
                    <label className="flex items-center gap-2 py-1">
                        <input type="radio" checked={!data.own_hours} onChange={() => setData('own_hours', false)} />
                        Ceux de l'établissement <span className="text-gray-500">({hoursSummary(storeHours)})</span>
                    </label>
                    <label className="flex items-center gap-2 py-1">
                        <input type="radio" checked={data.own_hours} onChange={() => setData((d) => ({ ...d, own_hours: true, hours: member?.own_hours ? d.hours : storeHours }))} />
                        Horaires propres
                    </label>
                    {data.own_hours && (
                        <table className="mt-1 w-full">
                            <tbody>
                                {DAYS.map((label, i) => {
                                    const day = i + 1;
                                    const value = data.hours[day] ?? null;
                                    return (
                                        <tr key={day} className="border-t border-gray-100 first:border-0 dark:border-gray-800">
                                            <td className="py-1 pr-2">
                                                <label className="flex items-center gap-2">
                                                    <input type="checkbox" checked={value !== null} onChange={(e) => setDay(day, e.target.checked ? { open: '09:00', close: '18:00' } : null)} />
                                                    {label}
                                                </label>
                                            </td>
                                            <td className="py-1">
                                                {value ? (
                                                    <span className="flex items-center gap-1.5">
                                                        <input type="time" aria-label={`${label} début`} value={value.open} onChange={(e) => setDay(day, { ...value, open: e.target.value })} className={fieldClasses()} />
                                                        <span className="text-gray-400">–</span>
                                                        <input type="time" aria-label={`${label} fin`} value={value.close} onChange={(e) => setDay(day, { ...value, close: e.target.value })} className={fieldClasses()} />
                                                    </span>
                                                ) : (
                                                    <span className="text-gray-400">Absent</span>
                                                )}
                                                {errors[`hours.${day}` as keyof typeof errors] && <p className="mt-1 text-xs text-error-500">{errors[`hours.${day}` as keyof typeof errors]}</p>}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </fieldset>

                <fieldset>
                    <legend className="mb-1.5 font-medium text-gray-700 dark:text-gray-300">Prestations réalisées</legend>
                    {services.length === 0 ? (
                        <p className="text-gray-500">Aucune prestation au catalogue pour l'instant.</p>
                    ) : (
                        <div className="flex flex-wrap gap-2">
                            {services.map((s) => (
                                <label key={s.id} className="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 dark:border-gray-700">
                                    <input type="checkbox" checked={data.service_ids.includes(s.id)} onChange={() => toggleService(s.id)} />
                                    {s.name}
                                </label>
                            ))}
                        </div>
                    )}
                    <p className="mt-1 text-xs text-gray-500">Aucune cochée : toutes les prestations.</p>
                </fieldset>

                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={data.bookable_online} onChange={(e) => setData('bookable_online', e.target.checked)} />
                    Proposé au choix sur la page de réservation en ligne
                </label>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : member ? 'Enregistrer' : 'Ajouter'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function StaffIndex() {
    const { staff, services } = usePage<PageProps>().props;
    const [modal, setModal] = useState<StaffRow | null | undefined>(undefined);
    const serviceName = (id: number) => services.find((s) => s.id === id)?.name;

    return (
        <Page
            title="Intervenants"
            subtitle="Qui reçoit les clients, quand, et pour quelles prestations"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal(null)}>
                    <PlusIcon />
                    Nouvel intervenant
                </button>
            }
        >
            <Panel>
                {staff.length === 0 ? (
                    <Empty title="Aucun intervenant">
                        Sans intervenant, l'établissement accepte autant de rendez-vous en même temps que sa capacité (Paramètres › Réservation en ligne). Ajoutez vos intervenants pour que
                        chacun ait son agenda et que les clients puissent choisir avec qui réserver.
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Intervenant</th>
                                    <th>Établissement</th>
                                    <th>Horaires</th>
                                    <th>Prestations</th>
                                    <th>En ligne</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {staff.map((m) => (
                                    <tr key={m.id} className={m.active ? '' : 'opacity-50'}>
                                        <td className="cell-main">
                                            <b>
                                                <span className="mr-2 inline-block h-2.5 w-2.5 rounded-full align-middle" style={{ background: m.color ?? '#94a3b8' }} />
                                                {m.name}
                                            </b>
                                            {!m.active && <span>archivé</span>}
                                        </td>
                                        <td>{m.store_name}</td>
                                        <td className="wrap">{m.own_hours ? hoursSummary(m.hours) : `Établissement · ${hoursSummary(m.hours)}`}</td>
                                        <td className="wrap">{m.service_ids.length === 0 ? 'Toutes' : m.service_ids.map(serviceName).filter(Boolean).join(', ')}</td>
                                        <td>{m.bookable_online ? 'Oui' : 'Non'}</td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setModal(m)}>
                                                    Modifier
                                                </button>
                                                <button type="button" className={m.active ? 'danger' : ''} onClick={() => router.post(`/settings/staff/${m.id}/toggle`, {}, { preserveScroll: true })}>
                                                    {m.active ? 'Archiver' : 'Réactiver'}
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

            {modal !== undefined && <StaffModal key={modal?.id ?? 'new'} member={modal} onClose={() => setModal(undefined)} />}
        </Page>
    );
}

StaffIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default StaffIndex;
