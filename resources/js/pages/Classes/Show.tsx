import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import CustomerPicker, { PickedCustomer } from '../../components/common/CustomerPicker';
import ClassFormModal, { ClassFormOptions, ClassRow } from './ClassFormModal';
import { SessionLine, SessionRow } from './Index';

/* Un cours : ses inscrits (assiduité), ses séances passées et à venir. */

interface EnrollmentRow {
    id: number;
    customer_id: number;
    name: string;
    age: number | null;
    responsible: string | null;
    plan: string | null;
    present: number;
    absent: number;
    since: string;
}

interface PageProps extends ClassFormOptions {
    groupClass: ClassRow;
    enrollments: EnrollmentRow[];
    sessions: SessionRow[];
    makeup: { cancelled: number; date: string } | null;
    [key: string]: unknown;
}

function ClassShow() {
    const props = usePage<PageProps>().props;
    const { groupClass: c, enrollments, sessions, makeup } = props;
    const [makeupDate, setMakeupDate] = useState(makeup?.date ?? '');
    const [editing, setEditing] = useState(false);
    const [adding, setAdding] = useState(false);
    const [picked, setPicked] = useState<PickedCustomer | null>(null);
    const today = new Date().toISOString().slice(0, 10);
    const upcoming = sessions.filter((s) => s.date >= today).reverse();
    const past = sessions.filter((s) => s.date < today);

    return (
        <Page
            title={c.name}
            subtitle={[c.schedule, c.store_name, c.coach].filter(Boolean).join(' · ')}
            actions={
                <>
                    <Link href="/classes" className="mdash-btn ghost">
                        ← Cours
                    </Link>
                    <button type="button" className="mdash-btn ghost" onClick={() => setEditing(true)}>
                        Modifier
                    </button>
                </>
            }
        >
            {makeup && (
                <div className="mdash-flash ok flex flex-wrap items-center gap-2">
                    <span>
                        {makeup.cancelled} séances annulées pour intempérie : proposez un rattrapage, annoncé ensuite aux mêmes personnes.
                    </span>
                    <input type="date" className="rounded-md border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900" value={makeupDate} onChange={(e) => setMakeupDate(e.target.value)} />
                    <button type="button" className="mdash-btn brand sm" onClick={() => router.post(`/classes/${c.id}/makeup`, { date: makeupDate }, { preserveScroll: true })}>
                        Programmer le rattrapage
                    </button>
                </div>
            )}

            <div className="mdash-grid">
                <Panel
                    title={`Inscrits (${enrollments.length} / ${c.capacity})`}
                    className="c8"
                    actions={
                        <button type="button" className="mdash-btn brand sm" onClick={() => setAdding(!adding)} disabled={enrollments.length >= c.capacity}>
                            <PlusIcon />
                            Inscrire
                        </button>
                    }
                >
                    {adding && (
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <div className="min-w-64 grow">
                                <CustomerPicker value={picked} onChange={setPicked} placeholder="Nom de l’adhérent ou de l’enfant" />
                            </div>
                            <button
                                type="button"
                                className="mdash-btn brand"
                                disabled={!picked}
                                onClick={() =>
                                    picked &&
                                    router.post(`/classes/${c.id}/enrollments`, { customer_id: picked.id }, { preserveScroll: true, onSuccess: () => { setPicked(null); setAdding(false); } })
                                }
                            >
                                Valider
                            </button>
                        </div>
                    )}
                    {enrollments.length === 0 ? (
                        <Empty title="Personne n’est encore inscrit">Inscrivez les participants avec « Inscrire » (un enfant se crée d’abord depuis la fiche de son parent).</Empty>
                    ) : (
                        <div className="mdash-tbl">
                            <table className="mpage-table">
                                <thead>
                                    <tr>
                                        <th>Nom</th>
                                        <th>Formule</th>
                                        <th className="num">Présences</th>
                                        <th className="num">Absences</th>
                                        <th />
                                    </tr>
                                </thead>
                                <tbody>
                                    {enrollments.map((e) => (
                                        <tr key={e.id}>
                                            <td>
                                                <Link href={`/customers/${e.customer_id}`} className="font-medium text-brand-text hover:underline">
                                                    {e.name}
                                                </Link>
                                                <span className="block text-xs text-gray-500">{[e.age !== null ? `${e.age} ans` : null, e.responsible ? `parent : ${e.responsible}` : null].filter(Boolean).join(' · ')}</span>
                                            </td>
                                            <td>{e.plan ?? <span className="text-xs text-gray-500">sans abonnement</span>}</td>
                                            <td className="num">{e.present}</td>
                                            <td className="num">{e.absent > 0 ? <span className={e.absent >= 3 ? 'text-error-500' : ''}>{e.absent}</span> : 0}</td>
                                            <td>
                                                <button
                                                    type="button"
                                                    className="mdash-btn ghost sm"
                                                    onClick={() => confirm(`Désinscrire ${e.name} de ce cours ?`) && router.delete(`/classes/${c.id}/enrollments/${e.id}`, { preserveScroll: true })}
                                                >
                                                    Désinscrire
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
                <Panel title="Séances" className="c4">
                    {upcoming.length > 0 && <h3 className="mb-1 text-xs font-semibold text-gray-500 uppercase">À venir</h3>}
                    {upcoming.map((s) => (
                        <SessionLine key={s.id} s={s} showClass={false} />
                    ))}
                    {past.length > 0 && <h3 className="mt-3 mb-1 text-xs font-semibold text-gray-500 uppercase">Passées</h3>}
                    {past.map((s) => (
                        <SessionLine key={s.id} s={s} showClass={false} />
                    ))}
                </Panel>
            </div>

            {editing && <ClassFormModal groupClass={c} options={props} onClose={() => setEditing(false)} />}
        </Page>
    );
}

ClassShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ClassShow;
