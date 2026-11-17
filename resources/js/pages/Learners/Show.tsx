import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { APPOINTMENT_PILL } from '../../components/appointments/AppointmentFormModal';
import { Empty, Page, Panel, StatStrip, formatDay } from '../../components/page/Page';
import { EXAM_PILL, ExamRow, field, hoursLabel, LEARNER_PILL, LearnerRow, Progress } from './shared';

/*
 * Parcours d'un apprenant : progression, leçons (agenda), examens et
 * paiements de sa formule. Les heures se comptent toutes seules à partir
 * des leçons honorées ; on ne saisit ici que les examens et le statut.
 */

interface Lesson {
    id: number;
    starts_at: string;
    duration_minutes: number;
    service: string | null;
    status: string;
    status_label: string;
    staff: string | null;
    resource: string | null;
    notes: string | null;
}

interface PageProps {
    learner: LearnerRow & {
        notes: string | null;
        end_reason: string | null;
        ended_on: string | null;
        extra_hours: number;
        hours_planned: number;
        customer_phone: string | null;
        subscription: {
            plan: string | null;
            total: number;
            paid: number;
            overdue: number;
            schedule_id: number | null;
        } | null;
    };
    lessons: Lesson[];
    exams: ExamRow[];
    examTypes: string[];
    results: Record<string, string>;
    statuses: Record<string, string>;
    staff: { id: number; name: string }[];
    [key: string]: unknown;
}

const money = (n: number) => `${n.toLocaleString('fr-FR')} FCFA`;

function LearnerShow() {
    const { learner: l, lessons, exams, examTypes, results, statuses, staff } = usePage<PageProps>().props;

    const status = useForm({
        status: l.status as string,
        staff_member_id: l.staff_member_id ? String(l.staff_member_id) : '',
        extra_hours: String(l.extra_hours),
        target_on: l.target_on ?? '',
        end_reason: l.end_reason ?? '',
        notes: l.notes ?? '',
    });
    const exam = useForm({
        type: examTypes[0] ?? '',
        exam_on: '',
        exam_time: '',
        result: 'scheduled',
        score: '',
        notes: '',
    });

    const saveStatus: FormEventHandler = (e) => {
        e.preventDefault();
        status.put(`/learners/${l.id}`, { preserveScroll: true });
    };
    const addExam: FormEventHandler = (e) => {
        e.preventDefault();
        exam.post(`/learners/${l.id}/exams`, {
            preserveScroll: true,
            onSuccess: () => exam.reset('exam_on', 'exam_time', 'score', 'notes'),
        });
    };
    const setResult = (e: ExamRow, result: string) => router.put(`/learner-exams/${e.id}`, { result, score: e.score, notes: e.notes }, { preserveScroll: true });

    return (
        <Page
            title={l.customer_name ?? 'Apprenant'}
            subtitle={`${l.training} · depuis le ${formatDay(l.started_on)}${l.target_on ? ` · objectif ${formatDay(l.target_on)}` : ''}`}
            actions={
                <>
                    <Link href={`/customers/${l.customer_id}`} className="mdash-btn ghost">
                        Fiche
                    </Link>
                    <Link href={`/appointments?date=${new Date().toISOString().slice(0, 10)}`} className="mdash-btn brand">
                        Réserver une leçon
                    </Link>
                </>
            }
        >
            {l.alert && <div className="mdash-flash bad">{l.alert}</div>}

            <StatStrip
                items={[
                    {
                        label: 'Progression',
                        value: `${l.percent} %`,
                        tone: l.alert ? 'warn' : 'ok',
                    },
                    {
                        label: 'Heures faites',
                        value: hoursLabel(l),
                        hint: l.hours_booked > 0 ? `${l.hours_booked} h réservées` : undefined,
                    },
                    {
                        label: 'Leçons honorées',
                        value: l.lessons_done,
                        hint: l.no_shows > 0 ? `${l.no_shows} absence(s)` : undefined,
                        tone: l.no_shows > 1 ? 'bad' : undefined,
                    },
                    {
                        label: 'Prochain examen',
                        value: l.next_exam ? l.next_exam.type : '—',
                        hint: l.next_exam ? formatDay(l.next_exam.date) : l.exams_passed.length ? `Réussi : ${l.exams_passed.join(', ')}` : undefined,
                    },
                    ...(l.subscription
                        ? [
                              {
                                  label: 'Payé',
                                  value: `${money(l.subscription.paid)}`,
                                  hint: `sur ${money(l.subscription.total)}${l.subscription.overdue ? ` · ${l.subscription.overdue} en retard` : ''}`,
                                  tone: l.subscription.overdue ? ('bad' as const) : undefined,
                              },
                          ]
                        : []),
                ]}
            />

            <div className="mdash-grid">
                <Panel title="Leçons" sub="Rendez-vous de l’agenda comptés dans ce parcours" className="c8">
                    {lessons.length === 0 ? (
                        <Empty title="Aucune leçon pour l’instant">Réservez une leçon dans l’agenda pour cet apprenant : elle se rattache toute seule à son parcours.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Leçon</th>
                                        <th>Formateur</th>
                                        <th>Véhicule / salle</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {lessons.map((a) => (
                                        <tr key={a.id}>
                                            <td>{formatDay(a.starts_at, true)}</td>
                                            <td className="wrap">
                                                {a.service ?? 'Leçon'} · {a.duration_minutes} min
                                                {a.notes && <span className="block text-xs text-gray-500">{a.notes}</span>}
                                            </td>
                                            <td>{a.staff ?? '—'}</td>
                                            <td>{a.resource ?? '—'}</td>
                                            <td>
                                                <span className={`mdash-pill ${APPOINTMENT_PILL[a.status] ?? 'idle'}`}>{a.status_label}</span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel title="Parcours" className="c4">
                    <div className="mb-3 flex items-center justify-between">
                        <Progress percent={l.percent} alert={l.alert !== null} />
                        <span className={`mdash-pill ${LEARNER_PILL[l.status]}`}>{l.status_label}</span>
                    </div>
                    <form onSubmit={saveStatus} className="space-y-3 text-sm">
                        <label className="block">
                            <span className="mb-1 block font-medium">Statut</span>
                            <select className={field} value={status.data.status} onChange={(e) => status.setData('status', e.target.value)}>
                                {Object.entries(statuses).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {(status.data.status === 'dropped' || status.data.status === 'completed') && (
                            <label className="block">
                                <span className="mb-1 block font-medium">Motif</span>
                                <input
                                    className={field}
                                    value={status.data.end_reason}
                                    onChange={(e) => status.setData('end_reason', e.target.value)}
                                    placeholder="ex. Déménagement, permis obtenu"
                                />
                            </label>
                        )}
                        <label className="block">
                            <span className="mb-1 block font-medium">Formateur référent</span>
                            <select className={field} value={status.data.staff_member_id} onChange={(e) => status.setData('staff_member_id', e.target.value)}>
                                <option value="">Aucun</option>
                                {staff.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <div className="grid grid-cols-2 gap-2">
                            {l.hours_planned > 0 && (
                                <label>
                                    <span className="mb-1 block font-medium">Heures en plus</span>
                                    <input
                                        type="number"
                                        min={0}
                                        className={field}
                                        value={status.data.extra_hours}
                                        onChange={(e) => status.setData('extra_hours', e.target.value)}
                                    />
                                </label>
                            )}
                            <label>
                                <span className="mb-1 block font-medium">Objectif</span>
                                <input type="date" className={field} value={status.data.target_on} onChange={(e) => status.setData('target_on', e.target.value)} />
                            </label>
                        </div>
                        <label className="block">
                            <span className="mb-1 block font-medium">Note</span>
                            <textarea className={field} rows={2} value={status.data.notes} onChange={(e) => status.setData('notes', e.target.value)} />
                        </label>
                        <div className="flex justify-end">
                            <button type="submit" className="mdash-btn brand" disabled={status.processing}>
                                Enregistrer
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Examens" sub="Une nouvelle date après un échec compte comme une nouvelle tentative" className="c12">
                    {exams.length > 0 && (
                        <div className="mdash-tbl mpage-table mb-4">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Examen</th>
                                        <th>Date</th>
                                        <th>Tentative</th>
                                        <th>Résultat</th>
                                        <th className="r" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {exams.map((e) => (
                                        <tr key={e.id}>
                                            <td className="cell-main">
                                                <b>{e.type}</b>
                                                {e.notes && <span className="block text-xs text-gray-500">{e.notes}</span>}
                                            </td>
                                            <td>
                                                {formatDay(e.exam_on)}
                                                {e.exam_time ? ` · ${e.exam_time}` : ''}
                                            </td>
                                            <td className="num">{e.attempt}</td>
                                            <td>
                                                <span className={`mdash-pill ${EXAM_PILL[e.result]}`}>{e.result_label}</span>
                                                {e.score && <span className="ml-1 text-xs text-gray-500">{e.score}</span>}
                                            </td>
                                            <td className="r">
                                                {e.result === 'scheduled' && (
                                                    <span className="mpage-rowlinks">
                                                        <button type="button" onClick={() => setResult(e, 'passed')}>
                                                            Réussi
                                                        </button>
                                                        <button type="button" onClick={() => setResult(e, 'failed')}>
                                                            Échoué
                                                        </button>
                                                        <button type="button" onClick={() => setResult(e, 'absent')}>
                                                            Absent
                                                        </button>
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <form onSubmit={addExam} className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-6 sm:items-end">
                        <label className="sm:col-span-2">
                            <span className="mb-1 block font-medium">Examen</span>
                            {examTypes.length > 0 ? (
                                <select className={field} value={exam.data.type} onChange={(e) => exam.setData('type', e.target.value)}>
                                    {examTypes.map((t) => (
                                        <option key={t} value={t}>
                                            {t}
                                        </option>
                                    ))}
                                </select>
                            ) : (
                                <input className={field} value={exam.data.type} onChange={(e) => exam.setData('type', e.target.value)} placeholder="ex. Examen final" />
                            )}
                        </label>
                        <label>
                            <span className="mb-1 block font-medium">Date</span>
                            <input type="date" className={field} value={exam.data.exam_on} onChange={(e) => exam.setData('exam_on', e.target.value)} />
                            {exam.errors.exam_on && <span className="text-xs text-error-500">{exam.errors.exam_on}</span>}
                        </label>
                        <label>
                            <span className="mb-1 block font-medium">Heure</span>
                            <input type="time" className={field} value={exam.data.exam_time} onChange={(e) => exam.setData('exam_time', e.target.value)} />
                        </label>
                        <label>
                            <span className="mb-1 block font-medium">Résultat</span>
                            <select className={field} value={exam.data.result} onChange={(e) => exam.setData('result', e.target.value)}>
                                {Object.entries(results).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <button type="submit" className="mdash-btn brand" disabled={exam.processing || !exam.data.type || !exam.data.exam_on}>
                            Ajouter
                        </button>
                    </form>
                </Panel>
            </div>
        </Page>
    );
}

LearnerShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default LearnerShow;
