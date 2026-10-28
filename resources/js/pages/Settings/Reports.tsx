import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';

/*
 * Paramètres › Rapports : le rapport quotidien au dirigeant, envoyé chaque
 * soir par email, consultable et imprimable à tout moment.
 */

interface PageProps {
    settings: { enabled: boolean; recipients: string[]; send_at: string; monthly_target: number; last_sent_on: string | null };
    today: string;
    userEmail: string;
    mailConfigured: boolean;
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function ReportsSettings() {
    const { settings, today, userEmail, mailConfigured } = usePage<PageProps>().props;
    const [date, setDate] = useState(today);
    const { data, setData, put, processing, errors } = useForm({
        enabled: settings.enabled,
        recipients: settings.recipients.length ? settings.recipients : [userEmail],
        send_at: settings.send_at,
        monthly_target: settings.monthly_target ? String(settings.monthly_target) : '',
    });
    const [recipientsText, setRecipientsText] = useState(data.recipients.join(', '));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/reports', { preserveScroll: true });
    };

    function updateRecipients(text: string) {
        setRecipientsText(text);
        setData(
            'recipients',
            text
                .split(/[\s,;]+/)
                .map((r) => r.trim())
                .filter(Boolean),
        );
    }

    const recipientError = Object.entries(errors).find(([key]) => key.startsWith('recipients'))?.[1];

    return (
        <Page title="Rapports" subtitle="Le rapport quotidien du dirigeant : la journée, le lendemain et les actions à mener">
            <div className="mdash-grid">
                <Panel title="Rapport quotidien" sub={settings.enabled ? `Envoyé chaque soir à ${settings.send_at}` : 'Envoi désactivé'} className="c6">
                    <form onSubmit={submit} className="space-y-4 text-sm">
                        <label className="flex items-center gap-2 font-medium">
                            <input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                            Envoyer le rapport chaque soir par email
                        </label>
                        <label className="block">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Destinataires</span>
                            <input value={recipientsText} onChange={(e) => updateRecipients(e.target.value)} className={fieldClasses()} placeholder="dg@entreprise.ga, gerant@entreprise.ga" />
                            <span className="mt-1 block text-xs text-gray-500">Séparez les adresses par une virgule.</span>
                            {recipientError && <p className="mt-1 text-xs text-error-500">{recipientError}</p>}
                        </label>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Heure d'envoi</span>
                                <input type="time" value={data.send_at} onChange={(e) => setData('send_at', e.target.value)} className={fieldClasses()} />
                                <span className="mt-1 block text-xs text-gray-500">après la fermeture de vos établissements</span>
                            </label>
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Objectif de CA du mois (FCFA)</span>
                                <input type="number" min={0} step={100000} value={data.monthly_target} onChange={(e) => setData('monthly_target', e.target.value)} className={fieldClasses()} placeholder="ex. 18000000" />
                                <span className="mt-1 block text-xs text-gray-500">facultatif : le rapport suit le rythme du mois</span>
                            </label>
                        </div>
                        {!mailConfigured && (
                            <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300">
                                L'envoi d'emails n'est pas encore configuré sur ce serveur (paramètres MAIL_ du fichier .env) : les emails sont écrits dans le journal au lieu d'être envoyés.
                            </p>
                        )}
                        <div className="flex flex-wrap justify-end gap-2">
                            <button type="button" className="mdash-btn ghost" onClick={() => router.post('/settings/reports/test', {}, { preserveScroll: true })}>
                                M'envoyer le rapport du jour
                            </button>
                            <button type="submit" className="mdash-btn brand" disabled={processing}>
                                {processing ? 'Enregistrement…' : 'Enregistrer'}
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Consulter un rapport" sub="Imprimable ou enregistrable en PDF depuis le navigateur" className="c6">
                    <div className="space-y-3 text-sm">
                        <label className="block">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Jour</span>
                            <input type="date" value={date} max={today} onChange={(e) => setDate(e.target.value)} className={fieldClasses()} />
                        </label>
                        <div className="flex flex-wrap gap-2">
                            <a href={`/reports/daily?date=${date}`} target="_blank" rel="noreferrer" className="mdash-btn ghost">
                                Ouvrir le rapport
                            </a>
                            <a href={`/reports/daily?date=${date}&print=1`} target="_blank" rel="noreferrer" className="mdash-btn ghost">
                                Imprimer / PDF
                            </a>
                        </div>
                        {settings.last_sent_on && <p className="text-xs text-gray-500">Dernier envoi : rapport du {new Date(`${settings.last_sent_on}T12:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' })}.</p>}
                    </div>
                </Panel>
            </div>
        </Page>
    );
}

ReportsSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ReportsSettings;
