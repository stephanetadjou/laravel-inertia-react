import { usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { Empty, formatDay, Page, Pager, Paginated, Panel, SelectFilter, useQueryFilters } from '../../components/page/Page';

interface Log {
    id: number;
    at: string | null;
    user: string | null;
    action: string;
    action_label: string;
    description: string | null;
    payload: Record<string, unknown> | null;
    ip: string | null;
}

interface PageProps {
    filters: { action: string | null; user: number | null };
    actions: { value: string; label: string }[];
    users: { value: number; label: string }[];
    logs: Paginated<Log>;
    [key: string]: unknown;
}

function AuditIndex() {
    const { filters, actions, users, logs } = usePage<PageProps>().props;
    const apply = useQueryFilters('/settings/audit', filters);

    return (
        <Page
            title="Journal"
            subtitle="Qui a importé, exporté, fusionné ou modifié quoi, et quand"
            filters={
                <>
                    <SelectFilter label="Action" value={filters.action} options={actions} allLabel="Toutes" onChange={(v) => apply({ action: v })} />
                    <SelectFilter label="Utilisateur" value={filters.user} options={users} allLabel="Tous" onChange={(v) => apply({ user: v })} />
                </>
            }
        >
            <Panel>
                {logs.data.length === 0 ? (
                    <Empty title="Aucune entrée">Les imports, exports, fusions de fiches et changements de paramètres apparaîtront ici.</Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Utilisateur</th>
                                    <th>Action</th>
                                    <th>Détail</th>
                                    <th>Adresse IP</th>
                                </tr>
                            </thead>
                            <tbody>
                                {logs.data.map((log) => (
                                    <tr key={log.id}>
                                        <td>{formatDay(log.at, true)}</td>
                                        <td>{log.user ?? <span className="mpage-muted">Système</span>}</td>
                                        <td>
                                            <span className="mdash-pill idle">{log.action_label}</span>
                                        </td>
                                        <td className="wrap">
                                            {log.description}
                                            {log.payload && <div className="mpage-code">{JSON.stringify(log.payload)}</div>}
                                        </td>
                                        <td className="mpage-muted">{log.ip ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={logs} onPage={(n) => apply({ page: n })} unit="entrées" />
            </Panel>
        </Page>
    );
}

AuditIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default AuditIndex;
