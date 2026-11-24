import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useRef, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel, PlusIcon, Seg, formatDay } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

/*
 * Discussions › Assistant IA : la base de connaissances de l'entreprise
 * (son document), les questions à apprendre, les réglages et un essai.
 */

interface Entry {
    id: number;
    section: string;
    title: string;
    content: string;
    source: 'document' | 'human';
    active: boolean;
    indexed: boolean;
}

interface Question {
    id: number;
    question: string;
    answer: string | null;
    status: 'pending' | 'answered';
    contact: string | null;
    contact_id: number | null;
    answered_by: string | null;
    created_at: string;
}

interface Settings {
    enabled: boolean;
    use_ai: boolean;
    voice: boolean;
    unknown_reply: string;
    outcomes: string[];
}

interface PageProps {
    entries: Entry[];
    questions: Question[];
    sections: { key: string; label: string }[];
    outcomes: { key: string; label: string }[];
    settings: Settings;
    status: { ai_global: boolean; ai_allowed: boolean; embeddings: boolean; voice_global: boolean; voice_minutes: number; used: number; quota: number | null; api_enabled: boolean; booking: boolean; learned: number };
    readiness: { percent: number; checks: { key: string; label: string; done: boolean; url: string }[] };
    facts: { section: string; title: string; content: string }[];
    questionnaire: QuestionItem[];
    initialTab: string | null;
    [key: string]: unknown;
}

type Tab = 'start' | 'base' | 'questions' | 'settings';

interface QuestionItem {
    key: string;
    question: string;
    help: string | null;
    choices: string[];
    answer: { choices: string[]; text: string };
}

/** Jauge « IA prête à X % ». */
function Readiness() {
    const { readiness } = usePage<PageProps>().props;
    const tone = readiness.percent >= 80 ? 'bg-success-500' : readiness.percent >= 50 ? 'bg-warning-500' : 'bg-error-500';

    return (
        <Panel title={`IA prête à ${readiness.percent} %`} sub={readiness.percent >= 80 ? 'L’assistant peut répondre seul à vos clients' : 'Complétez ce qui manque'}>
            <div className="mb-3 h-2.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10" role="progressbar" aria-valuenow={readiness.percent} aria-valuemin={0} aria-valuemax={100}>
                <div className={`h-full rounded-full ${tone}`} style={{ width: `${readiness.percent}%` }} />
            </div>
            <ul className="space-y-1.5 text-sm">
                {readiness.checks.map((c) => (
                    <li key={c.key} className="flex items-center gap-2">
                        <span className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] ${c.done ? 'bg-success-500 text-white' : 'border border-gray-300 text-gray-400 dark:border-gray-600'}`}>
                            {c.done ? '✓' : ''}
                        </span>
                        {c.done ? (
                            <span className="text-gray-700 dark:text-gray-300">{c.label}</span>
                        ) : (
                            <a href={c.url} className="text-brand-text hover:underline">
                                {c.label}
                            </a>
                        )}
                    </li>
                ))}
            </ul>
        </Panel>
    );
}

/** Étape 2 : une question par écran, chaque réponse devient une fiche. */
function Questionnaire() {
    const { questionnaire } = usePage<PageProps>().props;
    const firstOpen = Math.max(0, questionnaire.findIndex((q) => q.answer.choices.length === 0 && q.answer.text.trim() === ''));
    const [index, setIndex] = useState(firstOpen);
    const q = questionnaire[index];
    const [choices, setChoices] = useState<string[]>(q?.answer.choices ?? []);
    const [text, setText] = useState(q?.answer.text ?? '');
    const [saving, setSaving] = useState(false);

    if (!q) return null;

    const go = (to: number) => {
        const next = questionnaire[to];
        setIndex(to);
        setChoices(next.answer.choices);
        setText(next.answer.text);
    };

    const save = (to: number | null) => {
        setSaving(true);
        router.put(
            `/assistant/questionnaire/${q.key}`,
            { choices, text },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    if (to !== null) go(to);
                },
            },
        );
    };

    const answered = questionnaire.filter((x) => x.answer.choices.length > 0 || x.answer.text.trim() !== '').length;

    return (
        <Panel title={`2. Questionnaire : question ${index + 1} sur ${questionnaire.length}`} sub={`${answered} réponse(s) enregistrée(s) · seulement ce que votre configuration ne dit pas déjà`}>
            <div className="space-y-3 text-sm">
                <p className="text-base font-semibold text-gray-800 dark:text-white/90">{q.question}</p>
                {q.help && <p className="text-xs text-gray-500">{q.help}</p>}
                {q.choices.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {q.choices.map((c) => {
                            const on = choices.includes(c);
                            return (
                                <button
                                    key={c}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => setChoices(on ? choices.filter((x) => x !== c) : [...choices, c])}
                                    className={`rounded-full border px-3 py-1.5 ${on ? 'border-brand-500 bg-brand-50 text-brand-text dark:bg-white/10' : 'border-gray-200 text-gray-700 hover:border-gray-300 dark:border-gray-700 dark:text-gray-300'}`}
                                >
                                    {on ? '✓ ' : ''}
                                    {c}
                                </button>
                            );
                        })}
                    </div>
                )}
                <textarea
                    className={input}
                    rows={3}
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    placeholder={q.choices.length > 0 ? 'Précision (facultatif)' : 'Votre réponse, comme vous la diriez au client'}
                    aria-label="Votre réponse"
                />
                <div className="flex items-center justify-between gap-2 border-t border-gray-100 pt-3 dark:border-gray-800">
                    <Button type="button" variant="outline" disabled={index === 0 || saving} onClick={() => go(index - 1)}>
                        Précédent
                    </Button>
                    <div className="flex gap-2">
                        {index < questionnaire.length - 1 && (
                            <Button type="button" variant="outline" disabled={saving} onClick={() => go(index + 1)}>
                                Passer
                            </Button>
                        )}
                        <Button type="button" disabled={saving} onClick={() => save(index < questionnaire.length - 1 ? index + 1 : null)}>
                            {saving ? 'Enregistrement…' : index < questionnaire.length - 1 ? 'Enregistrer et continuer' : 'Enregistrer'}
                        </Button>
                    </div>
                </div>
            </div>
        </Panel>
    );
}

/** Étape 1 : ce que l'assistant lit déjà dans la configuration. */
function Facts() {
    const { facts, sections } = usePage<PageProps>().props;
    const label = (key: string) => sections.find((s) => s.key === key)?.label ?? key;

    return (
        <Panel title="1. Ce que l’assistant sait déjà" sub="Lu dans votre configuration ClientlyX, toujours à jour : rien à ressaisir">
            <ul className="divide-y divide-gray-100 text-sm dark:divide-gray-800">
                {facts.map((f) => (
                    <li key={f.title} className="py-2">
                        <span className="text-[11px] tracking-wide text-gray-400 uppercase">{label(f.section)}</span>
                        <p className="text-gray-700 dark:text-gray-300">{f.content}</p>
                    </li>
                ))}
            </ul>
            <p className="mt-2 text-xs text-gray-500">Pour corriger : Catalogue (prestations et prix), Réservation en ligne (horaires), Intervenants, Fermetures.</p>
        </Panel>
    );
}

const input =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function EntryModal({ entry, defaultSection, onClose }: { entry: Entry | null; defaultSection: string; onClose: () => void }) {
    const { sections } = usePage<PageProps>().props;
    const { data, setData, post, put, processing, errors } = useForm({
        section: entry?.section ?? defaultSection,
        title: entry?.title ?? '',
        content: entry?.content ?? '',
        active: entry?.active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (entry) put(`/assistant/entries/${entry.id}`, options);
        else post('/assistant/entries', options);
    };

    return (
        <Modal isOpen onClose={onClose} className="m-4 max-w-xl p-6">
            <form onSubmit={submit} className="space-y-4 text-sm">
                <h3 className="text-lg font-semibold text-gray-800 dark:text-white/90">{entry ? 'Modifier la fiche' : 'Nouvelle fiche'}</h3>
                <label className="block">
                    <span className="mb-1.5 block font-medium text-gray-700 dark:text-gray-300">Rubrique</span>
                    <select className={input} value={data.section} onChange={(e) => setData('section', e.target.value)}>
                        {sections.map((s) => (
                            <option key={s.key} value={s.key}>
                                {s.label}
                            </option>
                        ))}
                    </select>
                </label>
                <label className="block">
                    <span className="mb-1.5 block font-medium text-gray-700 dark:text-gray-300">{data.section === 'faq' ? 'Question' : 'Titre'}</span>
                    <input className={input} value={data.title} onChange={(e) => setData('title', e.target.value)} maxLength={255} autoFocus />
                    {errors.title && <span className="mt-1 block text-xs text-error-500">{errors.title}</span>}
                </label>
                <label className="block">
                    <span className="mb-1.5 block font-medium text-gray-700 dark:text-gray-300">{data.section === 'faq' ? 'Réponse' : 'Contenu'}</span>
                    <textarea className={input} rows={6} value={data.content} onChange={(e) => setData('content', e.target.value)} maxLength={5000} />
                    {errors.content && <span className="mt-1 block text-xs text-error-500">{errors.content}</span>}
                    <span className="mt-1 block text-xs text-gray-500">Écrivez comme vous répondriez au client : c’est ce texte que l’assistant envoie.</span>
                </label>
                <label className="flex items-center gap-2">
                    <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                    Fiche utilisée par l’assistant
                </label>
                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : 'Enregistrer'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function LearnCard({ q }: { q: Question }) {
    const { data, setData, post, processing } = useForm({ title: q.question, content: q.answer ?? '', section: 'faq' });

    return (
        <div className="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
                <span>
                    {q.contact ?? 'Client'} · {formatDay(q.created_at, true)}
                </span>
                {q.status === 'pending' ? (
                    <span className="rounded-full bg-warning-50 px-2 py-0.5 font-medium text-warning-600">En attente de votre réponse au client</span>
                ) : (
                    <span className="rounded-full bg-success-50 px-2 py-0.5 font-medium text-success-600">Répondu{q.answered_by ? ` par ${q.answered_by}` : ''} : à valider</span>
                )}
            </div>
            <label className="block text-sm">
                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Question</span>
                <input className={input} value={data.title} onChange={(e) => setData('title', e.target.value)} />
            </label>
            <label className="mt-2 block text-sm">
                <span className="mb-1 block font-medium text-gray-700 dark:text-gray-300">Réponse à apprendre</span>
                <textarea
                    className={input}
                    rows={3}
                    value={data.content}
                    onChange={(e) => setData('content', e.target.value)}
                    placeholder="Répondez d’abord au client dans Discussions : votre réponse s’affichera ici. Vous pouvez aussi l’écrire directement."
                />
            </label>
            <div className="mt-3 flex flex-wrap justify-end gap-2">
                {q.contact_id && (
                    <a href={`/discussions/${q.contact_id}`} className="mdash-btn ghost">
                        Ouvrir la discussion
                    </a>
                )}
                <Button type="button" variant="outline" onClick={() => router.post(`/assistant/questions/${q.id}/dismiss`, {}, { preserveScroll: true })}>
                    Ignorer
                </Button>
                <Button
                    type="button"
                    disabled={processing || data.content.trim() === '' || data.title.trim() === ''}
                    onClick={() => post(`/assistant/questions/${q.id}/learn`, { preserveScroll: true })}
                >
                    Ajouter à la base
                </Button>
            </div>
        </div>
    );
}

function TestBox() {
    const [message, setMessage] = useState('Bonjour, quels sont vos tarifs ?');
    const [result, setResult] = useState<{ reply?: string; via?: string; matches?: { title: string; score: number }[]; error?: string } | null>(null);
    const [busy, setBusy] = useState(false);

    async function run() {
        setBusy(true);
        setResult(null);
        try {
            const response = await fetch('/assistant/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ message }),
            });
            const json = await response.json().catch(() => ({}));
            setResult(response.ok ? json : { error: json.error ?? json.message ?? 'Essai impossible.' });
        } catch {
            setResult({ error: 'Essai impossible : vérifiez votre connexion.' });
        } finally {
            setBusy(false);
        }
    }

    const via: Record<string, string> = { base: 'Réponse tirée de la base (sans IA)', ia: 'Réponse rédigée par l’IA à partir de la base', inconnu: 'Pas de réponse : la question irait dans « Questions à apprendre »' };

    return (
        <Panel title="Tester l’assistant" sub="Rien n’est envoyé ni appris">
            <div className="space-y-2">
                <textarea className={input} rows={3} value={message} onChange={(e) => setMessage(e.target.value)} aria-label="Question du client" />
                <Button type="button" variant="outline" className="w-full" disabled={busy || message.trim() === ''} onClick={run}>
                    {busy ? 'Recherche…' : 'Poser la question'}
                </Button>
                {result?.reply && (
                    <div className="rounded-xl rounded-bl-none bg-brand-50 px-3 py-2 text-sm whitespace-pre-line text-gray-800 dark:bg-brand-500/20 dark:text-white/90">
                        <span className="mb-1 block text-[11px] font-semibold text-brand-text">{via[result.via ?? ''] ?? 'Assistant'}</span>
                        {result.reply}
                    </div>
                )}
                {result?.matches && result.matches.length > 0 && (
                    <ul className="text-xs text-gray-500">
                        {result.matches.slice(0, 3).map((m) => (
                            <li key={m.title}>
                                {m.title} · {Math.round(m.score * 100)} %
                            </li>
                        ))}
                    </ul>
                )}
                {result?.error && <p className="text-xs text-error-500">{result.error}</p>}
            </div>
        </Panel>
    );
}

function SettingsPanel() {
    const { settings, outcomes, status } = usePage<PageProps>().props;
    const { data, setData, put, processing } = useForm<Settings>({ ...settings });
    const toggleOutcome = (key: string) => setData('outcomes', data.outcomes.includes(key) ? data.outcomes.filter((k) => k !== key) : [...data.outcomes, key]);

    return (
        <Panel title="Réglages">
            <form
                className="space-y-4 text-sm"
                onSubmit={(e) => {
                    e.preventDefault();
                    put('/assistant/settings', { preserveScroll: true });
                }}
            >
                <label className="flex items-start gap-2">
                    <input type="checkbox" className="mt-0.5" checked={data.enabled} onChange={(e) => setData('enabled', e.target.checked)} />
                    <span>
                        <b className="block text-gray-800 dark:text-white/90">Assistant actif sur WhatsApp</b>
                        <span className="text-xs text-gray-500">Il répond 24 h/24 à partir de la base, prend les RDV par boutons et enregistre les demandes.</span>
                    </span>
                </label>
                <label className="flex items-start gap-2">
                    <input type="checkbox" className="mt-0.5" checked={data.use_ai} onChange={(e) => setData('use_ai', e.target.checked)} />
                    <span>
                        <b className="block text-gray-800 dark:text-white/90">IA pour les questions libres (Claude Haiku)</b>
                        <span className="text-xs text-gray-500">
                            Désactivée : l’assistant répond seulement avec la fiche la plus proche, sinon il passe la main à l’équipe.
                            {!status.ai_global && ' L’IA n’est pas encore activée sur la plateforme ClientlyX.'}
                            {status.ai_global && ` ${status.used} question(s) IA ce mois${status.quota !== null ? ` sur ${status.quota}` : ''}.`}
                        </span>
                    </span>
                </label>
                <label className="flex items-start gap-2">
                    <input type="checkbox" className="mt-0.5" checked={data.voice} onChange={(e) => setData('voice', e.target.checked)} />
                    <span>
                        <b className="block text-gray-800 dark:text-white/90">Écouter les notes vocales</b>
                        <span className="text-xs text-gray-500">
                            La note est transcrite, puis l’assistant répond comme à un message écrit. Le texte apparaît dans Discussions.
                            {!status.voice_global && ' La transcription n’est pas encore activée sur la plateforme ClientlyX : le client est invité à écrire.'}
                            {status.voice_global && ` ${status.voice_minutes} minute(s) écoutée(s) ce mois.`}
                        </span>
                    </span>
                </label>
                <div>
                    <p className="mb-1.5 font-medium text-gray-700 dark:text-gray-300">Suites proposées au client</p>
                    <div className="flex flex-wrap gap-2">
                        {outcomes.map((o) => (
                            <label key={o.key} className="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 dark:border-gray-700">
                                <input type="checkbox" checked={data.outcomes.includes(o.key)} onChange={() => toggleOutcome(o.key)} />
                                {o.label}
                            </label>
                        ))}
                    </div>
                    {!status.booking && <p className="mt-1 text-xs text-gray-500">« Prendre RDV » demande la réservation en ligne ouverte (Paramètres › Réservation en ligne).</p>}
                </div>
                <label className="block">
                    <span className="mb-1.5 block font-medium text-gray-700 dark:text-gray-300">Message quand l’assistant ne sait pas</span>
                    <textarea className={input} rows={2} value={data.unknown_reply} onChange={(e) => setData('unknown_reply', e.target.value)} maxLength={500} />
                </label>
                <div className="flex justify-end">
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : 'Enregistrer'}</Button>
                </div>
            </form>
        </Panel>
    );
}

function AssistantIndex() {
    const { entries, questions, sections, status, readiness, initialTab } = usePage<PageProps>().props;
    const [tab, setTab] = useState<Tab>(
        initialTab === 'start' || initialTab === 'base' || initialTab === 'questions' || initialTab === 'settings'
            ? initialTab
            : readiness.percent < 80
              ? 'start'
              : questions.length > 0
                ? 'questions'
                : 'base',
    );
    const [modal, setModal] = useState<{ entry: Entry | null; section: string } | null>(null);
    const fileRef = useRef<HTMLInputElement>(null);
    const [replace, setReplace] = useState(false);

    function importFile(file: File) {
        router.post('/assistant/import', { file, replace }, { forceFormData: true, preserveScroll: true, onFinish: () => fileRef.current && (fileRef.current.value = '') });
    }

    return (
        <Page
            title="Assistant IA"
            subtitle="Votre assistant ClientlyX répond aux clients sur WhatsApp à partir de votre base de connaissances"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal({ entry: null, section: 'faq' })}>
                    <PlusIcon />
                    Nouvelle fiche
                </button>
            }
            filters={
                <Seg<Tab>
                    label="Afficher"
                    value={tab}
                    onChange={setTab}
                    options={[
                        { key: 'start', label: `Démarrage (${readiness.percent} %)` },
                        { key: 'base', label: `Base de connaissances (${entries.length})` },
                        { key: 'questions', label: `Questions à apprendre (${questions.length})` },
                        { key: 'settings', label: 'Réglages et essai' },
                    ]}
                />
            }
        >
            {!status.api_enabled && <div className="mdash-flash bad">L’assistant répond par l’API WhatsApp : connectez le numéro de l’établissement (Paramètres › WhatsApp).</div>}

            {tab === 'start' && (
                <div className="mdash-grid">
                    <div className="c8 flex flex-col gap-3.5">
                        <Questionnaire />
                        <Facts />
                    </div>
                    <div className="c4 flex flex-col gap-3.5">
                        <Readiness />
                        <TestBox />
                    </div>
                </div>
            )}

            {tab === 'base' && (
                <div className="mdash-grid">
                    <div className="c8 flex flex-col gap-3.5">
                        {sections.map((s) => {
                            const rows = entries.filter((e) => e.section === s.key);
                            return (
                                <Panel
                                    key={s.key}
                                    title={s.label}
                                    sub={`${rows.length} fiche(s)`}
                                    actions={
                                        <button type="button" className="mdash-btn ghost" onClick={() => setModal({ entry: null, section: s.key })}>
                                            Ajouter
                                        </button>
                                    }
                                >
                                    {s.key === 'prestations' && <p className="mb-2 text-xs text-gray-500">Les prestations du catalogue (prix, durée) et vos horaires sont déjà connus de l’assistant.</p>}
                                    {rows.length === 0 ? (
                                        <p className="text-sm text-gray-500">Aucune fiche.</p>
                                    ) : (
                                        <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                            {rows.map((e) => (
                                                <li key={e.id} className={`flex items-start gap-3 py-2.5 text-sm ${e.active ? '' : 'opacity-50'}`}>
                                                    <div className="min-w-0 flex-1">
                                                        <b className="text-gray-800 dark:text-white/90">{e.title}</b>
                                                        {e.source === 'human' && <span className="ml-2 rounded-full bg-success-50 px-2 py-0.5 text-[11px] text-success-600">appris</span>}
                                                        <p className="mt-0.5 line-clamp-2 text-gray-600 dark:text-gray-400">{e.content}</p>
                                                    </div>
                                                    <span className="mpage-rowlinks shrink-0">
                                                        <button type="button" onClick={() => setModal({ entry: e, section: e.section })}>
                                                            Modifier
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="danger"
                                                            onClick={() => confirm('Supprimer cette fiche ?') && router.delete(`/assistant/entries/${e.id}`, { preserveScroll: true })}
                                                        >
                                                            Supprimer
                                                        </button>
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Panel>
                            );
                        })}
                    </div>
                    <div className="c4 flex flex-col gap-3.5">
                        <Panel title="Importer votre document" sub="Excel ou CSV">
                            <ol className="mb-3 list-decimal space-y-1 pl-4 text-xs text-gray-600 dark:text-gray-400">
                                <li>
                                    Téléchargez le{' '}
                                    <a href="/assistant/modele" className="text-brand-text underline">
                                        modèle Excel
                                    </a>
                                    .
                                </li>
                                <li>Remplissez une ligne par fiche : rubrique, titre ou question, contenu ou réponse.</li>
                                <li>Importez-le ici.</li>
                            </ol>
                            <label className="mb-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-400">
                                <input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} />
                                Remplacer les fiches du document déjà importé (les fiches apprises sont gardées)
                            </label>
                            <input ref={fileRef} type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(e) => e.target.files?.[0] && importFile(e.target.files[0])} />
                            <Button type="button" variant="outline" className="w-full" onClick={() => fileRef.current?.click()}>
                                Choisir le fichier
                            </Button>
                        </Panel>
                        <Panel title="Où en est l’assistant ?">
                            <ul className="space-y-1.5 text-sm text-gray-700 dark:text-gray-300">
                                <li>{entries.filter((e) => e.active).length} fiche(s) active(s)</li>
                                <li>{status.learned} question(s) apprise(s) de votre équipe</li>
                                <li>{status.booking ? 'Prise de RDV par boutons : active' : 'Prise de RDV : ouvrez la réservation en ligne'}</li>
                                <li>{status.ai_allowed ? 'IA (Haiku) : active' : 'IA (Haiku) : désactivée, réponses par la base seule'}</li>
                                <li>{status.embeddings ? 'Recherche par le sens : active' : 'Recherche par les mots'}</li>
                            </ul>
                        </Panel>
                    </div>
                </div>
            )}

            {tab === 'questions' && (
                <Panel title="Questions à apprendre" sub="Questions de clients restées sans réponse de l’assistant">
                    {questions.length === 0 ? (
                        <p className="py-8 text-center text-sm text-gray-500">Aucune question en attente : l’assistant a su répondre à tout.</p>
                    ) : (
                        <div className="space-y-3">
                            <p className="text-xs text-gray-500">
                                Répondez au client dans Discussions, puis validez ici la réponse : elle entre dans la base et l’assistant saura répondre la prochaine fois.
                            </p>
                            {questions.map((q) => (
                                <LearnCard key={q.id} q={q} />
                            ))}
                        </div>
                    )}
                </Panel>
            )}

            {tab === 'settings' && (
                <div className="mdash-grid">
                    <div className="c8">
                        <SettingsPanel />
                    </div>
                    <div className="c4">
                        <TestBox />
                    </div>
                </div>
            )}

            {modal && <EntryModal key={modal.entry?.id ?? `new-${modal.section}`} entry={modal.entry} defaultSection={modal.section} onClose={() => setModal(null)} />}
        </Page>
    );
}

AssistantIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AssistantIndex;
