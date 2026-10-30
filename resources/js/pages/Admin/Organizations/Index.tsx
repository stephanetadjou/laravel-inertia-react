import { Link, usePage } from '@inertiajs/react';

import AppLayout from '../../../layout/AppLayout';
import { Empty, formatDay, Page, Pager, Paginated, Panel, SearchFilter, SelectFilter, useQueryFilters } from '../../../components/page/Page';
import { formatNumber } from '../../../components/dashboard/Charts';
import { AdminOrganization, Option, StatusPill } from '../shared';

/*
 * Back-office plateforme › Entreprises : toutes les entreprises inscrites,
 * avec recherche et filtres (statut, secteur, offre, plan).
 */

interface PageProps {
    filters: { q: string | null; status: string | null; sector: string | null; plan: string | null; offer: string | null };
    options: { statuses: Option[]; sectors: Option[]; plans: Option[]; offers: Option[] };
    organizations: Paginated<AdminOrganization>;
    [key: string]: unknown;
}

function OrganizationsIndex() {
    const { filters, options, organizations } = usePage<PageProps>().props;
    const apply = useQueryFilters('/admin/organizations', filters);

    return (
        <Page
            title="Entreprises"
            subtitle={`${formatNumber(organizations.total)} entreprise(s)`}
            filters={
                <>
                    <SearchFilter value={filters.q ?? ''} placeholder="Nom de l’entreprise" onSearch={(q) => apply({ q })} />
                    <SelectFilter label="Statut" value={filters.status} options={options.statuses} allLabel="Tous" onChange={(v) => apply({ status: v })} />
                    <SelectFilter label="Offre" value={filters.offer} options={options.offers} allLabel="Toutes" onChange={(v) => apply({ offer: v })} />
                    <SelectFilter label="Secteur" value={filters.sector} options={options.sectors} allLabel="Tous" onChange={(v) => apply({ sector: v })} />
                    <SelectFilter label="Plan" value={filters.plan} options={options.plans} allLabel="Tous" onChange={(v) => apply({ plan: v })} />
                </>
            }
        >
            <Panel>
                {organizations.data.length === 0 ? (
                    <Empty title="Aucune entreprise">Aucune entreprise ne correspond à ces filtres.</Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Entreprise</th>
                                    <th>Secteur</th>
                                    <th>Offre</th>
                                    <th>Statut</th>
                                    <th>Inscrite le</th>
                                    <th>Fin d’essai</th>
                                    <th className="r">Utilisateurs</th>
                                    <th className="r">Clients</th>
                                    <th>Dernière activité</th>
                                </tr>
                            </thead>
                            <tbody>
                                {organizations.data.map((o) => (
                                    <tr key={o.id}>
                                        <td className="cell-main">
                                            <Link href={`/admin/organizations/${o.id}`} className="mdash-link">
                                                <b>{o.name}</b>
                                            </Link>
                                        </td>
                                        <td>{o.sector_label}</td>
                                        <td>
                                            {o.offer} · {o.plan_label}
                                        </td>
                                        <td>
                                            <StatusPill organization={o} />
                                        </td>
                                        <td>{formatDay(o.created_at)}</td>
                                        <td>{o.status === 'active' ? '—' : formatDay(o.trial_ends_at)}</td>
                                        <td className="r num">{formatNumber(o.users_count)}</td>
                                        <td className="r num">{formatNumber(o.customers_count)}</td>
                                        <td>{o.last_activity ? formatDay(o.last_activity, true) : <span className="mpage-muted">Jamais</span>}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={organizations} onPage={(n) => apply({ page: n })} unit="entreprises" />
            </Panel>
        </Page>
    );
}

OrganizationsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OrganizationsIndex;
