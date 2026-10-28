import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, StatStrip } from '../../components/page/Page';

/*
 * Paramètres › Fidélité : points par FCFA dépensé, paliers et récompenses.
 * Les points s'échangent depuis la fiche du client.
 */

interface Tier {
    name: string;
    min_points: number;
}

interface Reward {
    id: number;
    name: string;
    points_cost: number;
    active: boolean;
}

interface PageProps {
    settings: { enabled: boolean; fcfa_per_point: number; starts_on: string | null; tiers: Tier[] };
    rewards: Reward[];
    stats: { members: number; points_out: number; redeemed: number };
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function RewardRow({ reward }: { reward: Reward }) {
    const [editing, setEditing] = useState(false);
    const { data, setData, put, processing } = useForm({ name: reward.name, points_cost: String(reward.points_cost) });

    if (editing) {
        return (
            <tr>
                <td>
                    <input value={data.name} onChange={(e) => setData('name', e.target.value)} className={fieldClasses()} aria-label="Nom de la récompense" />
                </td>
                <td className="r">
                    <input type="number" min={1} value={data.points_cost} onChange={(e) => setData('points_cost', e.target.value)} className={fieldClasses()} aria-label="Points" />
                </td>
                <td className="r">
                    <span className="mpage-rowlinks">
                        <button type="button" disabled={processing} onClick={() => put(`/settings/loyalty/rewards/${reward.id}`, { preserveScroll: true, onSuccess: () => setEditing(false) })}>
                            Enregistrer
                        </button>
                        <button type="button" onClick={() => setEditing(false)}>
                            Annuler
                        </button>
                    </span>
                </td>
            </tr>
        );
    }

    return (
        <tr className={reward.active ? '' : 'opacity-50'}>
            <td className="cell-main">
                <b>{reward.name}</b>
                {!reward.active && <span>retirée</span>}
            </td>
            <td className="r mono">{reward.points_cost.toLocaleString('fr-FR')} pts</td>
            <td className="r">
                <span className="mpage-rowlinks">
                    <button type="button" onClick={() => setEditing(true)}>
                        Modifier
                    </button>
                    <button type="button" className={reward.active ? 'danger' : ''} onClick={() => router.post(`/settings/loyalty/rewards/${reward.id}/toggle`, {}, { preserveScroll: true })}>
                        {reward.active ? 'Retirer' : 'Remettre'}
                    </button>
                </span>
            </td>
        </tr>
    );
}

function LoyaltySettings() {
    const { settings, rewards, stats } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors } = useForm({
        enabled: settings.enabled,
        fcfa_per_point: String(settings.fcfa_per_point),
        starts_on: settings.starts_on ?? '',
        tiers: settings.tiers,
    });
    const reward = useForm({ name: '', points_cost: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/loyalty', { preserveScroll: true });
    };

    const addReward: FormEventHandler = (e) => {
        e.preventDefault();
        reward.post('/settings/loyalty/rewards', { preserveScroll: true, onSuccess: () => reward.reset() });
    };

    function setTier(index: number, changes: Partial<Tier>) {
        setData('tiers', data.tiers.map((t, i) => (i === index ? { ...t, ...changes } : t)));
    }

    const example = Math.floor(25000 / Math.max(1, Number(data.fcfa_per_point) || 1));

    return (
        <Page title="Fidélité" subtitle="Des points à chaque achat, des paliers et des récompenses">
            <StatStrip
                items={[
                    { label: 'Clients avec des points', value: stats.members },
                    { label: 'Points en circulation', value: stats.points_out },
                    { label: 'Récompenses échangées', value: stats.redeemed },
                ]}
            />
            <div className="mdash-grid">
                <Panel title="Règles" sub={settings.enabled ? 'Programme actif' : 'Programme désactivé'} className="c6">
                    <form onSubmit={submit} className="space-y-4 text-sm">
                        <label className="flex items-center gap-2 font-medium">
                            <input type="checkbox" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                            Programme de fidélité actif
                        </label>
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">1 point tous les</span>
                                <span className="flex items-center gap-2">
                                    <input type="number" min={1} step={100} value={data.fcfa_per_point} onChange={(e) => setData('fcfa_per_point', e.target.value)} className={fieldClasses()} />
                                    <span className="text-gray-500">FCFA</span>
                                </span>
                                <span className="mt-1 block text-xs text-gray-500">ex. un achat de 25 000 FCFA rapporte {example} points</span>
                                {errors.fcfa_per_point && <p className="mt-1 text-xs text-error-500">{errors.fcfa_per_point}</p>}
                            </label>
                            <label>
                                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Achats comptés à partir du</span>
                                <input type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} className={fieldClasses()} />
                                <span className="mt-1 block text-xs text-gray-500">vide : le jour de l'activation</span>
                            </label>
                        </div>
                        <fieldset>
                            <legend className="mb-1.5 font-medium text-gray-700 dark:text-gray-300">Paliers (points gagnés sur 12 mois)</legend>
                            <div className="space-y-2">
                                {data.tiers.map((t, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <input value={t.name} onChange={(e) => setTier(i, { name: e.target.value })} className={fieldClasses()} aria-label="Nom du palier" />
                                        <span className="whitespace-nowrap text-gray-500">dès</span>
                                        <input type="number" min={0} value={t.min_points} onChange={(e) => setTier(i, { min_points: Number(e.target.value) })} className={`${fieldClasses()} w-28`} aria-label="Points minimum" />
                                        <span className="text-gray-500">pts</span>
                                        {data.tiers.length > 1 && (
                                            <button type="button" className="px-2 text-gray-400 hover:text-gray-700" aria-label="Retirer le palier" onClick={() => setData('tiers', data.tiers.filter((_, j) => j !== i))}>
                                                ×
                                            </button>
                                        )}
                                    </div>
                                ))}
                            </div>
                            {data.tiers.length < 6 && (
                                <button type="button" className="mt-2 text-sm text-brand-text underline" onClick={() => setData('tiers', [...data.tiers, { name: '', min_points: (data.tiers.at(-1)?.min_points ?? 0) + 500 }])}>
                                    + Ajouter un palier
                                </button>
                            )}
                        </fieldset>
                        <div className="flex justify-end">
                            <button type="submit" className="mdash-btn brand" disabled={processing}>
                                {processing ? 'Enregistrement…' : 'Enregistrer'}
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Récompenses" sub="Échangées depuis la fiche du client" className="c6">
                    <form onSubmit={addReward} className="mb-4 flex flex-wrap items-end gap-2 text-sm">
                        <label className="min-w-0 flex-1">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Récompense</span>
                            <input value={reward.data.name} onChange={(e) => reward.setData('name', e.target.value)} className={fieldClasses()} placeholder="ex. Brushing offert, -10 % sur la prochaine visite" />
                        </label>
                        <label className="w-28">
                            <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Points</span>
                            <input type="number" min={1} value={reward.data.points_cost} onChange={(e) => reward.setData('points_cost', e.target.value)} className={fieldClasses()} />
                        </label>
                        <button type="submit" className="mdash-btn ghost" disabled={reward.processing || !reward.data.name || !reward.data.points_cost}>
                            Ajouter
                        </button>
                    </form>
                    {rewards.length === 0 ? (
                        <Empty title="Aucune récompense">Ajoutez ce que vos clients peuvent obtenir avec leurs points.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Récompense</th>
                                        <th className="r">Coût</th>
                                        <th className="r" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {rewards.map((r) => (
                                        <RewardRow key={r.id} reward={r} />
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

LoyaltySettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LoyaltySettings;
