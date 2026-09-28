import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';
import { AlertIcon, ChatIcon, CheckCircleIcon, GroupIcon } from '../../icons';
import CreateCampaignModal from './CreateCampaignModal';

interface CampaignRow {
    id: number;
    name: string;
    store_names: string;
    template_name: string;
    status: string;
    sent_count: number;
    failed_count: number;
    total: number;
    created_at: string;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    links: PaginationLink[];
}

interface Stats {
    total_campaigns: number;
    active_campaigns: number;
    total_recipients: number;
    total_sent: number;
    total_failed: number;
    delivery_rate: number;
    failure_rate: number;
}

interface StoreOption {
    id: number;
    name: string;
}

interface GroupOption {
    id: number;
    name: string;
}

interface TemplateOption {
    id: number;
    name: string;
    language: string;
}

interface PageProps {
    campaigns: Paginated<CampaignRow>;
    stats: Stats;
    perPage: number;
    stores: StoreOption[];
    groups: GroupOption[];
    templates: TemplateOption[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

const statusColor: Record<string, 'success' | 'warning' | 'info' | 'light'> = {
    queued: 'info',
    sending: 'warning',
    completed: 'success',
};

function StatCard({
    label,
    value,
    hint,
    icon,
    iconClassName,
}: {
    label: string;
    value: string;
    hint?: string;
    icon: React.ReactNode;
    iconClassName: string;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="flex items-start justify-between">
                <div>
                    <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
                    <p className="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{value}</p>
                </div>
                <span className={`flex h-10 w-10 items-center justify-center rounded-full ${iconClassName}`}>{icon}</span>
            </div>
            {hint && <p className="mt-1 text-xs text-gray-400 dark:text-gray-500">{hint}</p>}
        </div>
    );
}

function CampaignsIndex() {
    const { campaigns, stats, perPage, stores, groups, templates, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [isCreateOpen, setIsCreateOpen] = useState(false);

    function changePerPage(value: string) {
        router.get('/campaigns', { per_page: value }, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Campagnes" />

            <PageBreadcrumb pageTitle="Campagnes" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Campagnes"
                        value={String(stats.total_campaigns)}
                        hint={`${stats.active_campaigns} active(s)`}
                        icon={<ChatIcon className="h-5 w-5" />}
                        iconClassName="bg-brand-50 text-brand-500 dark:bg-brand-500/15"
                    />
                    <StatCard
                        label="Destinataires totaux"
                        value={String(stats.total_recipients)}
                        hint={`${stats.total_sent} envoyés`}
                        icon={<GroupIcon className="h-5 w-5" />}
                        iconClassName="bg-blue-light-50 text-blue-light-500 dark:bg-blue-light-500/15"
                    />
                    <StatCard
                        label="Taux d'envoi réussi"
                        value={`${stats.delivery_rate}%`}
                        hint={`${stats.total_sent} délivrés`}
                        icon={<CheckCircleIcon className="h-5 w-5" />}
                        iconClassName="bg-success-50 text-success-500 dark:bg-success-500/15"
                    />
                    <StatCard
                        label="Messages en échec"
                        value={String(stats.total_failed)}
                        hint={`${stats.failure_rate}% taux d'échec`}
                        icon={<AlertIcon className="h-5 w-5" />}
                        iconClassName="bg-error-50 text-error-500 dark:bg-error-500/15"
                    />
                </div>
                <p className="text-xs text-gray-400 dark:text-gray-500">
                    Pas de « taux de lecture » affiché : les accusés de lecture Meta ne sont pas encore suivis (webhook de statut à venir).
                </p>

                <div className="flex justify-end">
                    <Button type="button" onClick={() => setIsCreateOpen(true)}>
                        Nouvelle campagne
                    </Button>
                </div>

                <ComponentCard title="Campagnes" desc="Envois groupés par boutique, avec suivi en temps réel">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Campagne
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Boutique(s)
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Template
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Statut
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Envoyés / Échecs / Total
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Créée le
                                    </TableCell>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {campaigns.data.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={6}>
                                            Aucune campagne pour l'instant.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {campaigns.data.map((campaign) => (
                                    <TableRow key={campaign.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{campaign.name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{campaign.store_names}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{campaign.template_name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            <Badge size="sm" color={statusColor[campaign.status] ?? 'light'}>
                                                {campaign.status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {campaign.sent_count} / {campaign.failed_count} / {campaign.total}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{campaign.created_at}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <div className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <span>
                                {campaigns.total === 0
                                    ? 'Aucune campagne'
                                    : `Page ${campaigns.current_page} sur ${campaigns.last_page} (${campaigns.total} au total)`}
                            </span>
                            <select
                                value={perPage}
                                onChange={(e) => changePerPage(e.target.value)}
                                className="rounded-lg border border-gray-300 px-2 py-1 text-sm text-gray-700 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300"
                            >
                                <option value={10}>10 / page</option>
                                <option value={25}>25 / page</option>
                                <option value={50}>50 / page</option>
                            </select>
                        </div>

                        <div className="flex gap-1">
                            {campaigns.links.map((link, index) => (
                                <button
                                    key={index}
                                    disabled={!link.url}
                                    onClick={() => link.url && router.get(link.url, {}, { preserveState: true })}
                                    className={`rounded-md px-3 py-1.5 text-sm ${
                                        link.active
                                            ? 'bg-brand-500 text-white'
                                            : 'text-gray-600 hover:bg-gray-100 disabled:opacity-40 dark:text-gray-400 dark:hover:bg-white/5'
                                    }`}
                                    dangerouslySetInnerHTML={{ __html: link.label }}
                                />
                            ))}
                        </div>
                    </div>
                </ComponentCard>
            </div>

            <CreateCampaignModal
                isOpen={isCreateOpen}
                onClose={() => setIsCreateOpen(false)}
                stores={stores}
                groups={groups}
                templates={templates}
            />
        </>
    );
}

CampaignsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CampaignsIndex;
