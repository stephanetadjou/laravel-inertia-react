import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';
import { ModuleSettingsFields, ModuleSettingsValues } from './ModuleSettingsFields';

/*
 * Paramètres › Modules : modules à la carte. Le socle (clients, ventes,
 * analyses, actions du jour, rapport du soir) est toujours actif ; un module
 * décoché disparaît du menu, des fiches et des réglages, sans rien effacer.
 */

interface ModuleRow {
    key: string;
    label: string;
    description: string;
    enabled: boolean;
    suggested: boolean;
}

interface PageProps {
    modules: ModuleRow[];
    settings: ModuleSettingsValues;
    beneficiaryLabels: { value: string; label: string }[];
    sectorLabel: string | null;
    [key: string]: unknown;
}

function ModulesSettings() {
    const { modules, settings, beneficiaryLabels, sectorLabel } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors } = useForm<{ enabled: string[]; settings: ModuleSettingsValues }>({
        enabled: modules.filter((m) => m.enabled).map((m) => m.key),
        settings,
    });

    const toggle = (key: string, on: boolean) => setData('enabled', on ? [...data.enabled, key] : data.enabled.filter((k) => k !== key));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/modules', { preserveScroll: true });
    };

    return (
        <Page title="Modules" subtitle="Choisissez les modules utiles à votre activité : le menu et les fiches clients s’adaptent">
            <form onSubmit={submit} className="mdash-grid">
                <Panel title="Socle" sub="Toujours actif" className="c4">
                    <ul className="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                        <li>Fiches clients, segments, score et alerte de départ</li>
                        <li>Ventes et encaissements</li>
                        <li>Analyses et tableau de bord</li>
                        <li>Actions du jour (relances WhatsApp)</li>
                        <li>Rapport du soir</li>
                    </ul>
                </Panel>

                <Panel title="Modules" sub={sectorLabel ? `Recommandés pour « ${sectorLabel} » : marqués d’une étoile` : undefined} className="c8">
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {modules.map((m) => {
                            const checked = data.enabled.includes(m.key);
                            return (
                                <label
                                    key={m.key}
                                    className={`flex cursor-pointer gap-3 rounded-lg border px-3 py-2.5 text-sm transition-colors ${
                                        checked ? 'border-brand-500 bg-brand-50 dark:bg-white/10' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'
                                    }`}
                                >
                                    <input type="checkbox" checked={checked} onChange={(e) => toggle(m.key, e.target.checked)} className="mt-1 accent-brand-500" />
                                    <span>
                                        <span className="block font-medium text-gray-800 dark:text-white/90">
                                            {m.label}
                                            {m.suggested && <span className="ml-1 text-brand-text" title="Recommandé pour votre secteur">★</span>}
                                        </span>
                                        <span className="block text-xs text-gray-500 dark:text-gray-400">{m.description}</span>
                                    </span>
                                </label>
                            );
                        })}
                    </div>
                    {data.enabled.includes('campagnes') && (
                        <p className="mt-3 text-xs text-gray-500">Les campagnes demandent un compte WhatsApp Cloud API vérifié par Meta : renseignez-le dans Paramètres › WhatsApp.</p>
                    )}
                </Panel>

                <Panel title="Réglages des modules" sub="Valeurs par défaut, modifiables à tout moment" className="c12">
                    <ModuleSettingsFields enabled={data.enabled} values={data.settings} onChange={(v) => setData('settings', v)} beneficiaryLabels={beneficiaryLabels} errors={errors as Record<string, string>} />
                    <div className="mt-4 flex justify-end">
                        <button type="submit" className="mdash-btn brand" disabled={processing}>
                            {processing ? 'Enregistrement…' : 'Enregistrer'}
                        </button>
                    </div>
                </Panel>
            </form>
        </Page>
    );
}

ModulesSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ModulesSettings;
