import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { STAGES, Stage, VIP_PILL, stageInfo } from '../../components/dashboard/stages';
import NewContactModal from '../Contacts/NewContactModal';
import SelectionCampaignModal, { CampaignTemplate } from './SelectionCampaignModal';
import CustomerEditModal, { EditableContact } from './CustomerEditModal';
import '../Dashboard/dashboard.css';
import './customers.css';

type Consent = 'opt_in' | 'unknown' | 'opt_out';

interface Filters {
    stage: Stage | null;
    vip: boolean;
    segment: string | null;
    risk: 'medium' | 'high' | 'any' | null;
    consent: Consent | null;
    q: string;
    sort: 'last_purchase' | 'total_spent' | 'name' | 'score';
    per_page: number;
    brand: number | null;
    brand_locked: boolean;
    brand_hidden: boolean;
    store: number | null;
    group: number | null;
    groups: { id: number; name: string }[];
    brands: { id: number; name: string }[];
    label: string;
}

interface CustomerRow {
    id: number;
    name: string;
    phone_e164: string;
    gender: 'H' | 'F' | null;
    birthdate: string | null;
    contacts: EditableContact[];
    can_edit: boolean;
    stage: Stage;
    segment: string | null;
    score: number | null;
    churn_risk: 'medium' | 'high' | null;
    stores: { name: string; vip: boolean; consent: Consent }[];
    opt_in: boolean;
    purchases_count: number;
    total_spent: number;
    last_purchase_at: string | null;
}

interface PageProps {
    filters: Filters;
    stores: { id: number; name: string }[];
    stages: { key: Stage; label: string; count: number }[];
    vipCount: number;
    segments: { key: string; label: string }[];
    riskCount: number;
    apiEnabled: boolean;
    consentCounts: Record<Consent, number>;
    reachableCount: number;
    templates: CampaignTemplate[];
    customers: { data: CustomerRow[]; current_page: number; last_page: number; from: number | null; to: number | null; total: number };
    flash?: { success: string | null; error: string | null };
    [key: string]: unknown;
}

type QueryValue = string | number | null;

const STAGE_COLOR: Record<string, string> = {
    idle: 'var(--faint)',
    info: 'var(--data)',
    ok: 'var(--ok)',
    accent: 'var(--accent)',
    warn: 'var(--warn)',
    bad: 'var(--bad)',
};

const CONSENTS: { key: Consent; label: string; hint: string }[] = [
    { key: 'opt_in', label: 'Opt-in', hint: 'A donné son accord : peut recevoir les campagnes' },
    { key: 'unknown', label: 'Inconnu', hint: 'Consentement non recueilli : exclu des campagnes' },
    { key: 'opt_out', label: 'Opt-out', hint: 'A refusé : ne jamais contacter' },
];

const CONSENT_LABEL: Record<Consent, string> = { opt_in: 'opt-in', unknown: 'consentement inconnu', opt_out: 'opt-out' };

function formatDate(day: string | null): string {
    if (!day) return '—';
    const [y, m, d] = day.split('-');
    return `${d}/${m}/${y}`;
}

function plural(n: number, word: string, pluralWord = word + 's'): string {
    return `${formatNumber(n)} ${n > 1 ? pluralWord : word}`;
}

function CustomersIndex() {
    const { filters, stores, stages, vipCount, segments, riskCount, apiEnabled, consentCounts, reachableCount, templates, customers, flash } = usePage<PageProps>().props;
    const segmentLabel = (key: string | null) => segments.find((s) => s.key === key)?.label ?? '—';
    const [search, setSearch] = useState(filters.q);
    const [isNewContactOpen, setIsNewContactOpen] = useState(false);
    const [isCampaignOpen, setIsCampaignOpen] = useState(false);
    const [editing, setEditing] = useState<CustomerRow | null>(null);
    const [groupTarget, setGroupTarget] = useState('');
    const [groupBusy, setGroupBusy] = useState(false);
    // Sélection : des fiches cochées (conservées d'une page à l'autre), ou tout le filtre.
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [allMatching, setAllMatching] = useState(false);
    const total = stages.reduce((sum, s) => sum + s.count, 0);

    useEffect(() => setSearch(filters.q), [filters.q]);

    // Filtres transmis à l'export, à l'aperçu d'audience et à la campagne.
    const activeFilters = useMemo(
        () =>
            Object.fromEntries(
                Object.entries({
                    stage: filters.stage,
                    vip: filters.vip ? 1 : null,
                    segment: filters.segment,
                    risk: filters.risk,
                    consent: filters.consent,
                    q: filters.q || null,
                    brand: filters.brand_locked ? null : filters.brand,
                    store: filters.store,
                    group: filters.group,
                }).filter(([, v]) => v !== null && v !== ''),
            ) as Record<string, string | number>,
        [filters.stage, filters.vip, filters.segment, filters.risk, filters.consent, filters.q, filters.brand, filters.brand_locked, filters.store, filters.group],
    );

    // Un changement de filtre vide la sélection (elle ne correspondrait plus).
    const filtersKey = JSON.stringify(activeFilters);
    useEffect(() => {
        setSelected(new Set());
        setAllMatching(false);
    }, [filtersKey]);

    function visit(overrides: Record<string, QueryValue>) {
        const current: Record<string, QueryValue> = {
            ...activeFilters,
            sort: filters.sort === 'last_purchase' ? null : filters.sort,
            per_page: filters.per_page === 25 ? null : filters.per_page,
            page: null,
        };
        const query = Object.fromEntries(Object.entries({ ...current, ...overrides }).filter(([, v]) => v !== null && v !== '')) as Record<string, string | number>;
        router.get('/customers', query, { preserveState: true, preserveScroll: true, replace: !('page' in overrides) });
    }

    useEffect(() => {
        if (search === filters.q) return;
        const timer = setTimeout(() => visit({ q: search.trim() || null }), 350);
        return () => clearTimeout(timer);
    }, [search]);

    const exportUrl = '/customers/export?' + new URLSearchParams(Object.entries(activeFilters).map(([k, v]) => [k, String(v)])).toString();

    // --- Sélection ---
    const pageIds = customers.data.map((c) => c.id);
    const pageAllSelected = allMatching || (pageIds.length > 0 && pageIds.every((id) => selected.has(id)));
    const pageSomeSelected = !pageAllSelected && pageIds.some((id) => selected.has(id));
    const selectionCount = allMatching ? customers.total : selected.size;

    function toggleOne(id: number) {
        setAllMatching(false);
        setSelected((prev) => {
            const next = new Set(allMatching ? pageIds : prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function togglePage() {
        if (pageAllSelected) {
            setAllMatching(false);
            setSelected((prev) => {
                const next = new Set(prev);
                pageIds.forEach((id) => next.delete(id));
                return next;
            });
        } else {
            setSelected((prev) => new Set([...prev, ...pageIds]));
        }
    }

    function clearSelection() {
        setSelected(new Set());
        setAllMatching(false);
    }

    // Groupes : ajouter la sélection à un groupe, ou la retirer du groupe affiché.
    const currentGroup = filters.groups.find((g) => g.id === filters.group) ?? null;

    function changeGroup(groupId: number | string, remove = false) {
        if (!groupId) return;
        const payload = { all: allMatching ? 1 : 0, customer_ids: allMatching ? [] : [...selected], filters: activeFilters };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                clearSelection();
                setGroupTarget('');
            },
            onFinish: () => setGroupBusy(false),
        };
        setGroupBusy(true);
        if (remove) router.delete(`/groups/${groupId}/customers`, { ...options, data: payload });
        else router.post(`/groups/${groupId}/customers`, payload, options);
    }

    return (
        <div className="mdash mcust">
            <Head title="Fiches clients" />

            <header className="mdash-top mcust-top">
                <div className="mdash-top-in">
                    <div className="mcust-title">
                        <div>
                            <h1>Fiches clients</h1>
                            <small>Une fiche par personne, pour tous vos établissements</small>
                        </div>
                        <div className="mcust-actions">
                            <a href={exportUrl} className="mdash-btn ghost">
                                Exporter (CSV)
                            </a>
                            <button type="button" className="mdash-btn brand" disabled={stores.length === 0} onClick={() => setIsNewContactOpen(true)}>
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
                                    <path d="M12 5v14M5 12h14" />
                                </svg>
                                Nouveau client
                            </button>
                        </div>
                    </div>
                    <div className="mcust-actions">
                        <input
                            type="search"
                            className="mcust-search"
                            aria-label="Rechercher un client"
                            placeholder="Nom ou numéro"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        {!filters.brand_hidden && (
                            <select
                                aria-label="Marque"
                                value={filters.brand ?? ''}
                                disabled={filters.brand_locked}
                                onChange={(e) => visit({ brand: e.target.value ? Number(e.target.value) : null, store: null })}
                            >
                                {!filters.brand_locked && <option value="">Toutes les marques</option>}
                                {filters.brands.map((b) => (
                                    <option key={b.id} value={b.id}>
                                        {b.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {!filters.brand_hidden && stores.length > 1 && (
                            <select aria-label="Boutique" value={filters.store ?? ''} onChange={(e) => visit({ store: e.target.value ? Number(e.target.value) : null })}>
                                <option value="">Toutes les boutiques</option>
                                {stores.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        {filters.groups.length > 0 && (
                            <select aria-label="Groupe" value={filters.group ?? ''} onChange={(e) => visit({ group: e.target.value ? Number(e.target.value) : null })}>
                                <option value="">Tous les groupes</option>
                                {filters.groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        Groupe : {g.name}
                                    </option>
                                ))}
                            </select>
                        )}
                        <select aria-label="Segment RFM" value={filters.segment ?? ''} onChange={(e) => visit({ segment: e.target.value || null })}>
                            <option value="">Tous les segments RFM</option>
                            {segments.map((s) => (
                                <option key={s.key} value={s.key}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                        <select aria-label="Trier par" value={filters.sort} onChange={(e) => visit({ sort: e.target.value === 'last_purchase' ? null : e.target.value })}>
                            <option value="last_purchase">Dernier achat</option>
                            <option value="total_spent">CA total</option>
                            <option value="score">Score client</option>
                            <option value="name">Nom</option>
                        </select>
                    </div>
                </div>
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            <nav className="mcust-funnel" aria-label="Filtrer par étape du parcours">
                <button type="button" className="mcust-step" aria-pressed={filters.stage === null && !filters.vip} onClick={() => visit({ stage: null, vip: null })}>
                    <i style={{ background: 'var(--ink)' }} />
                    <span>Tous les clients</span>
                    <b className="num">{formatNumber(total)}</b>
                </button>
                {stages.map((s) => (
                    <button
                        key={s.key}
                        type="button"
                        className="mcust-step"
                        aria-pressed={filters.stage === s.key}
                        title={STAGES[s.key]?.hint}
                        onClick={() => visit({ stage: filters.stage === s.key ? null : s.key })}
                    >
                        <i style={{ background: STAGE_COLOR[stageInfo(s.key).pill] }} />
                        <span>{s.label}</span>
                        <b className="num">{formatNumber(s.count)}</b>
                    </button>
                ))}
                <button
                    type="button"
                    className="mcust-step"
                    aria-pressed={filters.vip}
                    title={`${VIP_PILL.hint}. Un client peut être VIP dans une boutique et pas dans une autre.`}
                    onClick={() => visit({ vip: filters.vip ? null : 1 })}
                >
                    <i style={{ background: STAGE_COLOR.accent }} />
                    <span>VIP (par boutique)</span>
                    <b className="num">{formatNumber(vipCount)}</b>
                </button>
                <button
                    type="button"
                    className="mcust-step"
                    aria-pressed={filters.risk !== null}
                    title="Clients en retard sur leur rythme d'achat habituel (alerte de churn)"
                    onClick={() => visit({ risk: filters.risk ? null : 'any' })}
                >
                    <i style={{ background: STAGE_COLOR.bad }} />
                    <span>Alerte churn</span>
                    <b className="num">{formatNumber(riskCount)}</b>
                </button>
            </nav>

            <div className="mcust-consent" role="group" aria-label="Filtrer par consentement WhatsApp">
                <span className="mcust-consent-label">Consentement WhatsApp</span>
                <div className="mdash-seg">
                    <button type="button" aria-pressed={filters.consent === null} onClick={() => visit({ consent: null })}>
                        Tous
                    </button>
                    {CONSENTS.map((c) => (
                        <button key={c.key} type="button" aria-pressed={filters.consent === c.key} title={c.hint} onClick={() => visit({ consent: c.key })}>
                            <i className={`mcust-dot ${c.key}`} />
                            {c.label} <span className="mcust-count">{formatNumber(consentCounts[c.key])}</span>
                        </button>
                    ))}
                </div>
                <span className="mcust-consent-hint">
                    {apiEnabled
                        ? `${formatNumber(reachableCount)} client${reachableCount > 1 ? 's' : ''} du filtre joignable${reachableCount > 1 ? 's' : ''} par campagne (opt-in)`
                        : 'Relances manuelles par WhatsApp depuis Actions du jour : jamais les clients en opt-out'}
                </span>
            </div>

            <article className="mdash-panel mcust-table" style={{ marginTop: 12 }}>
                <div className="mdash-ph">
                    <h2>{filters.label === 'Clients' ? 'Tous les clients' : filters.label}</h2>
                    <span className="mdash-sub">{plural(customers.total, 'fiche')}</span>
                </div>

                {/* Même principe que Gmail : la case d'en-tête coche la page, puis propose tout le filtre. */}
                {pageAllSelected && !allMatching && customers.total > pageIds.length && (
                    <div className="mcust-select-all">
                        Les {plural(pageIds.length, 'client')} de cette page sont sélectionnés.{' '}
                        <button type="button" onClick={() => setAllMatching(true)}>
                            Sélectionner les {plural(customers.total, 'client')} du filtre
                        </button>
                    </div>
                )}
                {allMatching && (
                    <div className="mcust-select-all">
                        Les {plural(customers.total, 'client')} du filtre sont sélectionnés.{' '}
                        <button type="button" onClick={clearSelection}>
                            Annuler la sélection
                        </button>
                    </div>
                )}

                {customers.data.length === 0 ? (
                    <div className="mcust-empty">
                        <b>Aucune fiche client ne correspond</b>
                        <span>Modifiez la recherche, l'étape, le consentement ou la marque.</span>
                    </div>
                ) : (
                    <div className="mdash-tbl">
                        <table>
                            <thead>
                                <tr>
                                    <th className="mcust-check">
                                        <input
                                            type="checkbox"
                                            aria-label="Sélectionner les clients de la page"
                                            checked={pageAllSelected}
                                            ref={(el) => {
                                                if (el) el.indeterminate = pageSomeSelected;
                                            }}
                                            onChange={togglePage}
                                        />
                                    </th>
                                    <th>Client</th>
                                    <th>Étape · segment</th>
                                    <th className="r">Score</th>
                                    <th>Boutiques · consentement</th>
                                    <th className="r">Achats</th>
                                    <th className="r">CA total</th>
                                    <th>Dernier achat</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {customers.data.map((c) => {
                                    const stage = stageInfo(c.stage);
                                    const checked = allMatching || selected.has(c.id);
                                    return (
                                        <tr
                                            key={c.id}
                                            aria-selected={checked}
                                            className={`mrow-link ${checked ? 'selected' : ''}`}
                                            onClick={(e) => {
                                                // Toute la ligne ouvre la fiche, sauf la case à cocher et les boutons.
                                                if (!(e.target as HTMLElement).closest('input, button, a')) router.visit(`/customers/${c.id}`);
                                            }}
                                        >
                                            <td className="mcust-check">
                                                <input type="checkbox" aria-label={`Sélectionner ${c.name}`} checked={checked} onChange={() => toggleOne(c.id)} />
                                            </td>
                                            <td className="cell-main">
                                                <b>
                                                    <Link href={`/customers/${c.id}`}>{c.name}</Link>
                                                </b>
                                                <span className="mono">{c.phone_e164}</span>
                                            </td>
                                            <td>
                                                <span className={`mdash-pill ${stage.pill}`}>{stage.label}</span>
                                                {c.churn_risk && (
                                                    <span className={`mdash-pill ${c.churn_risk === 'high' ? 'bad' : 'warn'}`} title="Alerte churn">
                                                        {c.churn_risk === 'high' ? 'Risque élevé' : 'Risque moyen'}
                                                    </span>
                                                )}
                                                {c.segment && c.segment !== 'none' && <span className="mcust-sub">{segmentLabel(c.segment)}</span>}
                                            </td>
                                            <td className="r mono">{c.score ?? '—'}</td>
                                            <td>
                                                <span className="mcust-chips">
                                                    {c.stores.map((s) => (
                                                        <span
                                                            key={s.name}
                                                            className={`mcust-chip ${s.vip ? 'vip' : ''}`}
                                                            title={`${s.name} : ${CONSENT_LABEL[s.consent]}${s.vip ? ', VIP' : ''}`}
                                                        >
                                                            <i className={`mcust-dot ${s.consent}`} />
                                                            {s.vip && '★ '}
                                                            {s.name}
                                                        </span>
                                                    ))}
                                                </span>
                                            </td>
                                            <td className="r mono">{formatNumber(c.purchases_count)}</td>
                                            <td className="mcust-amount">{formatNumber(c.total_spent)} F</td>
                                            <td className="mono">{formatDate(c.last_purchase_at)}</td>
                                            <td className="r">
                                                <span className="mcust-row-actions">
                                                    {c.can_edit && (
                                                        <button type="button" className="mdash-btn ghost sm" onClick={() => setEditing(c)}>
                                                            Modifier
                                                        </button>
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
                {customers.total > 0 && (
                    <div className="mcust-pager">
                        <span className="mcust-legend">
                            <span>
                                <i className="mcust-dot opt_in" />
                                opt-in
                            </span>
                            <span>
                                <i className="mcust-dot unknown" />
                                inconnu
                            </span>
                            <span>
                                <i className="mcust-dot opt_out" />
                                opt-out
                            </span>
                            <span>★ VIP de la boutique</span>
                            <span>
                                · {customers.from}–{customers.to} sur {formatNumber(customers.total)}
                            </span>
                        </span>
                        <div className="btns">
                            <select aria-label="Lignes par page" value={filters.per_page} onChange={(e) => visit({ per_page: Number(e.target.value) === 25 ? null : Number(e.target.value) })}>
                                {[25, 50, 100].map((n) => (
                                    <option key={n} value={n}>
                                        {n} / page
                                    </option>
                                ))}
                            </select>
                            <button type="button" className="mdash-btn ghost" disabled={customers.current_page <= 1} onClick={() => visit({ page: customers.current_page - 1 })}>
                                Précédent
                            </button>
                            <button
                                type="button"
                                className="mdash-btn ghost"
                                disabled={customers.current_page >= customers.last_page}
                                onClick={() => visit({ page: customers.current_page + 1 })}
                            >
                                Suivant
                            </button>
                        </div>
                    </div>
                )}
            </article>

            {selectionCount > 0 && (
                <div className="mcust-selbar" role="region" aria-label="Clients sélectionnés">
                    <span>
                        <b className="num">{plural(selectionCount, 'client sélectionné', 'clients sélectionnés')}</b>
                        {allMatching ? ' (tout le filtre)' : ''}
                    </span>
                    <button type="button" className="mdash-btn ghost sm" onClick={clearSelection}>
                        Tout désélectionner
                    </button>
                    {filters.groups.length > 0 && (
                        <span className="mcust-groupact">
                            <select aria-label="Ajouter à un groupe" value={groupTarget} onChange={(e) => setGroupTarget(e.target.value)}>
                                <option value="">Ajouter à un groupe…</option>
                                {filters.groups.map((g) => (
                                    <option key={g.id} value={g.id}>
                                        {g.name}
                                    </option>
                                ))}
                            </select>
                            <button type="button" className="mdash-btn ghost sm" disabled={!groupTarget || groupBusy} onClick={() => changeGroup(groupTarget)}>
                                Ajouter
                            </button>
                        </span>
                    )}
                    {currentGroup && (
                        <button type="button" className="mdash-btn ghost sm" disabled={groupBusy} onClick={() => changeGroup(currentGroup.id, true)}>
                            Retirer de « {currentGroup.name} »
                        </button>
                    )}
                    {apiEnabled && (
                    <button type="button" className="mdash-btn primary" onClick={() => setIsCampaignOpen(true)}>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                            <path d="M22 2L11 13" />
                            <path d="M22 2l-7 20-4-9-9-4z" />
                        </svg>
                        Créer une campagne
                    </button>
                    )}
                </div>
            )}

            {/* Un nouveau client se crée comme contact d'une boutique ; sa fiche est créée (ou complétée) automatiquement. */}
            <NewContactModal isOpen={isNewContactOpen} onClose={() => setIsNewContactOpen(false)} stores={stores} />

            <CustomerEditModal customer={editing} contacts={editing?.contacts ?? []} isOpen={editing !== null} onClose={() => setEditing(null)} />

            <SelectionCampaignModal
                isOpen={isCampaignOpen}
                onClose={() => setIsCampaignOpen(false)}
                customerIds={allMatching ? null : [...selected]}
                filters={activeFilters}
                defaultName={filters.label}
                templates={templates}
            />
        </div>
    );
}

CustomersIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CustomersIndex;
