import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect, useRef } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, SearchFilter, Seg, formatDay, useQueryFilters } from '../../components/page/Page';

/*
 * Discussions : la boîte de réception WhatsApp partagée de l'équipe.
 * À gauche, les clients qui ont écrit (dernier message, source de la pub,
 * « à traiter ») ; à droite, le fil et la réponse. Texte libre tant que le
 * client a écrit il y a moins de 24 h, sinon un modèle approuvé.
 */

interface Conversation {
    id: number;
    name: string;
    phone: string;
    store: string | null;
    source: string | null;
    needs_attention: boolean;
    assignee: string | null;
    last: string | null;
    last_at: string | null;
}

interface Message {
    id: string;
    direction: 'in' | 'out';
    body: string | null;
    at: string | null;
    origin: string | null;
    status: string | null;
    author: string | null;
}

interface Thread {
    id: number;
    customer_id: number | null;
    name: string;
    phone: string;
    store: string | null;
    source: string | null;
    source_headline: string | null;
    needs_attention: boolean;
    assigned_user_id: number | null;
    bot_paused: boolean;
    window_open: boolean;
    window_ends_at: string | null;
    messages: Message[];
}

interface PageProps {
    apiEnabled: boolean;
    filters: { filter: 'all' | 'todo' | 'mine'; q: string };
    counts: { todo: number };
    conversations: Conversation[];
    selected: Thread | null;
    users: { id: number; name: string }[];
    templates: { id: number; name: string; category: string | null; body_text: string | null }[];
    [key: string]: unknown;
}

const ORIGIN: Record<string, string> = { auto: 'Réponse automatique', reminder: 'Rappel', campaign: 'Campagne' };

function Bubble({ m }: { m: Message }) {
    const out = m.direction === 'out';
    return (
        <div className={`flex ${out ? 'justify-end' : 'justify-start'}`}>
            <div className={`max-w-[75%] rounded-xl px-3 py-2 text-sm shadow-sm ${out ? 'rounded-br-none bg-brand-50 text-gray-800 dark:bg-brand-500/20 dark:text-white/90' : 'rounded-bl-none bg-white text-gray-800 dark:bg-white/10 dark:text-white/90'}`}>
                <p className="whitespace-pre-line">{m.body}</p>
                <p className="mt-1 text-right text-[11px] text-gray-500">
                    {m.origin && ORIGIN[m.origin] ? `${ORIGIN[m.origin]} · ` : m.author ? `${m.author} · ` : ''}
                    {formatDay(m.at, true)}
                    {m.status === 'failed' && <span className="ml-1 font-medium text-error-500">échec</span>}
                </p>
            </div>
        </div>
    );
}

function ThreadPanel({ thread, users, templates, apiEnabled }: { thread: Thread; users: PageProps['users']; templates: PageProps['templates']; apiEnabled: boolean }) {
    const form = useForm({ body: '', template_id: '' });
    const end = useRef<HTMLDivElement>(null);
    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [thread.id, thread.messages.length]);

    const send: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(`/discussions/${thread.id}/reply`, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Panel
            title={thread.name}
            sub={[thread.phone, thread.store, thread.source && `Source : ${thread.source}${thread.source_headline ? ` (« ${thread.source_headline} »)` : ''}`].filter(Boolean).join(' · ')}
            actions={
                <>
                    <select
                        aria-label="Attribuer la discussion"
                        className="rounded-md border border-gray-200 px-2 py-1 text-xs dark:border-gray-700 dark:bg-gray-900"
                        value={thread.assigned_user_id ?? ''}
                        onChange={(e) => router.put(`/discussions/${thread.id}/assign`, { user_id: e.target.value || null }, { preserveScroll: true })}
                    >
                        <option value="">Non attribuée</option>
                        {users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </select>
                    {thread.needs_attention && (
                        <button type="button" className="mdash-btn ghost sm" onClick={() => router.post(`/discussions/${thread.id}/resolve`, {}, { preserveScroll: true })}>
                            Marquer traitée
                        </button>
                    )}
                    {thread.customer_id && (
                        <Link href={`/customers/${thread.customer_id}`} className="mdash-btn ghost sm">
                            Fiche
                        </Link>
                    )}
                </>
            }
            className="c8"
        >
            {thread.bot_paused && <p className="mx-5 mt-3 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700">Le client a demandé à parler à quelqu’un : le robot se tait pendant 24 h.</p>}
            <div className="h-[52vh] space-y-2 overflow-y-auto bg-gray-50 p-4 dark:bg-white/[0.02]">
                {thread.messages.length === 0 ? <Empty title="Aucun message pour l’instant" /> : thread.messages.map((m) => <Bubble key={m.id} m={m} />)}
                <div ref={end} />
            </div>
            <form onSubmit={send} className="space-y-2 border-t border-gray-100 p-4 dark:border-gray-800">
                {thread.window_open ? (
                    <>
                        <textarea
                            rows={2}
                            value={form.data.body}
                            onChange={(e) => form.setData('body', e.target.value)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-white/[0.03]"
                            placeholder="Votre réponse…"
                            aria-label="Votre réponse"
                        />
                        <div className="flex items-center justify-between text-xs text-gray-500">
                            <span>Réponse libre possible jusqu’au {formatDay(thread.window_ends_at, true)} (fenêtre de 24 h WhatsApp).</span>
                            <button type="submit" className="mdash-btn brand" disabled={!apiEnabled || form.processing || form.data.body.trim() === ''}>
                                Envoyer
                            </button>
                        </div>
                    </>
                ) : (
                    <div className="flex flex-wrap items-center gap-2 text-sm">
                        <span className="text-gray-500">Plus de 24 h sans message du client : envoyez un modèle approuvé.</span>
                        <select
                            aria-label="Modèle de message"
                            value={form.data.template_id}
                            onChange={(e) => form.setData('template_id', e.target.value)}
                            className="rounded-md border border-gray-200 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-900"
                        >
                            <option value="">Choisir un modèle…</option>
                            {templates.map((t) => (
                                <option key={t.id} value={t.id}>
                                    {t.name} {t.category ? `(${t.category.toLowerCase()})` : ''}
                                </option>
                            ))}
                        </select>
                        <button type="submit" className="mdash-btn brand" disabled={!apiEnabled || form.processing || !form.data.template_id}>
                            Envoyer le modèle
                        </button>
                    </div>
                )}
            </form>
        </Panel>
    );
}

function DiscussionsIndex() {
    const { apiEnabled, filters, counts, conversations, selected, users, templates } = usePage<PageProps>().props;
    const visit = useQueryFilters(selected ? `/discussions/${selected.id}` : '/discussions', { ...filters, filter: filters.filter === 'all' ? null : filters.filter });
    const query = new URLSearchParams({ ...(filters.filter !== 'all' ? { filter: filters.filter } : {}), ...(filters.q ? { q: filters.q } : {}) }).toString();

    return (
        <Page
            title="Discussions"
            subtitle="Les messages WhatsApp de vos clients, au même endroit pour toute l’équipe"
            filters={
                <>
                    <Seg
                        label="Afficher"
                        value={filters.filter}
                        options={[
                            { key: 'all', label: 'Toutes' },
                            { key: 'todo', label: `À traiter${counts.todo ? ` (${counts.todo})` : ''}` },
                            { key: 'mine', label: 'Les miennes' },
                        ]}
                        onChange={(filter) => visit({ filter: filter === 'all' ? null : filter })}
                    />
                    <SearchFilter value={filters.q} placeholder="Nom ou numéro" onSearch={(q) => visit({ q })} />
                </>
            }
        >
            {!apiEnabled && (
                <p className="mb-4 rounded-lg bg-warning-50 px-4 py-3 text-sm text-warning-700">
                    Connectez le numéro WhatsApp de votre établissement (Paramètres › WhatsApp) : les messages de vos clients arriveront ici, avec les réponses automatiques.
                </p>
            )}
            {conversations.length === 0 && !selected ? (
                <Panel>
                    <Empty title={filters.filter === 'todo' ? 'Rien à traiter' : 'Aucune discussion pour l’instant'}>
                        Dès qu’un client écrit sur WhatsApp, sa fiche est créée et la discussion apparaît ici. « À traiter » regroupe ceux qui veulent parler à quelqu’un.
                    </Empty>
                </Panel>
            ) : (
                <div className="mdash-grid">
                    <Panel title="Clients" sub={`${conversations.length} discussion${conversations.length > 1 ? 's' : ''}`} className="c4">
                        <ul className="max-h-[68vh] divide-y divide-gray-100 overflow-y-auto dark:divide-gray-800">
                            {conversations.map((c) => (
                                <li key={c.id}>
                                    <Link
                                        href={`/discussions/${c.id}${query ? `?${query}` : ''}`}
                                        preserveScroll
                                        className={`block px-4 py-3 text-sm hover:bg-gray-50 dark:hover:bg-white/5 ${selected?.id === c.id ? 'bg-brand-50 dark:bg-white/10' : ''}`}
                                    >
                                        <span className="flex items-center justify-between gap-2">
                                            <b className="truncate">{c.name}</b>
                                            <span className="shrink-0 text-xs text-gray-500">{formatDay(c.last_at)}</span>
                                        </span>
                                        <span className="block truncate text-gray-600 dark:text-gray-400">{c.last ?? '—'}</span>
                                        <span className="mt-1 flex flex-wrap gap-1">
                                            {c.needs_attention && <span className="mdash-pill warn">À traiter</span>}
                                            {c.source && <span className="mdash-pill">{c.source}</span>}
                                            {c.assignee && <span className="mdash-pill">{c.assignee}</span>}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </Panel>
                    {selected ? (
                        <ThreadPanel thread={selected} users={users} templates={templates} apiEnabled={apiEnabled} />
                    ) : (
                        <Panel className="c8">
                            <Empty title="Choisissez une discussion">Cliquez sur un client à gauche pour lire ses messages et lui répondre.</Empty>
                        </Panel>
                    )}
                </div>
            )}
        </Page>
    );
}

DiscussionsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default DiscussionsIndex;
