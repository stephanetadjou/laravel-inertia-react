import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import ClassFormModal, { ClassFormOptions, ClassRow } from './ClassFormModal';

/*
 * Agenda › Cours : les cours réguliers (jour, heure, lieu, coach, places) et
 * les séances de la semaine, avec l'appel en un geste.
 */

export interface SessionRow {
    id: number;
    class_id: number;
    class_name: string | null;
    date: string;
    label: string;
    start_time: string;
    end_time: string;
    status: 'scheduled' | 'cancelled';
    is_makeup: boolean;
    present: number;
    past: boolean;
}

interface PageProps extends ClassFormOptions {
    classes: ClassRow[];
    week: SessionRow[];
    [key: string]: unknown;
}

export function SessionLine({ s, showClass = true }: { s: SessionRow; showClass?: boolean }) {
    return (
        <Link href={`/class-sessions/${s.id}`} className="flex items-center justify-between gap-2 rounded-lg px-2 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5">
            <span>
                <b className="capitalize">{s.label}</b>
                {showClass && <span className="text-gray-500"> · {s.class_name}</span>}
                {s.is_makeup && <span className="mdash-pill info ml-1">Rattrapage</span>}
            </span>
            {s.status === 'cancelled' ? (
                <span className="mdash-pill bad">Annulée</span>
            ) : s.past ? (
                <span className="mdash-pill ok">{s.present ? `${s.present} présent(s)` : 'Faire l’appel'}</span>
            ) : (
                <span className="mdash-pill idle">À venir</span>
            )}
        </Link>
    );
}

function ClassesIndex() {
    const props = usePage<PageProps>().props;
    const { classes, week } = props;
    const [editing, setEditing] = useState<ClassRow | null | undefined>(undefined);

    return (
        <Page
            title="Cours"
            subtitle="Vos cours réguliers, les inscrits et l’appel"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setEditing(null)}>
                    <PlusIcon />
                    Nouveau cours
                </button>
            }
        >
            <div className="mdash-grid">
                <Panel title="Cours réguliers" className="c8">
                    {classes.length === 0 ? (
                        <Empty title="Aucun cours pour l’instant">
                            Créez votre premier cours (ex. « Natation enfants, mercredi 15h-16h, piscine Akaé ») : les séances apparaissent ensuite dans l’agenda, prêtes pour l’appel.
                        </Empty>
                    ) : (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            {classes.map((c) => (
                                <Link key={c.id} href={`/classes/${c.id}`} className={`rounded-xl border p-3 text-sm hover:border-brand-300 ${c.active ? 'border-gray-200 dark:border-gray-700' : 'border-dashed border-gray-300 opacity-60'}`}>
                                    <b className="block">{c.name}</b>
                                    <span className="block text-gray-600 dark:text-gray-300">{c.schedule}</span>
                                    <span className="block text-xs text-gray-500">{[c.store_name, c.coach, c.activity].filter(Boolean).join(' · ')}</span>
                                    <span className={`mt-2 inline-block mdash-pill ${c.enrolled >= c.capacity ? 'warn' : 'idle'}`}>
                                        {c.enrolled} / {c.capacity} inscrits
                                    </span>
                                    {!c.active && <span className="mdash-pill idle ml-1">Arrêté</span>}
                                </Link>
                            ))}
                        </div>
                    )}
                </Panel>
                <Panel title="Séances de la semaine" sub="Touchez une séance pour faire l’appel" className="c4">
                    {week.length === 0 ? <p className="text-sm text-gray-500">Aucune séance cette semaine.</p> : week.map((s) => <SessionLine key={s.id} s={s} />)}
                </Panel>
            </div>

            {editing !== undefined && <ClassFormModal groupClass={editing} options={props} onClose={() => setEditing(undefined)} />}
        </Page>
    );
}

ClassesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ClassesIndex;
