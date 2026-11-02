import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel } from '../../components/page/Page';
import { SessionRow } from './Index';

/*
 * Appel d'une séance, pensé pour le téléphone du coach : un bouton par
 * personne (présent / absent / excusé), « Tous présents », puis Enregistrer.
 * Une séance peut être annulée (intempérie…) : les inscrits sont prévenus
 * depuis les Actions du jour.
 */

interface Participant {
    id: number;
    name: string;
    age: number | null;
    responsible: string | null;
    status: string | null;
    health: string | null;
}

interface PageProps {
    session: SessionRow & { cancel_reason: string | null; cancel_note: string | null; makeup_of: string | null };
    participants: Participant[];
    statuses: Record<string, string>;
    cancelReasons: Record<string, string>;
    [key: string]: unknown;
}

const TONE: Record<string, string> = { present: 'bg-success-500 text-white', absent: 'bg-error-500 text-white', excused: 'bg-amber-500 text-white' };

function CancelForm({ sessionId, reasons, onClose }: { sessionId: number; reasons: Record<string, string>; onClose: () => void }) {
    const [reason, setReason] = useState('weather');
    const [note, setNote] = useState('');

    return (
        <div className="space-y-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
            <label className="block">
                <span className="mb-1 block font-medium">Motif</span>
                <select className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" value={reason} onChange={(e) => setReason(e.target.value)}>
                    {Object.entries(reasons).map(([key, label]) => (
                        <option key={key} value={key}>
                            {label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="block">
                <span className="mb-1 block font-medium">Précision (facultatif)</span>
                <input className="w-full rounded-lg border border-gray-300 px-3 py-2 dark:border-gray-700 dark:bg-gray-900" value={note} onChange={(e) => setNote(e.target.value)} placeholder="ex. terrain inondé" />
            </label>
            <p className="text-xs text-gray-500">Les parents et adhérents inscrits seront à prévenir depuis « À faire » (lien WhatsApp prêt), ou par campagne si l’API est active.</p>
            <div className="flex justify-end gap-2">
                <button type="button" className="mdash-btn ghost sm" onClick={onClose}>
                    Retour
                </button>
                <button type="button" className="mdash-btn danger sm" onClick={() => router.post(`/class-sessions/${sessionId}/cancel`, { reason, note }, { preserveScroll: true, onSuccess: onClose })}>
                    Annuler la séance
                </button>
            </div>
        </div>
    );
}

function SessionPage() {
    const { session, participants, statuses, cancelReasons } = usePage<PageProps>().props;
    const [marks, setMarks] = useState<Record<number, string>>(() => Object.fromEntries(participants.filter((p) => p.status).map((p) => [p.id, p.status as string])));
    const [cancelling, setCancelling] = useState(false);
    const cancelled = session.status === 'cancelled';
    const counts = Object.values(marks).reduce<Record<string, number>>((acc, s) => ({ ...acc, [s]: (acc[s] ?? 0) + 1 }), {});

    return (
        <Page
            title={session.class_name ?? 'Séance'}
            subtitle={<span className="capitalize">{session.label}{session.is_makeup ? ' · rattrapage' : ''}</span>}
            actions={
                <Link href={`/classes/${session.class_id}`} className="mdash-btn ghost">
                    ← Le cours
                </Link>
            }
        >
            {cancelled ? (
                <Panel>
                    <p className="text-sm">
                        <span className="mdash-pill bad">Séance annulée</span> {session.cancel_reason}
                        {session.cancel_note ? ` — ${session.cancel_note}` : ''}
                    </p>
                </Panel>
            ) : (
                <Panel
                    title={`Appel · ${participants.length} inscrit(s)`}
                    sub={Object.entries(counts).map(([k, n]) => `${n} ${statuses[k].toLowerCase()}`).join(' · ') || 'Personne n’est encore pointé'}
                    actions={
                        <button type="button" className="mdash-btn ghost sm" onClick={() => setMarks(Object.fromEntries(participants.map((p) => [p.id, 'present'])))}>
                            Tous présents
                        </button>
                    }
                >
                    {participants.length === 0 ? (
                        <Empty title="Aucun inscrit">Inscrivez les participants depuis la page du cours.</Empty>
                    ) : (
                        <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                            {participants.map((p) => (
                                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
                                    <span className="text-sm">
                                        <b>{p.name}</b>
                                        <span className="block text-xs text-gray-500">{[p.age !== null ? `${p.age} ans` : null, p.responsible ? `parent : ${p.responsible}` : null].filter(Boolean).join(' · ')}</span>
                                        {p.health && <span className="block text-xs text-error-500">⚕ {p.health}</span>}
                                    </span>
                                    <span className="flex gap-1">
                                        {Object.entries(statuses).map(([key, label]) => (
                                            <button
                                                key={key}
                                                type="button"
                                                aria-pressed={marks[p.id] === key}
                                                onClick={() => setMarks({ ...marks, [p.id]: key })}
                                                className={`min-w-[74px] rounded-lg border px-3 py-2 text-sm font-medium ${marks[p.id] === key ? TONE[key] : 'border-gray-300 text-gray-700 dark:border-gray-700 dark:text-gray-300'}`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    <div className="mt-4 flex flex-wrap justify-between gap-2">
                        {cancelling ? (
                            <CancelForm sessionId={session.id} reasons={cancelReasons} onClose={() => setCancelling(false)} />
                        ) : (
                            <button type="button" className="mdash-btn ghost" onClick={() => setCancelling(true)}>
                                Annuler cette séance…
                            </button>
                        )}
                        {participants.length > 0 && (
                            <button type="button" className="mdash-btn brand" onClick={() => router.post(`/class-sessions/${session.id}/attendance`, { marks }, { preserveScroll: true })} disabled={Object.keys(marks).length === 0}>
                                Enregistrer l’appel
                            </button>
                        )}
                    </div>
                </Panel>
            )}
        </Page>
    );
}

SessionPage.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default SessionPage;
