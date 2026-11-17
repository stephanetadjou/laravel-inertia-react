import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';

/*
 * Paramètres › Occupation : les seuils du moteur d'occupation. Un créneau, un
 * cours ou une ressource peu utilisé est un creux à remplir, un jour trop plein
 * est saturé, une ressource qui ne sort plus est immobilisée.
 */

type Key = 'low' | 'high' | 'window_days' | 'class_low' | 'class_window_days' | 'idle_days' | 'safety_stock' | 'sale_days';

interface PageProps {
    settings: Record<Key, number>;
    defaults: Record<Key, number>;
    modules: { rdv: boolean; seances_groupe: boolean; ressources: boolean; vehicules: boolean };
    [key: string]: unknown;
}

const input =
    'w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function OccupationSettings() {
    const { settings, defaults, modules } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors } = useForm<Record<Key, number>>({ ...settings });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/occupation', { preserveScroll: true });
    };

    const field = (key: Key, label: string, unit: string, hint: string) => (
        <label className="flex flex-col gap-1">
            <span className="font-medium text-gray-700 dark:text-gray-300">{label}</span>
            <span className="flex items-center gap-2">
                <input type="number" min={0} value={data[key]} onChange={(e) => setData(key, Number(e.target.value))} className={input} />
                <span className="text-gray-500">{unit}</span>
            </span>
            <span className="text-xs text-gray-500">
                {hint} Par défaut : {defaults[key]} {unit}.
            </span>
            {errors[key] && <span className="text-xs text-error-500">{errors[key]}</span>}
        </label>
    );

    return (
        <Page title="Occupation" subtitle="La ressource à rentabiliser : créneaux, places de cours et ressources">
            <form onSubmit={submit} className="mdash-grid">
                {modules.rdv && (
                    <Panel title="Agenda des intervenants" sub="Heures réservées sur les heures de travail" className="c6">
                        <div className="grid gap-5 text-sm sm:grid-cols-2">
                            {field('low', 'Creux en dessous de', '%', 'Une journée moins remplie est proposée aux clients à relancer.')}
                            {field('high', 'Saturation à partir de', '%', 'Une journée plus remplie : proposez un autre jour.')}
                            {field('window_days', 'Surveiller les', 'jours', 'Aujourd’hui et les jours suivants.')}
                        </div>
                    </Panel>
                )}
                {modules.seances_groupe && (
                    <Panel title="Cours et sessions" sub="Inscrits sur les places" className="c6">
                        <div className="grid gap-5 text-sm sm:grid-cols-2">
                            {field('class_low', 'Peu rempli en dessous de', '%', 'Un cours ou une session à remplir.')}
                            {field('class_window_days', 'Surveiller les', 'jours', 'Les séances à venir dans ce délai.')}
                        </div>
                    </Panel>
                )}
                {(modules.ressources || modules.vehicules) && (
                    <Panel title={modules.vehicules ? 'Flotte' : 'Ressources'} sub={modules.vehicules ? 'Véhicules en location et à vendre' : 'Véhicules, salles, matériel'} className="c6">
                        <div className="grid gap-5 text-sm sm:grid-cols-2">
                            {field('idle_days', 'Immobilisé après', 'jours', 'Sans aucune réservation ni location depuis ce nombre de jours.')}
                            {modules.vehicules && field('safety_stock', 'Stock de sécurité', 'véhicules', 'Toujours ce nombre de véhicules libres chaque jour.')}
                            {modules.vehicules && field('sale_days', 'Vente qui traîne après', 'jours', 'Un véhicule à vendre depuis plus longtemps est signalé.')}
                        </div>
                    </Panel>
                )}
                <div className="c12">
                    <button type="submit" disabled={processing} className="rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white hover:bg-brand-600 disabled:opacity-50">
                        Enregistrer
                    </button>
                </div>
            </form>
        </Page>
    );
}

OccupationSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OccupationSettings;
