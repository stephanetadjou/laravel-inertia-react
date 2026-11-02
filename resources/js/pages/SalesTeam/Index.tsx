import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';
import { Modal } from '../../components/ui/modal';

/*
 * Clients › Objectifs : une ligne par commercial, une barre par indicateur
 * (réalisé, objectif du mois, objectif à date au prorata), taux de passage
 * et classement de l'équipe. Le directeur saisit les objectifs.
 */

interface Indicator {
    key: string;
    label: string;
    actual: number;
    target: number;
    to_date: number;
    percent: number | null;
}

interface Row {
    id: number;
    name: string;
    indicators: Indicator[];
    conversion: { visit_demo: number | null; demo_trial: number | null; trial_customer: number | null };
    score: number | null;
    behind: string[];
    commission: number;
}

interface PageProps {
    month: string;
    elapsed: number;
    board: Row[];
    indicators: Record<string, string>;
    members: { id: number; name: string; targets: Record<string, number> }[];
    canEdit: boolean;
    [key: string]: unknown;
}

const shift = (month: string, n: number) => {
    const d = new Date(`${month}-01T12:00:00`);
    d.setMonth(d.getMonth() + n);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};
const monthLabel = (month: string) => new Date(`${month}-01T12:00:00`).toLocaleDateString('fr-FR', { month: 'long', year: 'numeric' });
const value = (i: Indicator, n: number) => (i.key === 'revenue' ? `${formatNumber(n)} F` : formatNumber(n));

function Bar({ i }: { i: Indicator }) {
    const pct = i.target > 0 ? Math.min(100, (i.actual / i.target) * 100) : 0;
    const datePct = i.target > 0 ? Math.min(100, (i.to_date / i.target) * 100) : 0;
    const behind = i.target > 0 && i.actual < i.to_date;

    return (
        <div className="text-xs">
            <div className="mb-1 flex justify-between gap-2">
                <span className="font-medium text-gray-700 dark:text-gray-300">{i.label}</span>
                <span className={behind ? 'font-semibold text-error-500' : 'text-gray-600 dark:text-gray-400'}>
                    {value(i, i.actual)}
                    {i.target > 0 ? ` / ${value(i, i.target)}` : ''}
                </span>
            </div>
            <div className="relative h-2 rounded-full bg-gray-100 dark:bg-white/10" title={i.target > 0 ? `Objectif à date : ${value(i, i.to_date)}` : 'Pas d’objectif'}>
                <div className={`h-full rounded-full ${behind ? 'bg-amber-500' : 'bg-success-500'}`} style={{ width: `${pct}%` }} />
                {i.target > 0 && <span className="absolute top-[-3px] h-[14px] w-0.5 bg-gray-700 dark:bg-white" style={{ left: `${datePct}%` }} />}
            </div>
        </div>
    );
}

function TargetsModal({ month, member, indicators, onClose }: { month: string; member: PageProps['members'][number]; indicators: Record<string, string>; onClose: () => void }) {
    const { data, setData, put, processing } = useForm<Record<string, string>>({
        month,
        ...Object.fromEntries(Object.keys(indicators).map((k) => [k, String(member.targets[k] ?? 0)])),
    });
    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/sales-team/${member.id}/targets`, { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <form onSubmit={submit} className="space-y-3 text-sm">
                <h2 className="text-lg font-semibold">
                    Objectifs de {member.name} · {monthLabel(month)}
                </h2>
                {Object.entries(indicators).map(([k, label]) => (
                    <label key={k} className="flex items-center justify-between gap-3">
                        <span>{label}{k === 'revenue' ? ' (FCFA)' : ''}</span>
                        <input type="number" min={0} className="w-36 rounded-lg border border-gray-300 px-3 py-1.5 dark:border-gray-700 dark:bg-gray-900" value={data[k]} onChange={(e) => setData(k, e.target.value)} />
                    </label>
                ))}
                <div className="flex justify-end gap-2 pt-2">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        Enregistrer
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function SalesTeamIndex() {
    const { month, elapsed, board, indicators, members, canEdit } = usePage<PageProps>().props;
    const [editing, setEditing] = useState<PageProps['members'][number] | null>(null);
    const go = (m: string) => router.get('/sales-team', { month: m }, { preserveScroll: true });
    const rate = (n: number | null) => (n === null ? '—' : `${n} %`);

    return (
        <Page
            title="Objectifs de l’équipe"
            subtitle={`${monthLabel(month)} · ${elapsed} % du mois écoulé : le trait sur chaque barre marque l’objectif à date`}
            actions={
                <>
                    <button type="button" className="mdash-btn ghost" onClick={() => go(shift(month, -1))} aria-label="Mois précédent">
                        ‹
                    </button>
                    <button type="button" className="mdash-btn ghost" onClick={() => go(shift(month, 1))} aria-label="Mois suivant">
                        ›
                    </button>
                    <Link href={`/commissions?month=${month}`} className="mdash-btn ghost">
                        Primes du mois
                    </Link>
                </>
            }
        >
            {board.length === 0 ? (
                <Panel>
                    <Empty title="Aucun objectif ce mois-ci">
                        {canEdit ? 'Fixez les objectifs de chaque commercial ci-dessous : visites, démos, essais, clients payants et revenu.' : 'Votre directeur n’a pas encore fixé vos objectifs du mois.'}
                    </Empty>
                </Panel>
            ) : (
                <div className="space-y-3">
                    {board.map((row, rank) => (
                        <section key={row.id} className="mdash-panel">
                            <div className="mdash-ph">
                                <div>
                                    <h2>
                                        {board.length > 1 ? `${rank + 1}. ` : ''}
                                        {row.name}
                                    </h2>
                                    <span className="mdash-sub">
                                        {row.score !== null ? `${row.score} % des objectifs en moyenne` : 'Pas d’objectif'}
                                        {row.behind.length > 0 ? ` · en retard : ${row.behind.join(', ')}` : ''}
                                        {row.commission > 0 ? ` · primes du mois : ${formatNumber(row.commission)} F` : ''}
                                    </span>
                                </div>
                                {canEdit && (
                                    <button type="button" className="mdash-btn ghost sm" onClick={() => setEditing(members.find((m) => m.id === row.id) ?? null)}>
                                        Objectifs
                                    </button>
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                {row.indicators.map((i) => (
                                    <Bar key={i.key} i={i} />
                                ))}
                            </div>
                            <p className="mt-3 text-xs text-gray-600 dark:text-gray-400">
                                Taux de passage : visite → démo {rate(row.conversion.visit_demo)} · démo → essai {rate(row.conversion.demo_trial)} · essai → client {rate(row.conversion.trial_customer)}
                            </p>
                        </section>
                    ))}
                </div>
            )}

            {canEdit && (
                <Panel title="Fixer les objectifs" sub={monthLabel(month)}>
                    <div className="flex flex-wrap gap-2">
                        {members.map((m) => (
                            <button key={m.id} type="button" className="mdash-btn ghost sm" onClick={() => setEditing(m)}>
                                {m.name}
                            </button>
                        ))}
                    </div>
                </Panel>
            )}

            {editing && <TargetsModal month={month} member={editing} indicators={indicators} onClose={() => setEditing(null)} />}
        </Page>
    );
}

SalesTeamIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default SalesTeamIndex;
