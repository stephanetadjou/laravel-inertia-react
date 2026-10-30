import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, formatDay, Page, Pager, Paginated, Panel, SearchFilter, SelectFilter, useQueryFilters } from '../../components/page/Page';
import { formatNumber } from '../../components/dashboard/Charts';
import { fieldClasses, Option } from './shared';

/*
 * Back-office plateforme › Demandes de démo reçues du site vitrine : statut,
 * note de suivi, export Excel.
 */

interface Lead {
    id: number;
    created_at: string | null;
    name: string;
    company: string;
    whatsapp: string;
    city: string | null;
    activity: string | null;
    message: string | null;
    status: string;
    note: string | null;
    contacted_at: string | null;
}

interface PageProps {
    filters: { q: string | null; status: string | null };
    statuses: Option[];
    counts: Record<string, number>;
    leads: Paginated<Lead>;
    [key: string]: unknown;
}

const TONES: Record<string, string> = { new: 'warn', contacted: 'info', converted: 'ok', lost: 'idle' };

function LeadRow({ lead, statuses }: { lead: Lead; statuses: Option[] }) {
    const [note, setNote] = useState(lead.note ?? '');
    const save = (status: string, nextNote = note) => router.put(`/admin/leads/${lead.id}`, { status, note: nextNote || null }, { preserveScroll: true, preserveState: true });

    return (
        <tr>
            <td>{formatDay(lead.created_at, true)}</td>
            <td className="cell-main">
                <b>{lead.company}</b>
                <div className="mpage-muted">
                    {lead.name}
                    {lead.city ? ` · ${lead.city}` : ''}
                    {lead.activity ? ` · ${lead.activity}` : ''}
                </div>
                {lead.message && <div className="mpage-muted">« {lead.message} »</div>}
            </td>
            <td>
                <a href={`https://wa.me/${lead.whatsapp.replace(/\D/g, '')}`} target="_blank" rel="noopener noreferrer" className="mdash-link">
                    {lead.whatsapp}
                </a>
            </td>
            <td>
                <span className={`mdash-pill ${TONES[lead.status] ?? ''}`}>{statuses.find((s) => s.value === lead.status)?.label ?? lead.status}</span>
                <select className={`${fieldClasses()} mt-1`} value={lead.status} aria-label="Statut" onChange={(e) => save(e.target.value)}>
                    {statuses.map((s) => (
                        <option key={s.value} value={s.value}>
                            {s.label}
                        </option>
                    ))}
                </select>
            </td>
            <td>
                <textarea
                    className={fieldClasses()}
                    rows={2}
                    value={note}
                    placeholder="Note de suivi"
                    aria-label="Note de suivi"
                    onChange={(e) => setNote(e.target.value)}
                    onBlur={() => note !== (lead.note ?? '') && save(lead.status)}
                />
            </td>
        </tr>
    );
}

function AdminLeads() {
    const { filters, statuses, counts, leads } = usePage<PageProps>().props;
    const apply = useQueryFilters('/admin/leads', filters);
    const exportUrl = `/admin/leads/export?${new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][]).toString()}`;

    return (
        <Page
            title="Demandes de démo"
            subtitle={statuses.map((s) => `${formatNumber(counts[s.value] ?? 0)} ${s.label.toLowerCase()}`).join(' · ')}
            actions={
                <a href={exportUrl} className="mdash-btn ghost">
                    Exporter (Excel)
                </a>
            }
            filters={
                <>
                    <SearchFilter value={filters.q ?? ''} placeholder="Nom, entreprise, ville, numéro" onSearch={(q) => apply({ q })} />
                    <SelectFilter label="Statut" value={filters.status} options={statuses} allLabel="Tous" onChange={(v) => apply({ status: v })} />
                </>
            }
        >
            <Panel>
                {leads.data.length === 0 ? (
                    <Empty title="Aucune demande">Les demandes du formulaire menentia.com/contact arrivent ici.</Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Reçue le</th>
                                    <th>Entreprise</th>
                                    <th>WhatsApp</th>
                                    <th>Statut</th>
                                    <th>Note</th>
                                </tr>
                            </thead>
                            <tbody>
                                {leads.data.map((lead) => (
                                    <LeadRow key={lead.id} lead={lead} statuses={statuses} />
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={leads} onPage={(n) => apply({ page: n })} unit="demandes" />
            </Panel>
        </Page>
    );
}

AdminLeads.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AdminLeads;
