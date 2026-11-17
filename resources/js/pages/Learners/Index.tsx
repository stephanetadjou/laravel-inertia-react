import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, Seg, SelectFilter, StatStrip, formatDay, useQueryFilters } from '../../components/page/Page';
import { EnrollModal, EnrollOptions, hoursLabel, LEARNER_PILL, LearnerRow, Progress } from './shared';

/*
 * Apprenants › Suivi : chaque parcours en cours avec ses heures faites, sa
 * progression, la dernière leçon, le prochain examen et l'alerte de
 * décrochage (calculées à partir de l'agenda, des présences et des examens).
 */

interface PageProps extends EnrollOptions {
    learners: LearnerRow[];
    filters: {
        status: string;
        training: number | null;
        staff: number | null;
        alert: boolean;
        exam: boolean;
        q: string;
    };
    stats: {
        active: number;
        alerts: number | null;
        exams: number;
        completed: number;
    };
    statuses: Record<string, string>;
    [key: string]: unknown;
}

function LearnersIndex() {
    const props = usePage<PageProps>().props;
    const { learners, filters, stats, statuses } = props;
    const [enrolling, setEnrolling] = useState(false);
    const apply = useQueryFilters('/learners', filters);

    return (
        <Page
            title="Suivi des apprenants"
            subtitle="Heures faites, progression, examens et décrochage, calculés depuis l’agenda et les présences"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setEnrolling(true)}>
                    <PlusIcon /> Inscrire un apprenant
                </button>
            }
            filters={
                <>
                    <Seg
                        label="Statut"
                        value={filters.status}
                        options={[
                            ...Object.entries(statuses).map(([key, label]) => ({
                                key,
                                label,
                            })),
                            { key: 'all', label: 'Tous' },
                        ]}
                        onChange={(status) => apply({ status })}
                    />
                    <SelectFilter
                        label="Formation"
                        value={filters.training}
                        allLabel="Toutes"
                        options={props.trainingOptions.map((t) => ({
                            value: t.id,
                            label: t.name,
                        }))}
                        onChange={(training) => apply({ training })}
                    />
                    {props.staff.length > 0 && (
                        <SelectFilter
                            label="Formateur"
                            value={filters.staff}
                            allLabel="Tous"
                            options={props.staff.map((s) => ({
                                value: s.id,
                                label: s.name,
                            }))}
                            onChange={(staff) => apply({ staff })}
                        />
                    )}
                    <Seg
                        label="Afficher"
                        value={filters.alert ? 'alert' : filters.exam ? 'exam' : 'all'}
                        options={[
                            { key: 'all', label: 'Tous' },
                            { key: 'alert', label: 'Décrochage' },
                            { key: 'exam', label: 'Examen prévu' },
                        ]}
                        onChange={(k) =>
                            apply({
                                alert: k === 'alert' ? 1 : null,
                                exam: k === 'exam' ? 1 : null,
                            })
                        }
                    />
                    <SearchFilter value={filters.q} placeholder="Nom ou numéro" onSearch={(q) => apply({ q })} />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'En formation', value: stats.active },
                    ...(stats.alerts !== null
                        ? [
                              {
                                  label: 'Qui décrochent',
                                  value: stats.alerts,
                                  tone: stats.alerts > 0 ? ('bad' as const) : undefined,
                              },
                          ]
                        : []),
                    {
                        label: 'Examens (30 j)',
                        value: stats.exams,
                        tone: 'warn' as const,
                    },
                    {
                        label: 'Terminés (90 j)',
                        value: stats.completed,
                        tone: 'ok' as const,
                    },
                ]}
            />

            <Panel title={`${learners.length} parcours`}>
                {learners.length === 0 ? (
                    <Empty title="Aucun apprenant ici">Inscrivez un apprenant à une formation : ses leçons honorées et ses présences aux cours comptent automatiquement.</Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Apprenant</th>
                                    <th>Formation</th>
                                    <th>Heures</th>
                                    <th>Progression</th>
                                    <th>Dernière leçon</th>
                                    <th>Prochain examen</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {learners.map((r) => (
                                    <tr key={r.id}>
                                        <td className="cell-main">
                                            <Link href={`/learners/${r.id}`}>
                                                <b>{r.customer_name}</b>
                                            </Link>
                                            {r.staff && <span className="block text-xs text-gray-500">avec {r.staff}</span>}
                                        </td>
                                        <td className="wrap">
                                            {r.training}
                                            {r.exams_passed.length > 0 && <span className="block text-xs text-emerald-700">Réussi : {r.exams_passed.join(', ')}</span>}
                                        </td>
                                        <td className="wrap num">{hoursLabel(r)}</td>
                                        <td>
                                            <Progress percent={r.percent} alert={r.alert !== null} />
                                            {r.alert && <span className="mt-1 block text-xs text-error-600">{r.alert}</span>}
                                        </td>
                                        <td>
                                            {r.last_activity ? formatDay(r.last_activity) : '—'}
                                            {r.next_lesson && <span className="block text-xs text-gray-500">prochaine : {formatDay(r.next_lesson, true)}</span>}
                                        </td>
                                        <td>
                                            {r.next_exam ? (
                                                <>
                                                    {r.next_exam.type}
                                                    <span className="block text-xs text-gray-500">
                                                        {formatDay(r.next_exam.date)}
                                                        {r.next_exam.attempt > 1 ? ` · tentative ${r.next_exam.attempt}` : ''}
                                                    </span>
                                                </>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td>
                                            <span className={`mdash-pill ${LEARNER_PILL[r.status]}`}>{r.status_label}</span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {enrolling && <EnrollModal options={props} onClose={() => setEnrolling(false)} />}
        </Page>
    );
}

LearnersIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LearnersIndex;
