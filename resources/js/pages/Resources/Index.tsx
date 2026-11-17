import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';

/*
 * Agenda › Véhicules et salles : ce qu'on réserve avec un rendez-vous
 * (véhicule de leçon, salle, matériel). L'agenda refuse deux rendez-vous
 * qui se chevauchent sur la même ressource.
 */

interface ResourceRow {
    id: number;
    name: string;
    kind: string;
    kind_label: string;
    reference: string | null;
    store_id: number | null;
    store: string | null;
    active: boolean;
    past_hours: number;
    next_hours: number;
}

interface PageProps {
    resources: ResourceRow[];
    kinds: Record<string, string>;
    stores: { id: number; name: string }[];
    [key: string]: unknown;
}

const field =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function ResourcesIndex() {
    const { resources, kinds, stores } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        kind: 'vehicule',
        reference: '',
        store_id: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/resources', {
            preserveScroll: true,
            onSuccess: () => reset('name', 'reference'),
        });
    };

    const toggle = (r: ResourceRow) =>
        router.put(
            `/resources/${r.id}`,
            {
                name: r.name,
                kind: r.kind,
                reference: r.reference,
                store_id: r.store_id,
                active: !r.active,
            },
            { preserveScroll: true },
        );

    return (
        <Page title="Véhicules et salles" subtitle="Ressources réservées avec un rendez-vous : jamais deux leçons en même temps sur le même véhicule">
            <div className="mdash-grid">
                <Panel title="Ajouter" className="c4">
                    <form onSubmit={submit} className="space-y-3 text-sm">
                        <label className="block">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Nom</span>
                            <input className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="ex. Peugeot 208, Salle de code" />
                            {errors.name && <p className="mt-1 text-xs text-error-500">{errors.name}</p>}
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Type</span>
                                <select className={field} value={data.kind} onChange={(e) => setData('kind', e.target.value)}>
                                    {Object.entries(kinds).map(([k, v]) => (
                                        <option key={k} value={k}>
                                            {v}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Immatriculation / réf.</span>
                                <input className={field} value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                            </label>
                        </div>
                        {stores.length > 1 && (
                            <label className="block">
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Établissement</span>
                                <select className={field} value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                    <option value="">Tous</option>
                                    {stores.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <div className="flex justify-end">
                            <button type="submit" className="mdash-btn brand" disabled={processing || !data.name}>
                                Ajouter
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Ressources" sub="Heures réservées sur 7 jours" className="c8">
                    {resources.length === 0 ? (
                        <Empty title="Aucune ressource">Ajoutez vos véhicules ou vos salles : vous les choisirez en réservant une leçon dans l’agenda.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Ressource</th>
                                        <th>Type</th>
                                        <th>Établissement</th>
                                        <th className="r">7 derniers jours</th>
                                        <th className="r">7 prochains jours</th>
                                        <th className="r" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {resources.map((r) => (
                                        <tr key={r.id} className={r.active ? '' : 'opacity-50'}>
                                            <td className="cell-main">
                                                <b>{r.name}</b>
                                                {r.reference && <span className="block text-xs text-gray-500">{r.reference}</span>}
                                            </td>
                                            <td>{r.kind_label}</td>
                                            <td>{r.store ?? 'Tous'}</td>
                                            <td className="r num">{r.past_hours} h</td>
                                            <td className="r num">{r.next_hours} h</td>
                                            <td className="r">
                                                <span className="mpage-rowlinks">
                                                    <button type="button" onClick={() => toggle(r)}>
                                                        {r.active ? 'Mettre hors service' : 'Remettre en service'}
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

ResourcesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ResourcesIndex;
