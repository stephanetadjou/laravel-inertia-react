import { Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, ReactNode, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { RobotIcon } from './Index';

/*
 * Création / modification d'un agent IA en 5 étapes (Persona, Modèle,
 * Connaissances, Sécurité, Paramètres), avec à droite la liste de
 * vérification et un essai sans envoi.
 */

interface Provider {
    key: string;
    label: string;
    models: string[];
    key_hint: string;
    console: string;
}

interface AgentData {
    id: number;
    name: string;
    role: string | null;
    tone: string;
    instructions: string | null;
    provider: string;
    model: string;
    use_business_info: boolean;
    knowledge: string | null;
    forbidden_topics: string | null;
    handoff_message: string | null;
    max_replies_per_day: number;
    store_ids: number[];
    schedule: string;
    active: boolean;
    masked_key: string;
}

interface PageProps {
    agent: AgentData | null;
    providers: Provider[];
    tones: { key: string; label: string }[];
    schedules: { key: string; label: string }[];
    stores: { id: number; name: string }[];
    defaultHandoff: string;
    hasCatalog: boolean;
    apiEnabled: boolean;
    [key: string]: unknown;
}

const STEPS = [
    { key: 'persona', label: 'Persona', hint: 'Nom, rôle et ton' },
    { key: 'model', label: 'Modèle', hint: 'Fournisseur IA et clé' },
    { key: 'knowledge', label: 'Connaissances', hint: 'Ce que l’agent sait' },
    { key: 'safety', label: 'Sécurité', hint: 'Limites et relais humain' },
    { key: 'settings', label: 'Paramètres', hint: 'Où et quand il répond' },
] as const;

const STEP_FIELDS: Record<string, string[]> = {
    persona: ['name', 'role', 'tone', 'instructions'],
    model: ['provider', 'model', 'api_key'],
    knowledge: ['knowledge', 'use_business_info'],
    safety: ['forbidden_topics', 'handoff_message', 'max_replies_per_day'],
    settings: ['store_ids', 'schedule', 'active'],
};

const input =
    'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';

function Field({ label, hint, error, children }: { label: string; hint?: ReactNode; error?: string; children: ReactNode }) {
    return (
        <label className="block text-sm">
            <span className="mb-1.5 block font-medium text-gray-700 dark:text-gray-300">{label}</span>
            {children}
            {hint && !error && <span className="mt-1 block text-xs text-gray-500 dark:text-gray-400">{hint}</span>}
            {error && <span className="mt-1 block text-xs text-error-500">{error}</span>}
        </label>
    );
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function AiAgentForm() {
    const { agent, providers, tones, schedules, stores, defaultHandoff, hasCatalog, apiEnabled } = usePage<PageProps>().props;
    const [step, setStep] = useState(0);
    const [testMessage, setTestMessage] = useState('Bonjour, quels sont vos horaires et vos prix ?');
    const [testResult, setTestResult] = useState<{ reply?: string; error?: string } | null>(null);
    const [testing, setTesting] = useState(false);

    const { data, setData, post, put, processing, errors } = useForm({
        name: agent?.name ?? '',
        role: agent?.role ?? 'Conseiller clientèle',
        tone: agent?.tone ?? 'chaleureux',
        instructions: agent?.instructions ?? '',
        provider: agent?.provider ?? providers[0].key,
        model: agent?.model ?? providers[0].models[0],
        api_key: '',
        use_business_info: agent?.use_business_info ?? true,
        knowledge: agent?.knowledge ?? '',
        forbidden_topics: agent?.forbidden_topics ?? '',
        handoff_message: agent?.handoff_message ?? '',
        max_replies_per_day: agent?.max_replies_per_day ?? 20,
        store_ids: agent?.store_ids ?? ([] as number[]),
        schedule: agent?.schedule ?? 'always',
        active: agent?.active ?? true,
    });

    const provider = providers.find((p) => p.key === data.provider) ?? providers[0];
    const hasKey = data.api_key.trim() !== '' || !!agent;

    const checks = [
        { label: 'Nom de l’agent', done: data.name.trim() !== '', step: 0 },
        { label: 'Fournisseur et modèle choisis', done: data.model.trim() !== '', step: 1 },
        { label: 'Clé API saisie', done: hasKey, step: 1 },
        { label: 'Connaissances ajoutées', done: data.knowledge.trim() !== '' || data.use_business_info, step: 2 },
        { label: 'Relais vers l’équipe prévu', done: true, step: 3 },
        { label: 'Établissements et horaires', done: true, step: 4 },
        { label: 'API WhatsApp connectée', done: apiEnabled, step: 4 },
    ];
    const ready = checks.filter((c) => c.done).length;

    const stepHasError = (index: number) => STEP_FIELDS[STEPS[index].key].some((f) => Object.keys(errors).some((e) => e === f || e.startsWith(f + '.')));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onError: (errs: Record<string, string>) => {
                const first = STEPS.findIndex((s) => STEP_FIELDS[s.key].some((f) => Object.keys(errs).some((k) => k === f || k.startsWith(f + '.'))));
                if (first >= 0) setStep(first);
            },
        };
        if (agent) put(`/ai-agents/${agent.id}`, options);
        else post('/ai-agents', options);
    };

    async function runTest() {
        setTesting(true);
        setTestResult(null);
        try {
            const response = await fetch('/ai-agents/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ ...data, agent_id: agent?.id, message: testMessage }),
            });
            const json = await response.json().catch(() => ({}));
            if (response.ok) setTestResult({ reply: json.reply });
            else if (response.status === 429) setTestResult({ error: 'Trop d’essais : patientez une minute.' });
            else setTestResult({ error: json.error ?? json.message ?? 'Essai impossible.' });
        } catch {
            setTestResult({ error: 'Essai impossible : vérifiez votre connexion.' });
        } finally {
            setTesting(false);
        }
    }

    const toggleStore = (id: number) => setData('store_ids', data.store_ids.includes(id) ? data.store_ids.filter((s) => s !== id) : [...data.store_ids, id]);

    return (
        <Page
            title={agent ? `Agent « ${agent.name} »` : 'Nouvel agent IA'}
            subtitle="Configurez votre assistant en 5 étapes : il répondra à vos clients sur WhatsApp"
            actions={
                <Link href="/ai-agents" className="mdash-btn ghost">
                    Retour aux agents
                </Link>
            }
        >
            <form onSubmit={submit} className="mdash-grid">
                <div className="c8 flex flex-col gap-3.5">
                    {/* Étapes */}
                    <nav aria-label="Étapes" className="mdash-panel grid grid-cols-5 gap-1 p-2">
                        {STEPS.map((s, i) => (
                            <button
                                key={s.key}
                                type="button"
                                aria-current={step === i ? 'step' : undefined}
                                onClick={() => setStep(i)}
                                className={`flex flex-col items-center gap-1 rounded-lg px-1 py-2 text-center text-xs transition-colors sm:flex-row sm:text-left ${
                                    step === i ? 'bg-brand-50 text-brand-text dark:bg-white/10 dark:text-white' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5'
                                }`}
                            >
                                <span
                                    className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold ${
                                        stepHasError(i) ? 'bg-error-500 text-white' : i < step ? 'bg-success-500 text-white' : step === i ? 'bg-brand-500 text-white' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-300'
                                    }`}
                                >
                                    {i < step && !stepHasError(i) ? '✓' : i + 1}
                                </span>
                                <span className="min-w-0">
                                    <b className="block font-semibold">{s.label}</b>
                                    <span className="hidden text-[11px] text-gray-500 lg:block dark:text-gray-400">{s.hint}</span>
                                </span>
                            </button>
                        ))}
                    </nav>

                    <Panel title={`${step + 1}. ${STEPS[step].label}`} sub={STEPS[step].hint}>
                        <div className="space-y-4">
                            {step === 0 && (
                                <>
                                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                        <Field label="Nom de l’agent" hint="Le prénom sous lequel il se présente (ex. Awa)." error={errors.name}>
                                            <input className={input} value={data.name} onChange={(e) => setData('name', e.target.value)} maxLength={60} autoFocus />
                                        </Field>
                                        <Field label="Rôle" hint="Ex. Conseillère beauté, Accueil du club…" error={errors.role}>
                                            <input className={input} value={data.role} onChange={(e) => setData('role', e.target.value)} maxLength={120} />
                                        </Field>
                                    </div>
                                    <div>
                                        <p className="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Ton des réponses</p>
                                        <div className="mdash-seg" role="group" aria-label="Ton des réponses">
                                            {tones.map((t) => (
                                                <button key={t.key} type="button" aria-pressed={data.tone === t.key} onClick={() => setData('tone', t.key)}>
                                                    {t.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <Field label="Consignes" hint="Ce que l’agent doit faire ou mettre en avant (facultatif)." error={errors.instructions}>
                                        <textarea
                                            className={input}
                                            rows={5}
                                            value={data.instructions}
                                            onChange={(e) => setData('instructions', e.target.value)}
                                            placeholder={'Ex. Tutoie les clients. Propose toujours de réserver en ligne. Rappelle l’offre du mois : -20 % sur les soins du visage.'}
                                        />
                                    </Field>
                                </>
                            )}

                            {step === 1 && (
                                <>
                                    <div>
                                        <p className="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Fournisseur IA</p>
                                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">
                                            {providers.map((p) => (
                                                <label
                                                    key={p.key}
                                                    className={`cursor-pointer rounded-xl border p-3 text-sm transition-colors ${
                                                        data.provider === p.key ? 'border-brand-500 bg-brand-50 ring-1 ring-brand-500 dark:bg-white/10' : 'border-gray-200 hover:border-gray-300 dark:border-gray-700'
                                                    }`}
                                                >
                                                    <input
                                                        type="radio"
                                                        name="provider"
                                                        className="sr-only"
                                                        checked={data.provider === p.key}
                                                        onChange={() => setData((d) => ({ ...d, provider: p.key, model: p.models[0] }))}
                                                    />
                                                    <b className="block text-gray-800 dark:text-white/90">{p.label}</b>
                                                    <span className="text-xs text-gray-500 dark:text-gray-400">{p.models.join(', ')}</span>
                                                </label>
                                            ))}
                                        </div>
                                        {errors.provider && <p className="mt-1 text-xs text-error-500">{errors.provider}</p>}
                                    </div>
                                    <Field label="Modèle" hint="Choisissez dans la liste ou saisissez le nom exact d’un autre modèle du fournisseur." error={errors.model}>
                                        <input className={input} list="ai-models" value={data.model} onChange={(e) => setData('model', e.target.value.trim())} />
                                        <datalist id="ai-models">
                                            {provider.models.map((m) => (
                                                <option key={m} value={m} />
                                            ))}
                                        </datalist>
                                    </Field>
                                    <Field
                                        label="Clé API"
                                        error={errors.api_key}
                                        hint={
                                            <>
                                                {agent ? `Clé enregistrée : ${agent.masked_key}. Laissez vide pour la garder. ` : ''}
                                                Créez votre clé sur{' '}
                                                <a href={provider.console} target="_blank" rel="noreferrer" className="text-brand-text underline">
                                                    la console {provider.label}
                                                </a>
                                                . Elle est chiffrée et n’est jamais réaffichée. Les réponses sont facturées par le fournisseur sur votre compte.
                                            </>
                                        }
                                    >
                                        <input
                                            className={input}
                                            type="password"
                                            autoComplete="off"
                                            value={data.api_key}
                                            onChange={(e) => setData('api_key', e.target.value.trim())}
                                            placeholder={agent ? '••••••••' : provider.key_hint}
                                        />
                                    </Field>
                                </>
                            )}

                            {step === 2 && (
                                <>
                                    <label className="flex items-start gap-2 rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700">
                                        <input type="checkbox" className="mt-0.5" checked={data.use_business_info} onChange={(e) => setData('use_business_info', e.target.checked)} />
                                        <span>
                                            <b className="block text-gray-800 dark:text-white/90">Utiliser les informations de l’entreprise</b>
                                            <span className="text-xs text-gray-500 dark:text-gray-400">
                                                Nom de l’établissement, horaires, lien de réservation{hasCatalog ? ', prestations et produits avec leurs prix' : ''}. Toujours à jour, rien à recopier.
                                            </span>
                                        </span>
                                    </label>
                                    <Field label="Connaissances" hint="Questions fréquentes, adresse et accès, moyens de paiement, politique d’annulation, offres en cours…" error={errors.knowledge}>
                                        <textarea
                                            className={input}
                                            rows={10}
                                            value={data.knowledge}
                                            onChange={(e) => setData('knowledge', e.target.value)}
                                            placeholder={'Adresse : Quartier Louis, face à la pharmacie.\nPaiement : espèces, Airtel Money, Moov Money.\nAnnulation gratuite jusqu’à 24 h avant.\nQ : Faites-vous les enfants ? R : Oui, dès 6 ans.'}
                                        />
                                    </Field>
                                </>
                            )}

                            {step === 3 && (
                                <>
                                    <div className="rounded-lg bg-gray-50 p-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-300">
                                        Toujours actif : l’agent n’invente ni prix ni disponibilité, ne réserve et n’encaisse rien lui-même, ne demande jamais de code ni de carte bancaire. Si le client demande une
                                        personne, se plaint ou si l’agent ne sait pas, la discussion passe à votre équipe (« À faire ») et l’agent se tait 24 h pour ce client.
                                    </div>
                                    <Field label="Sujets à ne pas aborder" hint="Un sujet par ligne : l’agent passe alors la main à l’équipe." error={errors.forbidden_topics}>
                                        <textarea
                                            className={input}
                                            rows={4}
                                            value={data.forbidden_topics}
                                            onChange={(e) => setData('forbidden_topics', e.target.value)}
                                            placeholder={'Conseils médicaux\nRemboursements\nPrix négociés'}
                                        />
                                    </Field>
                                    <Field label="Message quand l’agent passe la main" error={errors.handoff_message}>
                                        <textarea className={input} rows={2} value={data.handoff_message} onChange={(e) => setData('handoff_message', e.target.value)} placeholder={defaultHandoff} />
                                    </Field>
                                    <Field label="Réponses IA au plus par client et par 24 h" hint="Au-delà, l’équipe prend le relais." error={errors.max_replies_per_day}>
                                        <input
                                            className={`${input} max-w-32`}
                                            type="number"
                                            min={1}
                                            max={200}
                                            value={data.max_replies_per_day}
                                            onChange={(e) => setData('max_replies_per_day', Number(e.target.value))}
                                        />
                                    </Field>
                                </>
                            )}

                            {step === 4 && (
                                <>
                                    <div>
                                        <p className="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Quand l’agent répond-il ?</p>
                                        <div className="space-y-1.5">
                                            {schedules.map((s) => (
                                                <label key={s.key} className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                                                    <input type="radio" name="schedule" checked={data.schedule === s.key} onChange={() => setData('schedule', s.key)} />
                                                    {s.label}
                                                </label>
                                            ))}
                                        </div>
                                        <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Horaires d’ouverture de chaque établissement (Paramètres › Réservation en ligne).</p>
                                    </div>
                                    {stores.length > 1 && (
                                        <div>
                                            <p className="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Établissements</p>
                                            <div className="flex flex-wrap gap-2">
                                                {stores.map((s) => (
                                                    <label key={s.id} className="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-700">
                                                        <input type="checkbox" checked={data.store_ids.includes(s.id)} onChange={() => toggleStore(s.id)} />
                                                        {s.name}
                                                    </label>
                                                ))}
                                            </div>
                                            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">Aucun coché : tous les établissements.</p>
                                        </div>
                                    )}
                                    <label className="flex items-center gap-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        <input type="checkbox" checked={data.active} onChange={(e) => setData('active', e.target.checked)} />
                                        Activer l’agent dès l’enregistrement
                                    </label>
                                </>
                            )}
                        </div>

                        <div className="mt-5 flex items-center justify-between gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                            <Button type="button" variant="outline" disabled={step === 0} onClick={() => setStep(step - 1)}>
                                Précédent
                            </Button>
                            <div className="flex gap-2">
                                {(agent || step === STEPS.length - 1) && <Button disabled={processing}>{processing ? 'Enregistrement…' : agent ? 'Enregistrer' : 'Créer l’agent'}</Button>}
                                {step < STEPS.length - 1 && (
                                    <Button type="button" variant={agent ? 'outline' : 'primary'} onClick={() => setStep(step + 1)}>
                                        Suivant
                                    </Button>
                                )}
                            </div>
                        </div>
                    </Panel>
                </div>

                <div className="c4 flex flex-col gap-3.5">
                    <Panel title="Liste de vérification" sub={`${ready} sur ${checks.length}`}>
                        <ul className="space-y-2 text-sm">
                            {checks.map((c) => (
                                <li key={c.label}>
                                    <button type="button" onClick={() => setStep(c.step)} className="flex w-full items-center gap-2 text-left hover:underline">
                                        <span
                                            className={`flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] ${c.done ? 'bg-success-500 text-white' : 'border border-gray-300 text-gray-400 dark:border-gray-600'}`}
                                        >
                                            {c.done ? '✓' : ''}
                                        </span>
                                        <span className={c.done ? 'text-gray-700 dark:text-gray-300' : 'text-gray-500'}>{c.label}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                        {!apiEnabled && (
                            <p className="mt-3 text-xs text-gray-500 dark:text-gray-400">Sans API WhatsApp, l’agent est prêt mais n’envoie rien : activez-la dans Paramètres › Entreprise.</p>
                        )}
                    </Panel>

                    <Panel title="Tester l’agent" sub="Rien n’est envoyé au client">
                        <div className="space-y-2">
                            <textarea className={input} rows={3} value={testMessage} onChange={(e) => setTestMessage(e.target.value)} aria-label="Message du client" />
                            <Button type="button" variant="outline" className="w-full" disabled={testing || !hasKey || testMessage.trim() === ''} onClick={runTest}>
                                <RobotIcon className="h-4 w-4" />
                                {testing ? 'L’agent réfléchit…' : 'Envoyer le message test'}
                            </Button>
                            {!hasKey && <p className="text-xs text-gray-500">Saisissez d’abord la clé API (étape 2).</p>}
                            {testResult?.reply && (
                                <div className="rounded-xl rounded-bl-none bg-brand-50 px-3 py-2 text-sm whitespace-pre-line text-gray-800 dark:bg-brand-500/20 dark:text-white/90">
                                    <span className="mb-1 block text-[11px] font-semibold text-brand-text">{data.name || 'Agent'} · Agent IA</span>
                                    {testResult.reply}
                                </div>
                            )}
                            {testResult?.error && <p className="text-xs text-error-500">{testResult.error}</p>}
                        </div>
                    </Panel>
                </div>
            </form>
        </Page>
    );
}

AiAgentForm.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AiAgentForm;
