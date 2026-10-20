import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { ConsentDonut, MessagesChart, SalesChart, bucketLabel, formatCompact, formatNumber } from '../../components/dashboard/Charts';
import type { Bucket } from '../../components/dashboard/Charts';
import { useHeaderHeight } from '../../components/dashboard/useHeaderHeight';
import { Delta, Kpi, percentChange } from '../../components/dashboard/Kpi';
import { VIP_PILL, stageInfo } from '../../components/dashboard/stages';
import './dashboard.css';
import { customerUrl } from '../../components/common/customerUrl';
import { NotificationPanel } from '../../components/notifications/Notifications';

/** Couleur de chaque étape du parcours, selon sa pastille (cf. stages.ts). */
const STAGE_COLORS: Record<string, string> = {
    idle: 'var(--faint)',
    info: 'var(--data)',
    ok: 'var(--ok)',
    accent: 'var(--accent)',
    warn: 'var(--warn)',
    bad: 'var(--bad)',
};

type Preset = 'today' | '7d' | '30d' | '90d';

interface Filters {
    from: string;
    to: string;
    preset: Preset | null;
    days: number;
    brand: number | null;
    brand_locked: boolean;
    brand_hidden: boolean;
    brands: { id: number; name: string }[];
    store_count: number;
}

interface Kpis {
    revenue: { current: number; previous: number };
    contacts: { current: number; previous: number; new: number };
    opt_in: { opt_in: number; opt_out: number; unknown: number; total: number };
    messages: { current: number; previous: number; campaigns: number };
    failure: { failed: number; attempted: number; previous_failed: number; previous_attempted: number };
    buyers: { current: number; previous: number; purchases: number };
}

interface CategoryRow {
    name: string;
    revenue: number;
    clients: number;
    purchases: number;
}

interface NewClient {
    id: number;
    customer_id: number | null;
    name: string;
    phone_e164: string;
    store_name: string;
    consent_status: 'unknown' | 'opt_in' | 'opt_out';
    welcomed: boolean;
    created_at: string;
}

interface BrandContacts {
    id: number;
    name: string;
    opt_in: number;
    unknown: number;
    opt_out: number;
    total: number;
}

interface StoreRow {
    id: number;
    name: string;
    brand_name: string | null;
    connected: boolean;
    shared_account: boolean;
    contacts: number;
    opt_in: number;
    vip: number;
    messages: number;
    revenue: number;
}

interface CampaignRow {
    id: number;
    name: string;
    store_names: string;
    status: string;
    sent_count: number;
    failed_count: number;
    total: number;
    created_at: string;
}

interface PageProps {
    filters: Filters;
    kpis: Kpis;
    messagesSeries: (Bucket & { sent: number; failed: number })[];
    salesSeries: (Bucket & { revenue: number; previous: number })[];
    pendingWelcome: number;
    topCategories: CategoryRow[];
    newClients: NewClient[];
    brandContacts: BrandContacts[];
    lifecycle: {
        stages: { key: string; label: string; count: number }[];
        total: number;
        vip: number;
        moves: { new_buyers: number; new_prospects: number; at_risk: number; churned: number; reactivated: number };
    };
    stores: StoreRow[];
    recentCampaigns: CampaignRow[];
    welcomeTemplates: { id: number; name: string; language: string }[];
    flash?: { success: string | null; error: string | null };
    auth?: { organization?: { whatsapp_api_enabled?: boolean } | null };
    [key: string]: unknown;
}

type Metric = 'revenue' | 'clients' | 'purchases';

const PRESETS: { key: Preset; label: string }[] = [
    { key: 'today', label: "Aujourd'hui" },
    { key: '7d', label: '7 j' },
    { key: '30d', label: '30 j' },
    { key: '90d', label: '90 j' },
];

const CAMPAIGN_STATUS: Record<string, [string, string]> = {
    draft: ['Brouillon', 'idle'],
    queued: ['En file', 'info'],
    sending: ['En cours', 'warn'],
    completed: ['Terminée', 'ok'],
};

const CONSENT: Record<NewClient['consent_status'], [string, string]> = {
    unknown: ['Inconnu', 'idle'],
    opt_in: ['Opt-in', 'ok'],
    opt_out: ['Opt-out', 'bad'],
};

function formatDate(day: string): string {
    const [y, m, d] = day.split('-');
    return `${d}/${m}/${y}`;
}

function formatPercent(value: number): string {
    return value.toLocaleString('fr-FR', { maximumFractionDigits: 1 }) + ' %';
}

function plural(n: number, word: string, pluralWord = word + 's'): string {
    return `${formatNumber(n)} ${n > 1 ? pluralWord : word}`;
}

function agoLabel(iso: string): string {
    const hours = Math.floor((Date.now() - new Date(iso).getTime()) / 36e5);
    if (hours < 1) return "à l'instant";
    if (hours < 24) return `il y a ${hours} h`;
    const days = Math.floor(hours / 24);
    return days === 1 ? 'hier' : `il y a ${days} j`;
}

function DashboardIndex() {
    const {
        filters,
        kpis,
        messagesSeries,
        salesSeries,
        pendingWelcome,
        topCategories,
        newClients,
        brandContacts,
        lifecycle,
        stores,
        recentCampaigns,
        welcomeTemplates,
        flash,
        auth,
    } = usePage<PageProps>().props;
    // Sans l'API WhatsApp : pas de messages, campagnes ni bienvenue à suivre ici.
    const apiEnabled = auth?.organization?.whatsapp_api_enabled ?? true;

    const headerHeight = useHeaderHeight();
    const [customFrom, setCustomFrom] = useState(filters.from);
    const [customTo, setCustomTo] = useState(filters.to);
    const [metric, setMetric] = useState<Metric>('revenue');

    useEffect(() => {
        setCustomFrom(filters.from);
        setCustomTo(filters.to);
    }, [filters.from, filters.to]);

    function visit(params: { period?: Preset; from?: string; to?: string; brand?: number | null }) {
        const brand = params.brand !== undefined ? params.brand : filters.brand;
        const query: Record<string, string | number> = {};
        if (params.period) {
            query.period = params.period;
        } else if (params.from && params.to) {
            query.from = params.from;
            query.to = params.to;
        } else if (filters.preset) {
            query.period = filters.preset;
        } else {
            query.from = filters.from;
            query.to = filters.to;
        }
        if (brand !== null && !filters.brand_locked) {
            query.brand = brand;
        }
        router.get('/dashboard', query, { preserveState: true, preserveScroll: true, replace: true });
    }

    function applyCustomRange(from: string, to: string) {
        setCustomFrom(from);
        setCustomTo(to);
        if (from && to && from <= to) {
            visit({ from, to });
        }
    }

    function selectBrand(id: number | null) {
        if (filters.brand_locked || filters.brand_hidden) return;
        visit({ brand: id });
    }

    const selectedBrandName = filters.brands.find((b) => b.id === filters.brand)?.name;

    // --- KPI ---
    const optInRate = kpis.opt_in.total > 0 ? (kpis.opt_in.opt_in / kpis.opt_in.total) * 100 : 0;
    const optOutRate = kpis.opt_in.total > 0 ? (kpis.opt_in.opt_out / kpis.opt_in.total) * 100 : 0;
    const failureRate = kpis.failure.attempted > 0 ? (kpis.failure.failed / kpis.failure.attempted) * 100 : 0;
    const previousFailureRate = kpis.failure.previous_attempted > 0 ? (kpis.failure.previous_failed / kpis.failure.previous_attempted) * 100 : null;
    const averageBasket = kpis.buyers.purchases > 0 ? kpis.revenue.current / kpis.buyers.purchases : 0;

    // --- Top 10 ---
    const top10 = useMemo(() => [...topCategories].sort((a, b) => b[metric] - a[metric]).slice(0, 10), [topCategories, metric]);
    const topMax = top10[0]?.[metric] || 1;

    // --- Ventes ---
    const salesTotal = salesSeries.reduce((sum, d) => sum + d.revenue, 0);
    const salesPrevious = salesSeries.reduce((sum, d) => sum + d.previous, 0);
    const bestBucket = salesSeries.reduce<(typeof salesSeries)[number] | null>((best, d) => (d.revenue > (best?.revenue ?? 0) ? d : best), null);
    const weekly = filters.days > 31;

    // --- Nouveaux clients & bienvenue ---
    const selectable = newClients.filter((c) => c.consent_status === 'unknown' && !c.welcomed);
    const [selectedIds, setSelectedIds] = useState<Set<number>>(() => new Set(selectable.map((c) => c.id)));
    const [confirming, setConfirming] = useState<'selection' | 'all' | null>(null);
    const [welcomeTemplate, setWelcomeTemplate] = useState(welcomeTemplates[0]?.name ?? '');
    const [sending, setSending] = useState(false);

    useEffect(() => {
        setSelectedIds(new Set(newClients.filter((c) => c.consent_status === 'unknown' && !c.welcomed).map((c) => c.id)));
        setConfirming(null);
    }, [newClients]);

    // « Tous les nouveaux en attente » s'appuie sur le périmètre du rôle et la
    // période : proposé seulement si aucune marque n'est choisie à la main.
    const canSendAllPending = pendingWelcome > selectable.length && (filters.brand === null || filters.brand_locked);

    function toggleClient(id: number) {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function toggleAllClients() {
        setSelectedIds((prev) => (selectable.every((c) => prev.has(c.id)) ? new Set() : new Set(selectable.map((c) => c.id))));
    }

    function sendWelcome() {
        if (!welcomeTemplate || !confirming) return;
        setSending(true);
        const payload =
            confirming === 'all'
                ? { all_pending: true, from: filters.from, to: filters.to, template_name: welcomeTemplate }
                : { contact_ids: Array.from(selectedIds), template_name: welcomeTemplate };
        router.post('/contacts/send-welcome-bulk', payload, {
            preserveScroll: true,
            onSuccess: () => setConfirming(null),
            onFinish: () => setSending(false),
        });
    }

    const allSelected = selectable.length > 0 && selectable.every((c) => selectedIds.has(c.id));
    const someSelected = selectedIds.size > 0 && !allSelected;
    const confirmCount = confirming === 'all' ? pendingWelcome : selectedIds.size;

    return (
        <div className="mdash">
            <Head title="Tableau de bord" />

            <header className="mdash-top" style={{ top: headerHeight }}>
                <div className="mdash-top-in">
                    <div>
                        <h1>Tableau de bord</h1>
                        <small>{apiEnabled ? 'Clients, ventes et WhatsApp' : 'Clients et ventes'}</small>
                    </div>
                    <div className="mdash-filters">
                        <div className="mdash-fgroup">
                            <span>Période</span>
                            <div className="mdash-seg" role="group" aria-label="Période">
                                {PRESETS.map((p) => (
                                    <button key={p.key} type="button" aria-pressed={filters.preset === p.key} onClick={() => visit({ period: p.key })}>
                                        {p.label}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="mdash-fgroup">
                            <span>Du / au</span>
                            <div className="mdash-range">
                                <input type="date" aria-label="Date de début" value={customFrom} max={customTo} onChange={(e) => applyCustomRange(e.target.value, customTo)} />
                                <span>→</span>
                                <input type="date" aria-label="Date de fin" value={customTo} min={customFrom} onChange={(e) => applyCustomRange(customFrom, e.target.value)} />
                            </div>
                        </div>
                        {!filters.brand_hidden && (
                            <div className="mdash-fgroup">
                                <label htmlFor="mdash-brand">Marque</label>
                                <select
                                    id="mdash-brand"
                                    value={filters.brand ?? ''}
                                    disabled={filters.brand_locked}
                                    title={filters.brand_locked ? 'Votre rôle est limité à cette marque' : undefined}
                                    onChange={(e) => selectBrand(e.target.value ? Number(e.target.value) : null)}
                                >
                                    {!filters.brand_locked && <option value="">Toutes les marques</option>}
                                    {filters.brands.map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </div>
                </div>
                <div className="mdash-scope">
                    Vous regardez <b>{selectedBrandName ?? (filters.brand_hidden ? 'votre boutique' : 'toutes les marques')}</b> ·{' '}
                    <b>{plural(filters.store_count, 'boutique')}</b> · du <b>{formatDate(filters.from)}</b> au <b>{formatDate(filters.to)}</b>
                </div>
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            <NotificationPanel />

            <section className="mdash-grid" aria-label="Indicateurs">
                <div className="c12 mdash-kpis">
                    <Kpi
                        hero
                        label="CA achats clients"
                        value={formatCompact(kpis.revenue.current)}
                        unit="FCFA"
                        delta={<Delta value={percentChange(kpis.revenue.current, kpis.revenue.previous)} />}
                    />
                    <Kpi
                        label="Contacts"
                        value={formatNumber(kpis.contacts.current)}
                        delta={<Delta value={percentChange(kpis.contacts.current, kpis.contacts.previous)} />}
                        foot={`+${plural(kpis.contacts.new, 'nouveau', 'nouveaux')}`}
                    />
                    <Kpi
                        label="Taux d'opt-in"
                        value={optInRate.toLocaleString('fr-FR', { maximumFractionDigits: 1 })}
                        unit="%"
                        delta={<Delta value={null} missing="Pas d'historique du consentement : variation indisponible" />}
                        foot={`${formatPercent(optOutRate)} opt-out`}
                    />
                    {apiEnabled && (
                    <Kpi
                        label="Messages envoyés"
                        value={formatNumber(kpis.messages.current)}
                        delta={<Delta value={percentChange(kpis.messages.current, kpis.messages.previous)} />}
                        foot={plural(kpis.messages.campaigns, 'campagne')}
                    />
                    )}
                    {apiEnabled && (
                    <Kpi
                        label="Taux d'échec d'envoi"
                        value={failureRate.toLocaleString('fr-FR', { maximumFractionDigits: 1 })}
                        unit="%"
                        delta={<Delta value={previousFailureRate === null ? null : failureRate - previousFailureRate} unit="pt" inverted />}
                        foot={`${formatNumber(kpis.failure.failed)} / ${formatNumber(kpis.failure.attempted)} msg de campagne`}
                    />
                    )}
                    <Kpi
                        label="Clients acheteurs"
                        value={formatNumber(kpis.buyers.current)}
                        delta={<Delta value={percentChange(kpis.buyers.current, kpis.buyers.previous)} />}
                        foot={`panier moy. ${formatNumber(averageBasket)} F`}
                    />
                </div>

                {apiEnabled && (
                <>
                <article className="mdash-panel c8">
                    <div className="mdash-ph">
                        <div>
                            <h2>Messages WhatsApp envoyés</h2>
                            <div className="mdash-sub">Campagnes et messages de bienvenue, {weekly ? 'par semaine' : 'par jour'}</div>
                        </div>
                        <div className="mdash-keys">
                            <span>
                                <i style={{ background: 'var(--data-2)' }} />
                                Envoyés
                            </span>
                            <span>
                                <i style={{ background: 'var(--bad)' }} />
                                Échecs
                            </span>
                        </div>
                    </div>
                    <MessagesChart series={messagesSeries} />
                </article>

                <article className="mdash-panel c4">
                    <div className="mdash-ph">
                        <h2>Consentement WhatsApp</h2>
                        <span className="mdash-sub">{plural(kpis.opt_in.total, 'contact')}</span>
                    </div>
                    <div className="mdash-consent">
                        <ConsentDonut optIn={kpis.opt_in.opt_in} unknown={kpis.opt_in.unknown} optOut={kpis.opt_in.opt_out} />
                        <div className="mdash-legend">
                            {(
                                [
                                    ['Opt-in', kpis.opt_in.opt_in, 'var(--ok)', 'Joignables par campagne'],
                                    ['Inconnu', kpis.opt_in.unknown, 'var(--faint)', 'Exclus des campagnes marketing'],
                                    ['Opt-out', kpis.opt_in.opt_out, 'var(--bad)', 'Ne plus contacter'],
                                ] as const
                            ).map(([name, value, color, hint]) => (
                                <div key={name} className="mdash-lg">
                                    <i style={{ background: color }} />
                                    <span>{name}</span>
                                    <b className="num">
                                        {formatNumber(value)} · {kpis.opt_in.total ? Math.round((value / kpis.opt_in.total) * 100) : 0} %
                                    </b>
                                    <span className="s">{hint}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                    {kpis.opt_in.unknown > 0 && (
                        <div className="mdash-note">
                            <b>{plural(kpis.opt_in.unknown, 'contact')}</b> au statut inconnu
                            {pendingWelcome > 0 && <> (dont {formatNumber(pendingWelcome)} nouveaux sur la période jamais accueillis)</>} : envoyez-leur le
                            message de bienvenue pour obtenir leur opt-in.
                        </div>
                    )}
                </article>

                </>
                )}

                <article className="mdash-panel c7" id="bienvenue">
                    <div className="mdash-ph">
                        <div>
                            <h2>Nouveaux clients</h2>
                            <div className="mdash-sub">
                                {plural(kpis.contacts.new, 'nouveau contact', 'nouveaux contacts')} sur la période
                                {newClients.length > 0 && ` · les ${newClients.length} plus récents`}
                            </div>
                        </div>
                        <Link href="/customers" className="mdash-link">
                            Tout voir
                        </Link>
                    </div>
                    {newClients.length === 0 ? (
                        <div className="mdash-empty">Aucun nouveau contact sur la période.</div>
                    ) : (
                        <div className="mdash-tbl">
                            <table>
                                <thead>
                                    <tr>
                                        {apiEnabled && (
                                        <th>
                                            <input
                                                type="checkbox"
                                                aria-label="Tout sélectionner"
                                                checked={allSelected}
                                                disabled={selectable.length === 0}
                                                ref={(el) => {
                                                    if (el) el.indeterminate = someSelected;
                                                }}
                                                onChange={toggleAllClients}
                                            />
                                        </th>
                                        )}
                                        <th>Client</th>
                                        <th>Ajouté</th>
                                        <th>Statut</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {newClients.map((c) => {
                                        const canSelect = c.consent_status === 'unknown' && !c.welcomed;
                                        const [consentLabel, consentClass] = CONSENT[c.consent_status];
                                        return (
                                            <tr key={c.id}>
                                                {apiEnabled && (
                                                <td>
                                                    <input
                                                        type="checkbox"
                                                        aria-label={`Sélectionner ${c.name}`}
                                                        checked={canSelect && selectedIds.has(c.id)}
                                                        disabled={!canSelect}
                                                        onChange={() => toggleClient(c.id)}
                                                    />
                                                </td>
                                                )}
                                                <td className="cell-main wrap">
                                                    <b>
                                                        <Link href={customerUrl(c)}>{c.name}</Link>
                                                    </b>
                                                    <span className="mono">{c.phone_e164}</span>
                                                </td>
                                                <td>
                                                    <span className="mdash-src">
                                                        {agoLabel(c.created_at)}
                                                        <br />
                                                        {c.store_name}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span className="mdash-stat">
                                                        <span className={`mdash-pill ${consentClass}`}>{consentLabel}</span>
                                                        {c.welcomed ? (
                                                            <span className="mdash-pill ok">Bienvenue ✓</span>
                                                        ) : c.consent_status === 'opt_out' ? (
                                                            <span className="mdash-src">Exclu</span>
                                                        ) : c.consent_status === 'opt_in' ? (
                                                            <span className="mdash-src">Déjà opt-in</span>
                                                        ) : (
                                                            <span className="mdash-src">À accueillir</span>
                                                        )}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {apiEnabled && (
                    <div className="mdash-bar">
                        <span className="mdash-sub">
                            {selectedIds.size ? `${plural(selectedIds.size, 'client')} sélectionné${selectedIds.size > 1 ? 's' : ''}` : 'Aucun client sélectionné'}
                        </span>
                        <span style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                            {canSendAllPending && (
                                <button type="button" className="mdash-btn ghost" onClick={() => setConfirming('all')}>
                                    Tous en attente ({formatNumber(pendingWelcome)})
                                </button>
                            )}
                            <button type="button" className="mdash-btn primary" disabled={selectedIds.size === 0} onClick={() => setConfirming('selection')}>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <path d="M22 2L11 13" />
                                    <path d="M22 2l-7 20-4-9-9-4z" />
                                </svg>
                                Campagne de bienvenue
                            </button>
                        </span>
                    </div>
                    )}
                    {apiEnabled && confirming && (
                        <div className="mdash-confirm" role="alertdialog" aria-label="Confirmer l'envoi du message de bienvenue">
                            {welcomeTemplates.length === 0 ? (
                                <>
                                    <span>
                                        Aucun modèle actif de catégorie « utility » :{' '}
                                        <Link href="/templates" className="mdash-link">
                                            créez-en un
                                        </Link>{' '}
                                        avant d'envoyer le message de bienvenue.
                                    </span>
                                    <div className="acts">
                                        <button type="button" className="mdash-btn ghost" onClick={() => setConfirming(null)}>
                                            Fermer
                                        </button>
                                    </div>
                                </>
                            ) : (
                                <>
                                    <span>
                                        Envoyer le message de bienvenue à <b>{plural(confirmCount, 'client')}</b>
                                        {confirming === 'all' && ` ajoutés du ${formatDate(filters.from)} au ${formatDate(filters.to)}`} depuis le numéro WhatsApp de leur
                                        boutique. Seuls les contacts au consentement inconnu et jamais accueillis le recevront ; les opt-out sont exclus.
                                    </span>
                                    <div className="acts">
                                        <select aria-label="Modèle de message" value={welcomeTemplate} onChange={(e) => setWelcomeTemplate(e.target.value)}>
                                            {welcomeTemplates.map((t) => (
                                                <option key={t.id} value={t.name}>
                                                    {t.name} ({t.language})
                                                </option>
                                            ))}
                                        </select>
                                        <button type="button" className="mdash-btn primary" disabled={sending || !welcomeTemplate} onClick={sendWelcome}>
                                            {sending ? 'Envoi…' : 'Envoyer maintenant'}
                                        </button>
                                        <button type="button" className="mdash-btn ghost" disabled={sending} onClick={() => setConfirming(null)}>
                                            Annuler
                                        </button>
                                    </div>
                                </>
                            )}
                        </div>
                    )}
                </article>

                <article className="mdash-panel c5">
                    <div className="mdash-ph">
                        <div>
                            <h2>Contacts par marque</h2>
                            <div className="mdash-sub">
                                {filters.brand_locked || filters.brand_hidden ? 'Répartition du consentement' : 'Cliquer une marque pour filtrer'}
                            </div>
                        </div>
                    </div>
                    {brandContacts.length === 0 ? (
                        <div className="mdash-empty">Aucune marque dans votre périmètre.</div>
                    ) : (
                        <div className="mdash-brands">
                            {brandContacts.map((b) => {
                                const pct = (v: number) => (b.total ? (v / b.total) * 100 : 0);
                                return (
                                    <button
                                        key={b.id}
                                        type="button"
                                        className={`mdash-br ${filters.brand !== null && filters.brand !== b.id ? 'dim' : ''}`}
                                        aria-pressed={filters.brand === b.id}
                                        disabled={filters.brand_locked || filters.brand_hidden}
                                        onClick={() => selectBrand(filters.brand === b.id ? null : b.id)}
                                    >
                                        <span className="mdash-br-top">
                                            <b>{b.name}</b>
                                            <span className="num">{formatNumber(b.total)}</span>
                                        </span>
                                        <span
                                            className="mdash-stack"
                                            title={`Opt-in ${formatNumber(b.opt_in)} · Inconnu ${formatNumber(b.unknown)} · Opt-out ${formatNumber(b.opt_out)}`}
                                        >
                                            <i style={{ width: `${pct(b.opt_in)}%`, background: 'var(--ok)' }} />
                                            <i style={{ width: `${pct(b.unknown)}%`, background: 'var(--faint)' }} />
                                            <i style={{ width: `${pct(b.opt_out)}%`, background: 'var(--bad)' }} />
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    <div className="mdash-keys" style={{ marginTop: 14 }}>
                        <span>
                            <i style={{ background: 'var(--ok)' }} />
                            Opt-in
                        </span>
                        <span>
                            <i style={{ background: 'var(--faint)' }} />
                            Inconnu
                        </span>
                        <span>
                            <i style={{ background: 'var(--bad)' }} />
                            Opt-out
                        </span>
                    </div>
                </article>

                <article className="mdash-panel c12">
                    <div className="mdash-ph">
                        <div>
                            <h2>Parcours client</h2>
                            <div className="mdash-sub">
                                {formatNumber(lifecycle.total)} fiches clients par étape · mouvements du {formatDate(filters.from)} au {formatDate(filters.to)}
                            </div>
                        </div>
                        <Link href="/customers" className="mdash-link">
                            Voir les fiches clients
                        </Link>
                    </div>
                    <div className="mdash-journey-bar" aria-hidden="true">
                        {lifecycle.stages
                            .filter((s) => s.count > 0)
                            .map((s) => (
                                <i key={s.key} style={{ width: `${(s.count / Math.max(1, lifecycle.total)) * 100}%`, background: STAGE_COLORS[stageInfo(s.key).pill] }} />
                            ))}
                    </div>
                    <div className="mdash-journey">
                        {lifecycle.stages.map((s) => (
                            <Link key={s.key} href={`/customers?stage=${s.key}`} className="mdash-journey-step" title={stageInfo(s.key).hint}>
                                <span>
                                    <i style={{ background: STAGE_COLORS[stageInfo(s.key).pill] }} />
                                    {s.label}
                                </span>
                                <b className="num">{formatNumber(s.count)}</b>
                                <small>{lifecycle.total ? Math.round((s.count / lifecycle.total) * 100) : 0} %</small>
                            </Link>
                        ))}
                        <Link href="/customers?vip=1" className="mdash-journey-step" title={`${VIP_PILL.hint} — statut propre à chaque boutique`}>
                            <span>
                                <i style={{ background: STAGE_COLORS.accent }} />
                                VIP (par boutique)
                            </span>
                            <b className="num">{formatNumber(lifecycle.vip)}</b>
                            <small>VIP dans au moins 1 boutique</small>
                        </Link>
                    </div>
                    <div className="mdash-journey-moves">
                        <span>
                            <b className="num">+{formatNumber(lifecycle.moves.new_buyers)}</b> nouveaux acheteurs
                        </span>
                        <span>
                            <b className="num">+{formatNumber(lifecycle.moves.new_prospects)}</b> nouveaux prospects
                        </span>
                        <span>
                            <b className="num">{formatNumber(lifecycle.moves.reactivated)}</b> réactivés
                        </span>
                        <span className="warn">
                            <b className="num">{formatNumber(lifecycle.moves.at_risk)}</b> passés « à risque »
                        </span>
                        <span className="bad">
                            <b className="num">{formatNumber(lifecycle.moves.churned)}</b> devenus inactifs
                        </span>
                    </div>
                </article>

                <article className="mdash-panel c12">
                    <div className="mdash-ph">
                        <div>
                            <h2>Évolution des ventes (CA)</h2>
                            <div className="mdash-sub">Chiffre d'affaires des achats clients, {weekly ? 'par semaine' : 'par jour'}</div>
                        </div>
                        <div className="mdash-keys">
                            <span>
                                <i style={{ background: 'var(--data)' }} />
                                Période
                            </span>
                            <span>
                                <i className="dash" />
                                Période précédente
                            </span>
                        </div>
                    </div>
                    <div className="mdash-ca-head">
                        <span className="mdash-ca-big num">
                            {formatCompact(salesTotal)}
                            <small>FCFA</small>
                        </span>
                        <span className="mdash-ca-meta">
                            <Delta value={percentChange(salesTotal, salesPrevious)} />
                            <span>vs {formatCompact(salesPrevious)} F la période précédente</span>
                            {bestBucket && (
                                <span>
                                    · meilleur{weekly ? 'e semaine' : ' jour'} : {bucketLabel(bestBucket)} ({formatCompact(bestBucket.revenue)} F)
                                </span>
                            )}
                        </span>
                    </div>
                    <SalesChart series={salesSeries} height={200} />
                </article>

                <article className={`mdash-panel ${apiEnabled ? 'c7' : 'c12'}`}>
                    <div className="mdash-ph">
                        <h2>Performance par boutique</h2>
                        <span className="mdash-sub">
                            {plural(stores.length, 'boutique')} · messages et CA sur la période
                        </span>
                    </div>
                    {stores.length === 0 ? (
                        <div className="mdash-empty">Aucune boutique dans ce périmètre.</div>
                    ) : (
                        <div className="mdash-tbl">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Boutique</th>
                                        <th>WhatsApp</th>
                                        <th className="r">Contacts</th>
                                        <th>Opt-in</th>
                                        <th className="r" title="Clients VIP de la boutique (top acheteurs de la boutique)">VIP</th>
                                        <th className="r">Messages</th>
                                        <th className="r">CA achats</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {stores.map((s) => {
                                        const rate = s.contacts ? (s.opt_in / s.contacts) * 100 : 0;
                                        return (
                                            <tr key={s.id}>
                                                <td className="cell-main wrap">
                                                    <b>{s.name}</b>
                                                    {s.brand_name && <span>{s.brand_name}</span>}
                                                </td>
                                                <td>
                                                    <span className="mdash-stat">
                                                        <span className={`mdash-pill ${s.connected ? 'ok' : 'bad'}`}>{s.connected ? 'Connectée' : 'Non connectée'}</span>
                                                        {s.connected && <span className="mdash-src">{s.shared_account ? 'Compte partagé' : 'Numéro propre'}</span>}
                                                    </span>
                                                </td>
                                                <td className="r mono">{formatNumber(s.contacts)}</td>
                                                <td>
                                                    <span className="mdash-minibar">
                                                        <i style={{ width: `${rate}%` }} />
                                                    </span>
                                                    <span className="mono">{Math.round(rate)} %</span>
                                                </td>
                                                <td className="r mono">{formatNumber(s.vip)}</td>
                                                <td className="r mono">{formatNumber(s.messages)}</td>
                                                <td className="r mono">{formatCompact(s.revenue)} F</td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <div style={{ marginTop: 10 }}>
                        <Link href="/stores" className="mdash-link">
                            Configurer les boutiques →
                        </Link>
                    </div>
                </article>

                {apiEnabled && (
                <article className="mdash-panel c5">
                    <div className="mdash-ph">
                        <h2>Dernières campagnes</h2>
                        <Link href="/campaigns" className="mdash-link">
                            Toutes les campagnes
                        </Link>
                    </div>
                    {recentCampaigns.length === 0 ? (
                        <div className="mdash-empty">Aucune campagne sur la période.</div>
                    ) : (
                        <div className="mdash-tbl">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Campagne</th>
                                        <th>Statut</th>
                                        <th>Envoi</th>
                                        <th className="r">Échecs</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentCampaigns.map((c) => {
                                        const [statusLabel, statusClass] = CAMPAIGN_STATUS[c.status] ?? [c.status, 'idle'];
                                        const done = c.sent_count + c.failed_count;
                                        const progress = c.total ? (done / c.total) * 100 : 0;
                                        return (
                                            <tr key={c.id}>
                                                <td className="cell-main wrap">
                                                    <b>{c.name}</b>
                                                    <span>
                                                        {c.store_names} · {c.created_at}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span className={`mdash-pill ${statusClass}`}>{statusLabel}</span>
                                                </td>
                                                <td>
                                                    <span className="mdash-minibar">
                                                        <i style={{ width: `${progress}%` }} />
                                                    </span>
                                                    <span className="mono">
                                                        {formatNumber(done)}/{formatNumber(c.total)}
                                                    </span>
                                                </td>
                                                <td className="r mono" style={c.failed_count ? { color: 'var(--bad)' } : undefined}>
                                                    {formatNumber(c.failed_count)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </article>
                )}

                <article className="mdash-panel c12">
                    <div className="mdash-ph">
                        <div>
                            <h2>Top 10 des meilleures catégories</h2>
                            <div className="mdash-sub">
                                {metric === 'revenue'
                                    ? "Classées par chiffre d'affaires des achats clients"
                                    : metric === 'clients'
                                      ? 'Classées par nombre de clients distincts'
                                      : "Classées par nombre d'achats enregistrés"}
                            </div>
                        </div>
                        <div className="mdash-switch" role="group" aria-label="Classer par">
                            {(
                                [
                                    ['revenue', 'CA'],
                                    ['clients', 'Clients'],
                                    ['purchases', 'Achats'],
                                ] as const
                            ).map(([key, label]) => (
                                <button key={key} type="button" aria-pressed={metric === key} onClick={() => setMetric(key)}>
                                    {label}
                                </button>
                            ))}
                        </div>
                    </div>
                    {top10.length === 0 ? (
                        <div className="mdash-empty">
                            Aucun achat sur la période. <Link href="/purchases" className="mdash-link">Importer un historique d'achats</Link>
                        </div>
                    ) : (
                        <div className="mdash-top10">
                            {top10.map((c, i) => (
                                <div key={c.name} className="mdash-row10">
                                    <span className="rank">{i + 1}</span>
                                    <span className="cat" title={c.name}>
                                        {c.name}
                                    </span>
                                    <span className="bar">
                                        <i style={{ width: `${(c[metric] / topMax) * 100}%` }} />
                                    </span>
                                    <span className="amt num">{metric === 'revenue' ? `${formatCompact(c.revenue)} F` : formatNumber(c[metric])}</span>
                                    <span className="cli num">{metric === 'revenue' ? `${formatNumber(c.clients)} cl.` : `${formatCompact(c.revenue)} F`}</span>
                                </div>
                            ))}
                        </div>
                    )}
                </article>
            </section>
        </div>
    );
}

DashboardIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default DashboardIndex;
