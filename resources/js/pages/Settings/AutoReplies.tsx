import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';

/*
 * Paramètres › Réponses automatiques : ce que NENTIX répond tout seul sur
 * WhatsApp (accueil, hors horaires, menu à boutons, rappel de la veille).
 * Règles simples, sans IA ; l'aperçu à droite montre le message tel qu'il
 * arrive sur le téléphone du client.
 */

type Settings = Record<string, string> & { enabled: boolean; options: string[]; labels: Record<string, string> };

interface PageProps {
    settings: Settings;
    defaults: Settings;
    options: { key: string; label: string }[];
    preview: { store: string; hours: string; booking_url: string; has_agenda: boolean };
    reminders: boolean;
    apiEnabled: boolean;
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

const TEXTS: { key: string; label: string; help?: string; reminder?: boolean }[] = [
    { key: 'welcome', label: 'Message d’accueil', help: 'Premier message du client depuis 24 h, pendant les horaires d’ouverture.' },
    { key: 'menu_intro', label: 'Phrase avant le menu' },
    { key: 'away', label: 'Message hors horaires', help: 'Envoyé au plus une fois par 24 h quand l’établissement est fermé.' },
    { key: 'prices_intro', label: 'Réponse « Nos prix »', help: 'Suivie de la liste des prix du catalogue.' },
    { key: 'prices_empty', label: 'Réponse « Nos prix » sans catalogue' },
    { key: 'hours_reply', label: 'Réponse « Nos horaires »' },
    { key: 'booking_reply', label: 'Réponse « Prendre RDV »', help: 'Avec le lien de réservation en ligne.' },
    { key: 'callback_reply', label: 'Réponse « Être rappelé »', help: 'Une tâche est créée dans « À faire ».' },
    { key: 'human_reply', label: 'Réponse « Parler à quelqu’un »', help: 'La discussion passe « à traiter » et le robot se tait 24 h.' },
    { key: 'reminder', label: 'Rappel de la veille', help: 'Avec les boutons « Je confirme » / « Je décale ».', reminder: true },
    { key: 'reminder_confirmed', label: 'Réponse à « Je confirme »', reminder: true },
    { key: 'reminder_reschedule', label: 'Réponse à « Je décale »', help: 'Une tâche « RDV à recaser » est créée.', reminder: true },
];

function fill(text: string, p: PageProps['preview']) {
    return text
        .replaceAll('{prenom}', 'Awa')
        .replaceAll('{boutique}', p.store)
        .replaceAll('{horaires}', p.hours || 'lun.–sam. 9h–18h')
        .replaceAll('{lien_rdv}', p.booking_url)
        .replaceAll('{date_rdv}', 'mardi 10 novembre')
        .replaceAll('{heure_rdv}', '10h30');
}

/** Aperçu façon téléphone : bulle du message et boutons (ou bouton de liste au-delà de 3 options). */
function PhonePreview({ body, buttons }: { body: string; buttons: string[] }) {
    return (
        <div className="mx-auto w-full max-w-[290px] rounded-[2rem] border-8 border-gray-800 bg-[#ECE5DD] p-3 shadow-theme-lg">
            <div className="mb-3 rounded-lg bg-[#075E54] px-3 py-2 text-xs font-semibold text-white">WhatsApp</div>
            <div className="rounded-lg rounded-tl-none bg-white p-2.5 text-[13px] leading-snug whitespace-pre-line text-gray-800 shadow-sm">{body}</div>
            {buttons.length > 0 && buttons.length <= 3 && (
                <div className="mt-1 space-y-1">
                    {buttons.map((b) => (
                        <div key={b} className="rounded-lg bg-white py-1.5 text-center text-[13px] font-medium text-[#00A5F4] shadow-sm">
                            {b}
                        </div>
                    ))}
                </div>
            )}
            {buttons.length > 3 && (
                <div className="mt-1 rounded-lg bg-white py-1.5 text-center text-[13px] font-medium text-[#00A5F4] shadow-sm">☰ Voir les choix ({buttons.length})</div>
            )}
        </div>
    );
}

function AutoRepliesSettings() {
    const { settings, defaults, options, preview, reminders, apiEnabled } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors } = useForm<Settings>({ ...settings });
    const [focus, setFocus] = useState('welcome');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/auto-replies', { preserveScroll: true });
    };

    const toggleOption = (key: string) =>
        setData('options', data.options.includes(key) ? data.options.filter((k) => k !== key) : [...data.options, key]);

    const menuButtons = data.options.map((k) => (k === 'rdv' && !preview.has_agenda ? data.labels.rappel : data.labels[k])).filter((v, i, a) => a.indexOf(v) === i);
    const previewBody =
        focus === 'welcome' || focus === 'menu_intro'
            ? `${fill(data.welcome, preview)}\n\n${data.menu_intro}`
            : focus === 'prices_intro'
              ? `${fill(data.prices_intro, preview)}\n• Coupe femme : 8 000 FCFA\n• Tresses : 15 000 FCFA`
              : fill(String(data[focus] ?? ''), preview);
    const previewButtons = focus === 'welcome' || focus === 'menu_intro' ? menuButtons : focus === 'reminder' ? ['Je confirme', 'Je décale'] : [];

    return (
        <Page
            title="Réponses automatiques"
            subtitle="Ce que NENTIX répond tout seul sur WhatsApp : accueil, hors horaires, menu à boutons"
            actions={
                <button type="button" className="mdash-btn ghost" onClick={() => router.post('/settings/sector-preset', {}, { preserveScroll: true })}>
                    Réappliquer le préréglage du secteur
                </button>
            }
        >
            {!apiEnabled && (
                <p className="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700">
                    Les réponses partent dès que le numéro WhatsApp de l’établissement est connecté (Paramètres › WhatsApp). Vous pouvez tout préparer dès maintenant.
                </p>
            )}
            <form onSubmit={submit} className="mdash-grid">
                <Panel title="Réglages" className="c7">
                    <div className="space-y-5 p-5 text-sm">
                        <label className="flex items-center gap-2 font-medium">
                            <input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                            Répondre automatiquement aux clients
                        </label>

                        <div>
                            <p className="mb-2 font-medium text-gray-700 dark:text-gray-300">Menu proposé (3 choix = boutons, 4 ou plus = liste)</p>
                            <div className="space-y-2">
                                {options.map((o) => (
                                    <div key={o.key} className="flex items-center gap-2">
                                        <input type="checkbox" checked={data.options.includes(o.key)} onChange={() => toggleOption(o.key)} aria-label={`Proposer ${o.label}`} />
                                        <input
                                            value={data.labels[o.key] ?? ''}
                                            maxLength={20}
                                            onChange={(e) => setData('labels', { ...data.labels, [o.key]: e.target.value })}
                                            className={`${field} max-w-xs`}
                                            aria-label={`Libellé du bouton ${o.label}`}
                                        />
                                        {o.key === 'rdv' && !preview.has_agenda && <span className="text-xs text-gray-500">sans réservation en ligne : devient « Être rappelé »</span>}
                                    </div>
                                ))}
                            </div>
                        </div>

                        {TEXTS.filter((t) => !t.reminder || reminders).map((t) => (
                            <label key={t.key} className="block">
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">{t.label}</span>
                                <textarea
                                    rows={2}
                                    value={String(data[t.key] ?? '')}
                                    onFocus={() => setFocus(t.key)}
                                    onChange={(e) => setData(t.key, e.target.value)}
                                    placeholder={String(defaults[t.key] ?? '')}
                                    className={field}
                                />
                                {t.help && <span className="mt-1 block text-xs text-gray-500">{t.help}</span>}
                                {errors[t.key] && <span className="mt-1 block text-xs text-error-500">{errors[t.key]}</span>}
                            </label>
                        ))}

                        {reminders && (
                            <label className="block">
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Modèle Meta du rappel (facultatif)</span>
                                <input value={data.reminder_template ?? ''} onChange={(e) => setData('reminder_template', e.target.value)} className={field} placeholder="rappel_rdv" />
                                <span className="mt-1 block text-xs text-gray-500">
                                    Quand le client n’a pas écrit depuis 24 h, WhatsApp impose un modèle approuvé, avec deux boutons de réponse rapide. Sans modèle, le rappel reste dans « À faire ».
                                </span>
                            </label>
                        )}
                        <p className="text-xs text-gray-500">Variables : {'{prenom}'}, {'{boutique}'}, {'{horaires}'}, {'{lien_rdv}'}, {'{date_rdv}'}, {'{heure_rdv}'}.</p>

                        <button type="submit" className="mdash-btn brand" disabled={processing}>
                            Enregistrer
                        </button>
                    </div>
                </Panel>
                <Panel title="Aperçu" sub="Cliquez dans un message pour le voir ici" className="c5">
                    <div className="p-5">
                        <PhonePreview body={previewBody} buttons={previewButtons} />
                    </div>
                </Panel>
            </form>
        </Page>
    );
}

AutoRepliesSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AutoRepliesSettings;
