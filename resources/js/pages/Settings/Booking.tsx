import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';

/*
 * Paramètres › Réservation en ligne : ouverture de la page publique, mode de
 * confirmation, créneaux, prestations réservables, et pour chaque
 * établissement ses horaires, sa capacité et son numéro WhatsApp.
 */

type Hours = Record<string, { open: string; close: string } | null>;

interface StoreRow {
    id: number;
    name: string;
    hours: Hours;
    capacity: number;
    phone: string;
    break: { start: string; end: string } | null;
    whatsapp_display_phone: string | null;
}

interface PageProps {
    settings: { enabled: boolean; auto_confirm: boolean; slot_step: number; min_notice_minutes: number; horizon_days: number; welcome: string };
    url: string;
    stores: StoreRow[];
    services: { id: number; name: string; price: number; duration_minutes: number | null; bookable_online: boolean }[];
    [key: string]: unknown;
}

const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function StoreHours({ store }: { store: StoreRow }) {
    const { data, setData, put, processing, errors } = useForm({
        hours: store.hours,
        capacity: String(store.capacity),
        phone: store.phone,
        break: store.break,
    });

    function setDay(day: number, value: { open: string; close: string } | null) {
        setData('hours', { ...data.hours, [day]: value });
    }

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/settings/booking/stores/${store.id}`, { preserveScroll: true });
    };

    return (
        <Panel title={store.name} sub="Horaires proposés à la réservation" className="c6">
            <form onSubmit={submit} className="space-y-3">
                <table className="w-full text-sm">
                    <tbody>
                        {DAYS.map((label, i) => {
                            const day = i + 1;
                            const value = data.hours[day] ?? null;
                            return (
                                <tr key={day} className="border-t border-gray-100 first:border-0 dark:border-gray-800">
                                    <td className="py-1.5 pr-2">
                                        <label className="flex items-center gap-2">
                                            <input type="checkbox" checked={value !== null} onChange={(e) => setDay(day, e.target.checked ? { open: '09:00', close: '18:00' } : null)} />
                                            {label}
                                        </label>
                                    </td>
                                    <td className="py-1.5">
                                        {value ? (
                                            <span className="flex items-center gap-1.5">
                                                <input type="time" aria-label={`${label} ouverture`} value={value.open} onChange={(e) => setDay(day, { ...value, open: e.target.value })} className={fieldClasses()} />
                                                <span className="text-gray-400">–</span>
                                                <input type="time" aria-label={`${label} fermeture`} value={value.close} onChange={(e) => setDay(day, { ...value, close: e.target.value })} className={fieldClasses()} />
                                            </span>
                                        ) : (
                                            <span className="text-gray-400">Fermé</span>
                                        )}
                                        {errors[`hours.${day}` as keyof typeof errors] && <p className="mt-1 text-xs text-error-500">{errors[`hours.${day}` as keyof typeof errors]}</p>}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
                <div className="text-sm">
                    <label className="flex items-center gap-2 font-medium text-gray-700 dark:text-gray-300">
                        <input type="checkbox" checked={data.break !== null} onChange={(e) => setData('break', e.target.checked ? { start: '12:00', end: '14:00' } : null)} />
                        Pause chaque jour (déjeuner)
                    </label>
                    {data.break && (
                        <span className="mt-2 flex items-center gap-1.5">
                            <input type="time" aria-label="Début de la pause" value={data.break.start} onChange={(e) => setData('break', { ...data.break!, start: e.target.value })} className={fieldClasses()} />
                            <span className="text-gray-400">–</span>
                            <input type="time" aria-label="Fin de la pause" value={data.break.end} onChange={(e) => setData('break', { ...data.break!, end: e.target.value })} className={fieldClasses()} />
                        </span>
                    )}
                    {(errors as Record<string, string>)['break.end'] && <p className="mt-1 text-xs text-error-500">{(errors as Record<string, string>)['break.end']}</p>}
                    <span className="mt-1 block text-xs text-gray-500">Aucun rendez-vous en ligne pendant la pause. Jours fériés et congés : Paramètres › Fermetures.</span>
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Rendez-vous en même temps</span>
                        <input type="number" min={1} max={50} value={data.capacity} onChange={(e) => setData('capacity', e.target.value)} className={fieldClasses()} />
                        <span className="mt-1 block text-xs text-gray-500">sans intervenants déclarés ; sinon, un rendez-vous par intervenant libre</span>
                    </label>
                    <label className="text-sm">
                        <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Numéro WhatsApp de l'établissement</span>
                        <input type="tel" value={data.phone} onChange={(e) => setData('phone', e.target.value)} placeholder={store.whatsapp_display_phone ?? '077 00 01 11'} className={fieldClasses()} />
                        <span className="mt-1 block text-xs text-gray-500">le client y envoie son récapitulatif</span>
                        {errors.phone && <p className="mt-1 text-xs text-error-500">{errors.phone}</p>}
                    </label>
                </div>
                <div className="flex justify-end">
                    <button type="submit" className="mdash-btn ghost" disabled={processing}>
                        {processing ? 'Enregistrement…' : 'Enregistrer les horaires'}
                    </button>
                </div>
            </form>
        </Panel>
    );
}

function BookingSettings() {
    const { settings, url, stores, services } = usePage<PageProps>().props;
    const [copied, setCopied] = useState(false);
    const { data, setData, put, processing, errors } = useForm({
        ...settings,
        bookable_ids: services.filter((s) => s.bookable_online).map((s) => s.id),
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/booking', { preserveScroll: true });
    };

    function copy() {
        navigator.clipboard?.writeText(url).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        });
    }

    function toggleService(id: number) {
        setData('bookable_ids', data.bookable_ids.includes(id) ? data.bookable_ids.filter((s) => s !== id) : [...data.bookable_ids, id]);
    }

    return (
        <Page title="Réservation en ligne" subtitle="Une page où vos clients prennent rendez-vous eux-mêmes, sans compte">
            <div className="mdash-grid">
                <Panel title="Votre page de réservation" sub={settings.enabled ? 'Ouverte' : 'Fermée'} className="c12">
                    <div className="flex flex-wrap items-center gap-2">
                        <code className="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-white/5">{url}</code>
                        <button type="button" className="mdash-btn ghost sm" onClick={copy}>
                            {copied ? 'Copié ✓' : 'Copier le lien'}
                        </button>
                        {settings.enabled && (
                            <a href={url} target="_blank" rel="noreferrer" className="mdash-btn ghost sm">
                                Ouvrir la page
                            </a>
                        )}
                    </div>
                    <p className="mdash-scope">Partagez ce lien dans votre bio WhatsApp, vos statuts, Instagram, Facebook et votre fiche Google Maps.</p>
                </Panel>

                <Panel title="Réglages" className="c6">
                    <form onSubmit={submit} className="space-y-4 text-sm">
                        <label className="flex items-center gap-2 font-medium">
                            <input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                            Page de réservation ouverte
                        </label>
                        <fieldset>
                            <legend className="mb-1.5 font-medium text-gray-700 dark:text-gray-300">Quand un client réserve</legend>
                            <label className="flex items-start gap-2 py-1">
                                <input type="radio" name="auto_confirm" checked={!data.auto_confirm} onChange={() => setData('auto_confirm', false)} />
                                <span>
                                    Le rendez-vous est <b>à confirmer</b> par l'équipe <span className="text-gray-500">(recommandé)</span>
                                </span>
                            </label>
                            <label className="flex items-start gap-2 py-1">
                                <input type="radio" name="auto_confirm" checked={data.auto_confirm} onChange={() => setData('auto_confirm', true)} />
                                <span>
                                    Le rendez-vous est <b>confirmé tout de suite</b>
                                </span>
                            </label>
                        </fieldset>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Créneaux toutes les</span>
                                <select value={data.slot_step} onChange={(e) => setData('slot_step', Number(e.target.value))} className={fieldClasses()}>
                                    {[10, 15, 20, 30, 45, 60].map((m) => (
                                        <option key={m} value={m}>
                                            {m} min
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Au plus tôt</span>
                                <select value={data.min_notice_minutes} onChange={(e) => setData('min_notice_minutes', Number(e.target.value))} className={fieldClasses()}>
                                    {[
                                        [0, 'immédiatement'],
                                        [60, '1 h avant'],
                                        [120, '2 h avant'],
                                        [240, '4 h avant'],
                                        [1440, 'la veille'],
                                    ].map(([v, l]) => (
                                        <option key={v} value={v}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Jusqu'à</span>
                                <select value={data.horizon_days} onChange={(e) => setData('horizon_days', Number(e.target.value))} className={fieldClasses()}>
                                    {[7, 14, 30, 60, 90].map((d) => (
                                        <option key={d} value={d}>
                                            {d} jours à l'avance
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>
                        <label className="block">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Message d'accueil (facultatif)</span>
                            <textarea rows={2} value={data.welcome} onChange={(e) => setData('welcome', e.target.value)} placeholder="ex. Bienvenue au salon ! Réservez votre créneau en 1 minute." className={fieldClasses()} />
                            {errors.welcome && <p className="mt-1 text-xs text-error-500">{errors.welcome}</p>}
                        </label>
                        <fieldset>
                            <legend className="mb-1.5 font-medium text-gray-700 dark:text-gray-300">Prestations réservables en ligne</legend>
                            {services.length === 0 ? (
                                <p className="text-gray-500">Ajoutez d'abord vos prestations dans Ventes › Catalogue.</p>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {services.map((s) => (
                                        <label key={s.id} className="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 dark:border-gray-700">
                                            <input type="checkbox" checked={data.bookable_ids.includes(s.id)} onChange={() => toggleService(s.id)} />
                                            {s.name}
                                        </label>
                                    ))}
                                </div>
                            )}
                        </fieldset>
                        <div className="flex justify-end">
                            <button type="submit" className="mdash-btn brand" disabled={processing}>
                                {processing ? 'Enregistrement…' : 'Enregistrer'}
                            </button>
                        </div>
                    </form>
                </Panel>

                {stores.map((s) => (
                    <StoreHours key={s.id} store={s} />
                ))}
            </div>
        </Page>
    );
}

BookingSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default BookingSettings;
