import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';

/*
 * Paramètres › Programmes de fidélité : un programme daté, une ou plusieurs
 * enseignes, et pour chacune ses règles. Rien n'est figé dans le code.
 */

interface Tier {
    points: number | string;
    percent: number | string;
}

interface Rule {
    brand_id: number;
    brand_name?: string | null;
    threshold: number | string;
    tranche_amount: number | string;
    points_per_tranche: number | string;
    activation_bonus: number | string;
    tiers: Tier[];
    validity_months: number | string;
    reset_after_use: boolean;
}

interface Program {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    rules: Rule[];
    active_members: number;
    expired_members: number;
    points: number;
}

interface PageProps {
    brands: { id: number; name: string }[];
    programs: Program[];
    [key: string]: unknown;
}

type FormData = { name: string; starts_on: string; ends_on: string; rules: Rule[] };

const field = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const money = (n: number | string) => `${Number(n).toLocaleString('fr-FR')} FCFA`;
const day = (d: string) => new Date(`${d}T12:00:00`).toLocaleDateString('fr-FR');

function newRule(brandId: number): Rule {
    return { brand_id: brandId, threshold: 400000, tranche_amount: 10000, points_per_tranche: 1, activation_bonus: 50, tiers: [{ points: 300, percent: 10 }], validity_months: 12, reset_after_use: true };
}

function Number_({ label, hint, value, onChange }: { label: string; hint?: string; value: number | string; onChange: (v: string) => void }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">{label}</span>
            <input type="number" min={0} value={value} onChange={(e) => onChange(e.target.value)} className={field} />
            {hint && <span className="mt-1 block text-xs text-gray-500">{hint}</span>}
        </label>
    );
}

function ProgramForm({ program, brands, onDone }: { program?: Program; brands: PageProps['brands']; onDone: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm<FormData>({
        name: program?.name ?? '',
        starts_on: program?.starts_on ?? '',
        ends_on: program?.ends_on ?? '',
        rules: program?.rules ?? [],
    });

    const setRule = (index: number, changes: Partial<Rule>) => setData('rules', data.rules.map((r, i) => (i === index ? { ...r, ...changes } : r)));
    const setTier = (index: number, tierIndex: number, changes: Partial<Tier>) =>
        setRule(index, { tiers: data.rules[index].tiers.map((t, i) => (i === tierIndex ? { ...t, ...changes } : t)) });

    const missing = brands.filter((b) => !data.rules.some((r) => r.brand_id === b.id));
    const errorList = Object.values(errors as Record<string, string>);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onDone };
        if (program) put(`/settings/loyalty-programs/${program.id}`, options);
        else post('/settings/loyalty-programs', options);
    };

    return (
        <form onSubmit={submit} className="space-y-4 text-sm">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label>
                    <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Nom du programme</span>
                    <input value={data.name} onChange={(e) => setData('name', e.target.value)} className={field} placeholder="ex. Fidélité 2026" />
                </label>
                <label>
                    <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Début</span>
                    <input type="date" value={data.starts_on} onChange={(e) => setData('starts_on', e.target.value)} className={field} />
                </label>
                <label>
                    <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Fin</span>
                    <input type="date" value={data.ends_on} onChange={(e) => setData('ends_on', e.target.value)} className={field} />
                </label>
            </div>

            {data.rules.map((rule, index) => (
                <fieldset key={rule.brand_id} className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                    <legend className="px-1 font-medium">{brands.find((b) => b.id === rule.brand_id)?.name ?? rule.brand_name}</legend>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-5">
                        <Number_ label="Seuil d’entrée (FCFA)" value={rule.threshold} onChange={(v) => setRule(index, { threshold: v })} />
                        <Number_ label="Montant d’une tranche (FCFA)" value={rule.tranche_amount} onChange={(v) => setRule(index, { tranche_amount: v })} />
                        <Number_ label="Points par tranche" value={rule.points_per_tranche} onChange={(v) => setRule(index, { points_per_tranche: v })} />
                        <Number_ label="Points offerts à l’activation" value={rule.activation_bonus} onChange={(v) => setRule(index, { activation_bonus: v })} />
                        <Number_ label="Validité (mois)" value={rule.validity_months} onChange={(v) => setRule(index, { validity_months: v })} />
                    </div>
                    <div className="mt-3">
                        <span className="mb-1 block text-xs font-medium text-gray-700 dark:text-gray-300">Paliers</span>
                        <div className="space-y-2">
                            {rule.tiers.map((tier, tierIndex) => (
                                <div key={tierIndex} className="flex items-center gap-2">
                                    <input type="number" min={1} value={tier.points} onChange={(e) => setTier(index, tierIndex, { points: e.target.value })} className={`${field} w-28`} aria-label="Points du palier" />
                                    <span className="whitespace-nowrap text-gray-500">points donnent</span>
                                    <input type="number" min={1} max={100} value={tier.percent} onChange={(e) => setTier(index, tierIndex, { percent: e.target.value })} className={`${field} w-24`} aria-label="Remise du palier" />
                                    <span className="text-gray-500">% de remise</span>
                                    {rule.tiers.length > 1 && (
                                        <button type="button" className="px-2 text-gray-400 hover:text-gray-700" aria-label="Retirer le palier" onClick={() => setRule(index, { tiers: rule.tiers.filter((_, i) => i !== tierIndex) })}>
                                            ×
                                        </button>
                                    )}
                                </div>
                            ))}
                        </div>
                        <button type="button" className="mt-2 text-sm text-brand-text underline" onClick={() => setRule(index, { tiers: [...rule.tiers, { points: Number(rule.tiers.at(-1)?.points ?? 0) + 50, percent: Number(rule.tiers.at(-1)?.percent ?? 0) + 5 }] })}>
                            + Ajouter un palier
                        </button>
                    </div>
                    <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                        <label className="flex items-center gap-2">
                            <input type="checkbox" checked={rule.reset_after_use} onChange={(e) => setRule(index, { reset_after_use: e.target.checked })} />
                            Remettre les points à zéro après utilisation de la remise
                        </label>
                        <button type="button" className="text-sm text-gray-500 underline" onClick={() => setData('rules', data.rules.filter((_, i) => i !== index))}>
                            Retirer cette enseigne
                        </button>
                    </div>
                </fieldset>
            ))}

            {missing.length > 0 && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-gray-500">Ajouter :</span>
                    {missing.map((b) => (
                        <button key={b.id} type="button" className="mdash-btn ghost sm" onClick={() => setData('rules', [...data.rules, newRule(b.id)])}>
                            + {b.name}
                        </button>
                    ))}
                </div>
            )}

            {errorList.length > 0 && <p className="text-xs text-error-500">{errorList[0]}</p>}

            <div className="flex justify-end gap-2">
                <button type="button" className="mdash-btn ghost" onClick={onDone}>
                    Annuler
                </button>
                <button type="submit" className="mdash-btn brand" disabled={processing || data.rules.length === 0}>
                    {processing ? 'Enregistrement…' : 'Enregistrer le programme'}
                </button>
            </div>
        </form>
    );
}

function ProgramCard({ program, brands }: { program: Program; brands: PageProps['brands'] }) {
    const [editing, setEditing] = useState(false);

    function remove() {
        if (confirm(`Supprimer « ${program.name} » ? Les cartes et le journal de ce programme disparaissent. Les tickets ne sont pas touchés.`)) {
            router.delete(`/settings/loyalty-programs/${program.id}`, { preserveScroll: true });
        }
    }

    if (editing) {
        return (
            <Panel title={`Modifier ${program.name}`} className="c12">
                <ProgramForm program={program} brands={brands} onDone={() => setEditing(false)} />
            </Panel>
        );
    }

    return (
        <Panel title={program.name} sub={`${day(program.starts_on)} au ${day(program.ends_on)} · ${program.active_members} actifs · ${program.expired_members} expirés · ${program.points.toLocaleString('fr-FR')} points`} className="c12">
            <div className="mdash-tbl mpage-table">
                <table>
                    <thead>
                        <tr>
                            <th>Enseigne</th>
                            <th className="r">Seuil</th>
                            <th className="r">Tranche</th>
                            <th className="r">Bonus</th>
                            <th>Paliers</th>
                            <th className="r">Validité</th>
                            <th>Après la remise</th>
                        </tr>
                    </thead>
                    <tbody>
                        {program.rules.map((r) => (
                            <tr key={r.brand_id}>
                                <td className="cell-main">
                                    <b>{r.brand_name}</b>
                                </td>
                                <td className="r mono">{money(r.threshold)}</td>
                                <td className="r mono">
                                    {money(r.tranche_amount)} = {r.points_per_tranche} pt
                                </td>
                                <td className="r mono">{r.activation_bonus} pts</td>
                                <td>{r.tiers.map((t) => `${t.points} pts → ${t.percent} %`).join(' · ')}</td>
                                <td className="r mono">{r.validity_months} mois</td>
                                <td>{r.reset_after_use ? 'Points à zéro' : 'Points gardés'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="mt-3 flex justify-end gap-2">
                <button type="button" className="mdash-btn ghost sm" onClick={remove}>
                    Supprimer
                </button>
                <button type="button" className="mdash-btn ghost sm" onClick={() => setEditing(true)}>
                    Modifier
                </button>
            </div>
        </Panel>
    );
}

function LoyaltyPrograms() {
    const { programs, brands } = usePage<PageProps>().props;
    const [creating, setCreating] = useState(false);

    return (
        <Page
            title="Programmes de fidélité"
            subtitle="Des points par enseigne, un seuil d’entrée, des paliers de remise"
            actions={
                !creating && (
                    <button type="button" className="mdash-btn brand" onClick={() => setCreating(true)}>
                        Nouveau programme
                    </button>
                )
            }
        >
            <div className="mdash-grid">
                {creating && (
                    <Panel title="Nouveau programme" className="c12">
                        <ProgramForm brands={brands} onDone={() => setCreating(false)} />
                    </Panel>
                )}
                {programs.length === 0 && !creating ? (
                    <Panel title="Aucun programme" className="c12">
                        <Empty title="Aucun programme pour l’instant">Créez un programme, choisissez ses enseignes et leurs règles. Les cartes sont calculées à partir des tickets déjà importés.</Empty>
                    </Panel>
                ) : (
                    programs.map((p) => <ProgramCard key={p.id} program={p} brands={brands} />)
                )}
            </div>
        </Page>
    );
}

LoyaltyPrograms.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LoyaltyPrograms;
