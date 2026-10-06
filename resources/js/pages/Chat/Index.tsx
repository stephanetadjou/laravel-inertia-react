import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { useHeaderHeight } from '../../components/dashboard/useHeaderHeight';
import '../Dashboard/dashboard.css';
import './chat.css';
import { customerUrl } from '../../components/common/customerUrl';

type Period = 'today' | '7d' | '30d' | 'all';
type Kind = 'campaign' | 'welcome';

interface Filters {
    period: Period;
    brand: number | null;
    brand_locked: boolean;
    brand_hidden: boolean;
    store: number | null;
    type: Kind | null;
    q: string;
    tab: 'all' | 'failed';
}

interface StoreGroup {
    brand: { id: number; name: string } | null;
    stores: { id: number; name: string }[];
}

interface LogRow {
    key: string;
    kind: Kind;
    sent_at: string;
    status: 'sent' | 'failed';
    error_message: string | null;
    contact: { id: number | null; name: string | null; phone_e164: string | null; store_name: string | null; brand_name: string | null };
    campaign: { id: number | null; name: string; template: string | null } | null;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

interface TimelineItem {
    key: string;
    kind: Kind;
    body: string | null;
    label: string;
    template: string | null;
    status: 'sent' | 'failed';
    delivery: 'sent' | 'delivered' | 'read' | 'failed';
    error_message: string | null;
    sent_at: string;
}

interface SelectedContact {
    id: number;
    customer_id: number | null;
    name: string;
    phone_e164: string;
    store_name: string;
    consent_status: 'unknown' | 'opt_in' | 'opt_out';
    message_count: number;
    last_purchase: { date: string; amount: number } | null;
    timeline: TimelineItem[];
}

interface PageProps {
    filters: Filters;
    storeOptions: StoreGroup[];
    stats: { sent: number; failed: number; failure_rate: number; clients: number; total: number };
    messages: Paginated<LogRow>;
    selectedContact: SelectedContact | null;
    [key: string]: unknown;
}

const PERIODS: { key: Period; label: string }[] = [
    { key: 'today', label: "Aujourd'hui" },
    { key: '7d', label: '7 j' },
    { key: '30d', label: '30 j' },
    { key: 'all', label: 'Tout' },
];

const KIND_LABEL: Record<Kind, [string, string]> = {
    campaign: ['Campagne', 'info'],
    welcome: ['Bienvenue', 'ok'],
};

const CONSENT: Record<SelectedContact['consent_status'], [string, string]> = {
    unknown: ['Inconnu', 'idle'],
    opt_in: ['Opt-in', 'ok'],
    opt_out: ['Opt-out', 'bad'],
};

const shortDate = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short' }).replace('.', '');
const time = (iso: string) => new Date(iso).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();

function ChatIndex() {
    const { filters, storeOptions, stats, messages, selectedContact } = usePage<PageProps>().props;
    const headerHeight = useHeaderHeight();
    const [search, setSearch] = useState(filters.q);
    const timelineRef = useRef<HTMLDivElement>(null);

    useEffect(() => setSearch(filters.q), [filters.q]);

    useEffect(() => {
        const el = timelineRef.current;
        if (el) el.scrollTop = el.scrollHeight;
    }, [selectedContact?.id]);

    function query(overrides: Partial<Record<keyof Filters | 'page', string | number | null>> = {}) {
        const next: Record<string, string | number | null> = {
            period: filters.period,
            brand: filters.brand_locked ? null : filters.brand,
            store: filters.store,
            type: filters.type,
            q: filters.q || null,
            tab: filters.tab === 'failed' ? 'failed' : null,
            ...overrides,
        };
        return Object.fromEntries(Object.entries(next).filter(([, v]) => v !== null && v !== '')) as Record<string, string | number>;
    }

    /** Changement de filtre : retour à la page 1 et au premier message. */
    function applyFilters(overrides: Parameters<typeof query>[0]) {
        router.get('/chat', query({ ...overrides, page: null }), { preserveState: true, preserveScroll: true, replace: true });
    }

    function goToPage(page: number) {
        const base = selectedContact ? `/chat/${selectedContact.id}` : '/chat';
        router.get(base, query({ page }), { preserveState: true, preserveScroll: true });
    }

    function selectContact(contactId: number | null) {
        if (!contactId) return;
        router.get(`/chat/${contactId}`, query({ page: messages.current_page > 1 ? messages.current_page : null }), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['selectedContact'],
        });
    }

    // Recherche : appliquée après une courte pause de frappe.
    useEffect(() => {
        if (search === filters.q) return;
        const timer = setTimeout(() => applyFilters({ q: search.trim() || null }), 350);
        return () => clearTimeout(timer);
    }, [search]);

    const storeValue = filters.store ? `s:${filters.store}` : filters.brand && !filters.brand_locked ? `b:${filters.brand}` : '';

    function onStoreChange(value: string) {
        if (value.startsWith('s:')) {
            applyFilters({ store: Number(value.slice(2)) });
        } else if (value.startsWith('b:')) {
            applyFilters({ brand: Number(value.slice(2)), store: null });
        } else {
            applyFilters({ brand: null, store: null });
        }
    }

    const rows = messages.data;
    const failureTotal = stats.failed;

    // Chronologie groupée par jour.
    let lastDay = '';

    return (
        <div className="mdash">
            <Head title="Historique messages" />

            <header className="mdash-top" style={{ top: headerHeight }}>
                <div className="mdash-top-in">
                    <div>
                        <h1>Historique messages</h1>
                        <small>Journal des messages WhatsApp envoyés par l'application</small>
                    </div>
                    <div className="mdash-filters">
                        <div className="mdash-fgroup">
                            <span>Période</span>
                            <div className="mdash-seg" role="group" aria-label="Période">
                                {PERIODS.map((p) => (
                                    <button key={p.key} type="button" aria-pressed={filters.period === p.key} onClick={() => applyFilters({ period: p.key })}>
                                        {p.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        {!filters.brand_hidden && (
                            <div className="mdash-fgroup">
                                <label htmlFor="mchat-store">Marque / boutique</label>
                                <select id="mchat-store" value={storeValue} onChange={(e) => onStoreChange(e.target.value)}>
                                    <option value="">{filters.brand_locked ? 'Toute ma marque' : 'Toutes'}</option>
                                    {storeOptions.map((group) => (
                                        <optgroup key={group.brand?.id ?? 0} label={group.brand?.name ?? 'Sans marque'}>
                                            {!filters.brand_locked && group.brand && <option value={`b:${group.brand.id}`}>Toute la marque</option>}
                                            {group.stores.map((s) => (
                                                <option key={s.id} value={`s:${s.id}`}>
                                                    {s.name}
                                                </option>
                                            ))}
                                        </optgroup>
                                    ))}
                                </select>
                            </div>
                        )}
                        <div className="mdash-fgroup">
                            <label htmlFor="mchat-type">Type</label>
                            <select id="mchat-type" value={filters.type ?? ''} onChange={(e) => applyFilters({ type: e.target.value || null })}>
                                <option value="">Tous</option>
                                <option value="campaign">Campagne</option>
                                <option value="welcome">Bienvenue</option>
                            </select>
                        </div>
                        <div className="mdash-fgroup">
                            <label htmlFor="mchat-q">Rechercher</label>
                            <input
                                id="mchat-q"
                                type="search"
                                className="mchat-search"
                                placeholder="Nom ou numéro"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                    </div>
                </div>
            </header>

            <div className="mchat-sum">
                <div className="mchat-stat">
                    <span>Messages envoyés</span>
                    <b className="num">{formatNumber(stats.sent)}</b>
                </div>
                <div className="mchat-stat bad">
                    <span>Échecs</span>
                    <b className="num">{formatNumber(stats.failed)}</b>
                </div>
                <div className="mchat-stat">
                    <span>Taux d'échec (campagnes)</span>
                    <b className="num">{stats.failure_rate.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} %</b>
                </div>
                <div className="mchat-stat">
                    <span>Clients touchés</span>
                    <b className="num">{formatNumber(stats.clients)}</b>
                </div>
            </div>

            <div className="mchat-layout">
                <article className="mdash-panel mchat-log">
                    <div className="mchat-tabs" role="tablist">
                        <button type="button" role="tab" aria-selected={filters.tab === 'all'} onClick={() => applyFilters({ tab: null })}>
                            Tous les messages <span className="cnt">{formatNumber(stats.total)}</span>
                        </button>
                        <button type="button" role="tab" className="err" aria-selected={filters.tab === 'failed'} onClick={() => applyFilters({ tab: 'failed' })}>
                            Échecs <span className="cnt">{formatNumber(failureTotal)}</span>
                        </button>
                    </div>
                    <div className="mdash-tbl">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Client</th>
                                    <th>Boutique</th>
                                    <th>Envoi</th>
                                    <th>Statut</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="wrap" style={{ padding: 28, textAlign: 'center', color: 'var(--muted)' }}>
                                            Aucun message pour ces filtres. Élargissez la période ou retirez un filtre.
                                        </td>
                                    </tr>
                                )}
                                {rows.map((row) => {
                                    const [kindLabel, kindClass] = KIND_LABEL[row.kind];
                                    return (
                                        <tr
                                            key={row.key}
                                            data-key={row.key}
                                            tabIndex={0}
                                            aria-selected={row.contact.id !== null && row.contact.id === selectedContact?.id}
                                            onClick={() => selectContact(row.contact.id)}
                                            onKeyDown={(e) => e.key === 'Enter' && selectContact(row.contact.id)}
                                        >
                                            <td className="mchat-when">
                                                <b>{time(row.sent_at)}</b>
                                                <span>{shortDate(row.sent_at)}</span>
                                            </td>
                                            <td className="cell-main">
                                                <b>{row.contact.name}</b>
                                                <span className="mono">{row.contact.phone_e164}</span>
                                            </td>
                                            <td className="cell-main wrap">
                                                <b style={{ fontWeight: 500 }}>{row.contact.store_name}</b>
                                                {row.contact.brand_name && <span>{row.contact.brand_name}</span>}
                                            </td>
                                            <td className="mchat-send">
                                                <b>
                                                    <span className={`mdash-pill ${kindClass}`}>{kindLabel}</span>{' '}
                                                    {row.kind === 'campaign' ? row.campaign?.name : 'Message de bienvenue'}
                                                </b>
                                                {row.campaign?.template && <code>{row.campaign.template}</code>}
                                                {row.kind === 'campaign' && !row.campaign?.template && row.campaign?.id && <code>texte libre</code>}
                                            </td>
                                            <td>
                                                {row.status === 'failed' ? (
                                                    <>
                                                        <span className="mdash-pill bad">Échec</span>
                                                        {row.error_message && <span className="mchat-err">{row.error_message}</span>}
                                                    </>
                                                ) : (
                                                    <span className="mdash-pill ok">Envoyé</span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <div className="mchat-pager">
                        <span>{messages.total ? `${messages.from}–${messages.to} sur ${formatNumber(messages.total)}` : '0 message'}</span>
                        <div className="btns">
                            <button type="button" className="mdash-btn ghost" disabled={messages.current_page <= 1} onClick={() => goToPage(messages.current_page - 1)}>
                                Précédent
                            </button>
                            <button
                                type="button"
                                className="mdash-btn ghost"
                                disabled={messages.current_page >= messages.last_page}
                                onClick={() => goToPage(messages.current_page + 1)}
                            >
                                Suivant
                            </button>
                        </div>
                    </div>
                </article>

                <aside className="mdash-panel mchat-drawer" aria-label="Fiche client" style={{ top: headerHeight + 130 }}>
                    {!selectedContact ? (
                        <p style={{ color: 'var(--muted)', margin: 0 }}>Sélectionnez un message pour voir la fiche du client.</p>
                    ) : (
                        <>
                            <div className="mchat-head">
                                <span className="mchat-avatar">{initials(selectedContact.name)}</span>
                                <div>
                                    <b>{selectedContact.name}</b>
                                    <span>{selectedContact.phone_e164}</span>
                                </div>
                            </div>
                            <dl className="mchat-facts">
                                <div>
                                    <dt>Boutique</dt>
                                    <dd>{selectedContact.store_name}</dd>
                                </div>
                                <div>
                                    <dt>Consentement</dt>
                                    <dd>
                                        <span className={`mdash-pill ${CONSENT[selectedContact.consent_status][1]}`}>{CONSENT[selectedContact.consent_status][0]}</span>
                                    </dd>
                                </div>
                                <div>
                                    <dt>Messages envoyés</dt>
                                    <dd>{formatNumber(selectedContact.message_count)}</dd>
                                </div>
                                <div>
                                    <dt>Dernier achat</dt>
                                    <dd>
                                        {selectedContact.last_purchase
                                            ? `${shortDate(selectedContact.last_purchase.date)} · ${formatNumber(selectedContact.last_purchase.amount)} F`
                                            : 'Aucun'}
                                    </dd>
                                </div>
                            </dl>
                            <div className="mchat-timeline" ref={timelineRef} aria-label="Messages envoyés à ce client">
                                {selectedContact.timeline.length === 0 && <span className="mchat-day">Aucun message envoyé</span>}
                                {selectedContact.timeline.map((m) => {
                                    const day = shortDate(m.sent_at);
                                    const showDay = day !== lastDay;
                                    lastDay = day;
                                    return (
                                        <div key={m.key} style={{ display: 'contents' }}>
                                            {showDay && <span className="mchat-day">{day}</span>}
                                            <div className={`mchat-bubble ${m.status === 'failed' ? 'failed' : ''}`}>
                                                <span>
                                                    {m.body ??
                                                        (m.kind === 'welcome'
                                                            ? 'Message de bienvenue (modèle non conservé)'
                                                            : m.template
                                                              ? `Modèle WhatsApp « ${m.template} »`
                                                              : m.label)}
                                                </span>
                                                <span className="btpl">{m.label}</span>
                                                <span className="bmeta">
                                                    <span>{m.status === 'failed' ? `Échec${m.error_message ? ` · ${m.error_message}` : ''}` : m.delivery === 'read' ? 'Lu ✓✓' : m.delivery === 'delivered' ? 'Remis ✓✓' : 'Envoyé ✓'}</span>
                                                    <span>{time(m.sent_at)}</span>
                                                </span>
                                            </div>
                                        </div>
                                    );
                                })}
                                <div className="mchat-bubble in">Les réponses du client apparaîtront ici après la mise en place du webhook Meta.</div>
                            </div>
                            <div>
                                <Link href={customerUrl(selectedContact)} className="mdash-btn ghost">
                                    Ouvrir la fiche client
                                </Link>
                            </div>
                        </>
                    )}
                </aside>
            </div>
        </div>
    );
}

ChatIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ChatIndex;
