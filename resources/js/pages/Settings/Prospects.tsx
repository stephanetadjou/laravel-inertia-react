import { useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';
import { Stage } from '../Prospects/Index';

/*
 * Paramètres › Prospects : étapes de l'entreprise (nom, étape interne,
 * délai de relance), primes des commerciaux et codes de parrainage.
 */

interface PageProps {
    stages: Stage[];
    kinds: Record<string, string>;
    commissionRate: number;
    commissionGrid: Record<string, number>;
    plans: { id: number; name: string; price: number }[];
    salesPeople: { id: number; name: string; code: string }[];
    [key: string]: unknown;
}

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function ProspectSettings() {
    const { stages, kinds, commissionRate, commissionGrid, plans, salesPeople } = usePage<PageProps>().props;
    const { data, setData, put, processing, errors } = useForm({
        stages: stages.map((s) => ({ ...s, delay_days: String(s.delay_days) })),
        commission_rate: String(commissionRate),
        commission_grid: Object.fromEntries(plans.map((p) => [p.name, commissionGrid[p.name] ? String(commissionGrid[p.name]) : ''])) as Record<string, string>,
    });
    const setStage = (i: number, patch: Partial<(typeof data.stages)[number]>) => setData('stages', data.stages.map((s, j) => (j === i ? { ...s, ...patch } : s)));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/settings/prospects', { preserveScroll: true });
    };

    return (
        <Page title="Prospects" subtitle="Vos étapes de démarchage, les délais de relance et les primes des commerciaux">
            <form onSubmit={submit} className="mdash-grid">
                <Panel title="Étapes" sub="Renommez, ajoutez ou retirez des étapes ; chacune se range sur une étape interne" className="c8">
                    <div className="space-y-2 text-sm">
                        <div className="grid grid-cols-12 gap-2 text-xs font-medium text-gray-500">
                            <span className="col-span-5">Nom affiché</span>
                            <span className="col-span-4">Étape interne</span>
                            <span className="col-span-2">Relance après (j)</span>
                        </div>
                        {data.stages.map((s, i) => (
                            <div key={i} className="grid grid-cols-12 items-center gap-2">
                                <input className={`${field} col-span-5`} value={s.label} onChange={(e) => setStage(i, { label: e.target.value })} aria-label="Nom de l’étape" />
                                <select className={`${field} col-span-4`} value={s.kind} onChange={(e) => setStage(i, { kind: e.target.value as Stage['kind'] })} aria-label="Étape interne">
                                    {Object.entries(kinds).map(([k, l]) => (
                                        <option key={k} value={k}>
                                            {l}
                                        </option>
                                    ))}
                                </select>
                                <input type="number" min={0} className={`${field} col-span-2`} value={s.delay_days} onChange={(e) => setStage(i, { delay_days: e.target.value })} aria-label="Délai de relance" disabled={s.kind === 'gagne' || s.kind === 'perdu'} />
                                <button type="button" className="mdash-btn ghost sm col-span-1" aria-label="Retirer" onClick={() => setData('stages', data.stages.filter((_, j) => j !== i))}>
                                    ×
                                </button>
                            </div>
                        ))}
                        <button type="button" className="text-xs text-brand-text hover:underline" onClick={() => setData('stages', [...data.stages, { key: '', label: '', kind: 'en_discussion', delay_days: '3' }])}>
                            + Ajouter une étape
                        </button>
                        {errors.stages && <p className="text-xs text-error-500">{errors.stages}</p>}
                        <p className="text-xs text-gray-500">
                            Une visite ou une démo honorée dans l’agenda fait passer à l’étape « Visité » / « Démo faite » si elles existent ; la première vente fait passer à l’étape « Client ».
                        </p>
                    </div>
                </Panel>

                <Panel title="Primes des commerciaux" sub="Dues au 2e paiement du client apporté" className="c4">
                    <label className="block text-sm">
                        <span className="mb-1 block font-medium">Prime (% du premier mois de la formule)</span>
                        <input type="number" min={0} max={100} className={field} value={data.commission_rate} onChange={(e) => setData('commission_rate', e.target.value)} />
                    </label>
                    {plans.length > 0 && (
                        <div className="mt-3 space-y-2 text-sm">
                            <span className="block text-xs text-gray-500">Montant fixe par formule (facultatif, prioritaire sur le %)</span>
                            {plans.map((p) => (
                                <label key={p.id} className="flex items-center gap-2">
                                    <span className="grow">{p.name}</span>
                                    <input
                                        type="number"
                                        min={0}
                                        className={`${field} w-28`}
                                        value={data.commission_grid[p.name] ?? ''}
                                        placeholder={String(Math.round((p.price * Number(data.commission_rate || 0)) / 100))}
                                        onChange={(e) => setData('commission_grid', { ...data.commission_grid, [p.name]: e.target.value })}
                                    />
                                </label>
                            ))}
                        </div>
                    )}
                </Panel>

                <Panel title="Codes commerciaux" sub="À donner au prospect : saisi à son inscription, il rattache la fiche au bon commercial" className="c12">
                    <div className="flex flex-wrap gap-2 text-sm">
                        {salesPeople.map((u) => (
                            <span key={u.id} className="rounded-lg border border-gray-200 px-3 py-1.5 dark:border-gray-700">
                                {u.name} : <b className="mono">{u.code}</b>
                            </span>
                        ))}
                    </div>
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

ProspectSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ProspectSettings;
