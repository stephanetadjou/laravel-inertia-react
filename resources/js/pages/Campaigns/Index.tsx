import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Pager, Paginated, Panel, PlusIcon, SearchFilter, SelectFilter, StatStrip, formatDay, useQueryFilters } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';
import CreateCampaignModal from './CreateCampaignModal';

interface CampaignRow {
    id: number;
    name: string;
    store_names: string;
    message_type: 'template' | 'text';
    template_name: string | null;
    group_names: string[];
    status: 'draft' | 'queued' | 'sending' | 'completed';
    sent_count: number;
    failed_count: number;
    total: number;
    created_at: string;
}

interface Filters {
    q: string;
    status: string | null;
    type: string | null;
    store: number | null;
    period: string | null;
    per_page: number;
}

interface PageProps {
    campaigns: Paginated<CampaignRow>;
    stats: {
        total_campaigns: number;
        active_campaigns: number;
        total_recipients: number;
        total_sent: number;
        total_failed: number;
        delivery_rate: number;
        failure_rate: number;
    };
    filters: Filters;
    stores: { id: number; name: string }[];
    groups: { id: number; name: string }[];
    templates: { id: number; name: string; language: string }[];
    [key: string]: unknown;
}

export const CAMPAIGN_STATUS: Record<CampaignRow['status'], { label: string; pill: string }> = {
    draft: { label: 'Brouillon', pill: 'idle' },
    queued: { label: 'En file', pill: 'idle' },
    sending: { label: 'En cours', pill: 'warn' },
    completed: { label: 'Terminée', pill: 'ok' },
};

function Progress({ c }: { c: CampaignRow }) {
    const done = c.sent_count + c.failed_count;
    const pct = (n: number) => (c.total > 0 ? `${(n / c.total) * 100}%` : '0%');

    return (
        <div className="mpage-progress" title={`${c.sent_count} envoyés, ${c.failed_count} échecs, ${c.total} destinataires`}>
            <span className="bar">
                <i className="ok" style={{ width: pct(c.sent_count) }} />
                <i className="bad" style={{ width: pct(c.failed_count) }} />
            </span>
            <small>
                {formatNumber(done)} / {formatNumber(c.total)} traités
            </small>
        </div>
    );
}

function CampaignsIndex() {
    const { campaigns, stats, filters, stores, groups, templates } = usePage<PageProps>().props;
    // ?group=ID (depuis la page Groupes) : ouvre la création avec ce groupe présélectionné.
    const requestedGroup = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '').get('group');
    const presetGroup = groups.some((g) => String(g.id) === requestedGroup) ? requestedGroup : null;
    const [isCreateOpen, setIsCreateOpen] = useState(presetGroup !== null);
    const apply = useQueryFilters('/campaigns', filters as unknown as Record<string, unknown>);
    const filtered = Boolean(filters.q || filters.status || filters.type || filters.store || filters.period);

    return (
        <Page
            title="Campagnes"
            subtitle="Envois groupés depuis le numéro WhatsApp de chaque boutique, aux seuls contacts opt-in pour le marketing"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setIsCreateOpen(true)}>
                    <PlusIcon />
                    Nouvelle campagne
                </button>
            }
            filters={
                <>
                    <SearchFilter value={filters.q} placeholder="Nom ou modèle" onSearch={(q) => apply({ q })} />
                    <SelectFilter
                        label="Statut"
                        value={filters.status}
                        allLabel="Tous"
                        options={Object.entries(CAMPAIGN_STATUS).map(([value, s]) => ({ value, label: s.label }))}
                        onChange={(status) => apply({ status })}
                    />
                    <SelectFilter
                        label="Type"
                        value={filters.type}
                        allLabel="Tous"
                        options={[
                            { value: 'template', label: 'Modèle Meta' },
                            { value: 'text', label: 'Message libre' },
                        ]}
                        onChange={(type) => apply({ type })}
                    />
                    {stores.length > 1 && (
                        <SelectFilter
                            label="Boutique"
                            value={filters.store}
                            allLabel="Toutes"
                            options={stores.map((s) => ({ value: s.id, label: s.name }))}
                            onChange={(store) => apply({ store })}
                        />
                    )}
                    <SelectFilter
                        label="Créées"
                        value={filters.period}
                        allLabel="Depuis le début"
                        options={[
                            { value: '7d', label: '7 derniers jours' },
                            { value: '30d', label: '30 derniers jours' },
                            { value: '90d', label: '90 derniers jours' },
                        ]}
                        onChange={(period) => apply({ period })}
                    />
                    {filtered && (
                        <button type="button" className="mdash-btn ghost sm" onClick={() => apply({ q: null, status: null, type: null, store: null, period: null })}>
                            Effacer les filtres
                        </button>
                    )}
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Campagnes', value: stats.total_campaigns, hint: `${formatNumber(stats.active_campaigns)} en cours ou en file` },
                    { label: 'Destinataires', value: stats.total_recipients },
                    { label: 'Envoyés', value: stats.total_sent, hint: `${stats.delivery_rate} % des destinataires`, tone: 'ok' },
                    { label: 'Échecs', value: stats.total_failed, hint: `${stats.failure_rate} % des destinataires`, tone: stats.total_failed ? 'bad' : undefined },
                ]}
            />
            <p className="mdash-scope">
                « Envoyé » = accepté par Meta. Les statuts délivré / lu arriveront avec le webhook.
            </p>

            <Panel>
                {campaigns.data.length === 0 ? (
                    <Empty title={filtered ? 'Aucune campagne ne correspond aux filtres' : 'Aucune campagne pour l’instant'}>
                        {filtered ? 'Modifiez ou effacez les filtres.' : 'Créez une première campagne à partir d’un modèle approuvé par Meta.'}
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Campagne</th>
                                    <th>Boutique(s)</th>
                                    <th>Message</th>
                                    <th>Statut</th>
                                    <th>Progression</th>
                                    <th className="r">Échecs</th>
                                    <th className="r">Créée le</th>
                                </tr>
                            </thead>
                            <tbody>
                                {campaigns.data.map((c) => (
                                    <tr key={c.id}>
                                        <td className="cell-main wrap">
                                            <b>{c.name}</b>
                                            {c.group_names.length > 0 && <span>Groupe : {c.group_names.join(', ')}</span>}
                                        </td>
                                        <td className="wrap">{c.store_names}</td>
                                        <td>
                                            {c.message_type === 'text' ? (
                                                <span className="mdash-pill info">Message libre</span>
                                            ) : (
                                                <span className="mono">{c.template_name}</span>
                                            )}
                                        </td>
                                        <td>
                                            <span className={`mdash-pill ${CAMPAIGN_STATUS[c.status]?.pill ?? 'idle'}`}>{CAMPAIGN_STATUS[c.status]?.label ?? c.status}</span>
                                        </td>
                                        <td>
                                            <Progress c={c} />
                                        </td>
                                        <td className="r">
                                            {c.failed_count > 0 ? (
                                                <a className="mdash-link" href="/chat?tab=failed&type=campaign&period=all" title="Voir les échecs dans l'historique messages">
                                                    {formatNumber(c.failed_count)}
                                                </a>
                                            ) : (
                                                <span className="mpage-muted">0</span>
                                            )}
                                        </td>
                                        <td className="r mono">{formatDay(c.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={campaigns} unit="campagnes" onPage={(page) => apply({ page })} perPage={filters.per_page} onPerPage={(per_page) => apply({ per_page })} />
            </Panel>

            <CreateCampaignModal
                isOpen={isCreateOpen}
                onClose={() => setIsCreateOpen(false)}
                stores={stores}
                groups={groups}
                templates={templates}
                initialGroupIds={presetGroup ? [presetGroup] : []}
            />
        </Page>
    );
}

CampaignsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CampaignsIndex;
