import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';

/*
 * Paramètres › Fermetures : jours fériés, congés et fermetures
 * exceptionnelles. Aucun rendez-vous en ligne ces jours-là ; l'agenda les
 * affiche grisés.
 */

interface ClosureRow {
    id: number;
    label: string;
    starts_on: string;
    ends_on: string;
    store_id: number | null;
    store_name: string | null;
    past: boolean;
}

interface PageProps {
    closures: ClosureRow[];
    stores: { id: number; name: string }[];
    year: number;
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function period(c: ClosureRow): string {
    const f = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
    return c.starts_on === c.ends_on ? f(c.starts_on) : `du ${f(c.starts_on)} au ${f(c.ends_on)}`;
}

function ClosuresIndex() {
    const { closures, stores, year } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({ label: '', starts_on: '', ends_on: '', store_id: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/settings/closures', { preserveScroll: true, onSuccess: () => reset() });
    };

    function destroy(c: ClosureRow) {
        if (confirm(`Supprimer la fermeture « ${c.label} » ?`)) router.delete(`/settings/closures/${c.id}`, { preserveScroll: true });
    }

    function addHolidays(y: number) {
        router.post('/settings/closures/holidays', { year: y }, { preserveScroll: true });
    }

    return (
        <Page
            title="Fermetures"
            subtitle="Jours fériés, congés et fermetures exceptionnelles"
            actions={
                <>
                    <button type="button" className="mdash-btn ghost" onClick={() => addHolidays(year)}>
                        Jours fériés du Gabon {year}
                    </button>
                    <button type="button" className="mdash-btn ghost" onClick={() => addHolidays(year + 1)}>
                        {year + 1}
                    </button>
                </>
            }
        >
            <div className="mdash-grid">
                <Panel title="Ajouter une fermeture" className="c4">
                    <form onSubmit={submit} className="space-y-3 text-sm">
                        <label className="block">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Motif</span>
                            <input value={data.label} onChange={(e) => setData('label', e.target.value)} className={fieldClasses()} placeholder="ex. Congés annuels, Inventaire, Tabaski" />
                            {errors.label && <p className="mt-1 text-xs text-error-500">{errors.label}</p>}
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Du</span>
                                <input type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} className={fieldClasses()} />
                                {errors.starts_on && <p className="mt-1 text-xs text-error-500">{errors.starts_on}</p>}
                            </label>
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Au (inclus)</span>
                                <input type="date" value={data.ends_on} min={data.starts_on} onChange={(e) => setData('ends_on', e.target.value)} className={fieldClasses()} />
                                {errors.ends_on && <p className="mt-1 text-xs text-error-500">{errors.ends_on}</p>}
                            </label>
                        </div>
                        {stores.length > 1 && (
                            <label className="block">
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Établissement</span>
                                <select value={data.store_id} onChange={(e) => setData('store_id', e.target.value)} className={fieldClasses()}>
                                    <option value="">Tous les établissements</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <div className="flex justify-end">
                            <button type="submit" className="mdash-btn brand" disabled={processing || !data.label || !data.starts_on}>
                                {processing ? 'Enregistrement…' : 'Ajouter'}
                            </button>
                        </div>
                    </form>
                    <p className="mdash-scope">
                        Les jours fériés fixes du Gabon s'ajoutent en un clic. L'Aïd el-Fitr et la Tabaski changent chaque année : ajoutez-les ici à la main.
                    </p>
                </Panel>

                <Panel title="Fermetures à venir" className="c8">
                    {closures.length === 0 ? (
                        <Empty title="Aucune fermeture prévue">Ajoutez vos congés ou les jours fériés : aucun rendez-vous en ligne ne sera proposé ces jours-là.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Motif</th>
                                        <th>Quand</th>
                                        <th>Établissement</th>
                                        <th className="r" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {closures.map((c) => (
                                        <tr key={c.id} className={c.past ? 'opacity-50' : ''}>
                                            <td className="cell-main">
                                                <b>{c.label}</b>
                                            </td>
                                            <td className="wrap">{period(c)}</td>
                                            <td>{c.store_name ?? 'Tous'}</td>
                                            <td className="r">
                                                <span className="mpage-rowlinks">
                                                    <button type="button" className="danger" onClick={() => destroy(c)}>
                                                        Supprimer
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
            </div>
        </Page>
    );
}

ClosuresIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ClosuresIndex;
