import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import DateRangeFilter from '../../components/common/DateRangeFilter';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';
import { AlertIcon, BoxCubeIcon, ChatIcon, CheckCircleIcon, DollarLineIcon, GroupIcon } from '../../icons';

interface Stats {
    total_contacts: number;
    opt_in_contacts: number;
    opt_out_contacts: number;
    unknown_contacts: number;
    total_stores: number;
    connected_stores: number;
    total_campaigns: number;
    active_campaigns: number;
    total_sent: number;
    total_failed: number;
    delivery_rate: number;
    period_purchases_count: number;
    period_purchases_amount: number;
}

interface StoreRow {
    id: number;
    name: string;
    contacts_count: number;
    connected: boolean;
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

interface ClientRow {
    id: number;
    name: string;
    phone_e164: string;
    store_name: string;
    created_at: string;
}

interface TemplateOption {
    id: number;
    name: string;
    language: string;
}

interface Period {
    from: string;
    to: string;
}

interface PageProps {
    period: Period;
    stats: Stats;
    stores: StoreRow[];
    recentCampaigns: CampaignRow[];
    recentClients: ClientRow[];
    welcomeTemplates: TemplateOption[];
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

function formatFcfa(amount: number) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
}

function selectClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

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
        <div className="rounded-2xl border border-gray-200 bg-white p-3.5 dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                    <p className="truncate text-xs text-gray-500 dark:text-gray-400">{label}</p>
                    <p className="mt-1 text-lg font-semibold text-gray-800 dark:text-white/90">{value}</p>
                </div>
                <span className={`flex h-8 w-8 shrink-0 items-center justify-center rounded-full ${iconClassName}`}>{icon}</span>
            </div>
            {hint && <p className="mt-1 truncate text-xs text-gray-400 dark:text-gray-500">{hint}</p>}
        </div>
    );
}

function DashboardIndex() {
    const { period, stats, stores, recentCampaigns, recentClients, welcomeTemplates, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [welcomeTemplate, setWelcomeTemplate] = useState(welcomeTemplates[0]?.name ?? '');
    const [sending, setSending] = useState(false);

    const optInRate = stats.total_contacts > 0 ? Math.round((stats.opt_in_contacts / stats.total_contacts) * 100) : 0;

    function applyDateRange(from: string, to: string) {
        router.get('/dashboard', { from, to }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function clearDateRange() {
        router.get('/dashboard', {}, { preserveState: true, preserveScroll: true, replace: true });
    }

    function toggleSelected(id: number) {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });
    }

    function toggleSelectAll() {
        setSelectedIds((prev) => (prev.size === recentClients.length ? new Set() : new Set(recentClients.map((c) => c.id))));
    }

    function sendWelcomeBulk() {
        if (!welcomeTemplate || selectedIds.size === 0) {
            return;
        }
        setSending(true);
        router.post(
            '/contacts/send-welcome-bulk',
            { contact_ids: Array.from(selectedIds), template_name: welcomeTemplate },
            {
                preserveScroll: true,
                onSuccess: () => setSelectedIds(new Set()),
                onFinish: () => setSending(false),
            },
        );
    }

    return (
        <>
            <Head title="Tableau de bord" />

            <PageBreadcrumb pageTitle="Tableau de bord" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <div className="flex flex-wrap items-center gap-3">
                    <DateRangeFilter from={period.from} to={period.to} onApply={applyDateRange} onClear={clearDateRange} />
                    <p className="text-sm text-gray-500 dark:text-gray-400">
                        Les compteurs globaux sont à jour en temps réel. Campagnes, achats et clients récents suivent la plage sélectionnée.
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <StatCard
                        label="Contacts"
                        value={String(stats.total_contacts)}
                        hint={`${stats.opt_in_contacts} opt_in (${optInRate}%)`}
                        icon={<GroupIcon className="h-4 w-4" />}
                        iconClassName="bg-blue-light-50 text-blue-light-500 dark:bg-blue-light-500/15"
                    />
                    <StatCard
                        label="Boutiques connectées"
                        value={`${stats.connected_stores} / ${stats.total_stores}`}
                        icon={<BoxCubeIcon className="h-4 w-4" />}
                        iconClassName="bg-brand-50 text-brand-500 dark:bg-brand-500/15"
                    />
                    <StatCard
                        label="Campagnes"
                        value={String(stats.total_campaigns)}
                        hint={`${stats.active_campaigns} en cours`}
                        icon={<ChatIcon className="h-4 w-4" />}
                        iconClassName="bg-success-50 text-success-500 dark:bg-success-500/15"
                    />
                    <StatCard
                        label="Taux d'envoi réussi"
                        value={`${stats.delivery_rate}%`}
                        hint={`${stats.total_sent} / ${stats.total_failed} éch.`}
                        icon={stats.total_failed > 0 ? <AlertIcon className="h-4 w-4" /> : <CheckCircleIcon className="h-4 w-4" />}
                        iconClassName={
                            stats.total_failed > 0
                                ? 'bg-warning-50 text-warning-500 dark:bg-warning-500/15'
                                : 'bg-success-50 text-success-500 dark:bg-success-500/15'
                        }
                    />
                    <StatCard
                        label="Achats (période)"
                        value={String(stats.period_purchases_count)}
                        hint={formatFcfa(stats.period_purchases_amount)}
                        icon={<DollarLineIcon className="h-4 w-4" />}
                        iconClassName="bg-blue-light-50 text-blue-light-500 dark:bg-blue-light-500/15"
                    />
                    <StatCard
                        label="En attente de bienvenue"
                        value={String(recentClients.length)}
                        hint="Ajoutés sur la période"
                        icon={<GroupIcon className="h-4 w-4" />}
                        iconClassName="bg-warning-50 text-warning-500 dark:bg-warning-500/15"
                    />
                </div>

                <ComponentCard
                    title="Clients récents"
                    desc="Ajoutés sur la période, consentement encore inconnu et jamais contactés — cochez-les pour leur demander l'opt-in."
                >
                    {selectedIds.size > 0 && (
                        <div className="mb-5 flex flex-wrap items-center gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 dark:border-brand-500/30 dark:bg-brand-500/10">
                            <span className="text-sm font-medium text-brand-700 dark:text-brand-400">
                                {selectedIds.size} client{selectedIds.size > 1 ? 's' : ''} sélectionné{selectedIds.size > 1 ? 's' : ''}
                            </span>
                            {welcomeTemplates.length === 0 ? (
                                <span className="text-xs text-gray-500 dark:text-gray-400">
                                    Aucun template actif de catégorie « utility » —{' '}
                                    <Link href="/templates" className="underline">
                                        créez-en un
                                    </Link>{' '}
                                    d'abord.
                                </span>
                            ) : (
                                <>
                                    <select
                                        value={welcomeTemplate}
                                        onChange={(e) => setWelcomeTemplate(e.target.value)}
                                        className={selectClasses() + ' w-auto'}
                                    >
                                        {welcomeTemplates.map((template) => (
                                            <option key={template.id} value={template.name}>
                                                {template.name} ({template.language})
                                            </option>
                                        ))}
                                    </select>
                                    <Button size="sm" type="button" disabled={sending} onClick={sendWelcomeBulk}>
                                        {sending ? 'Envoi...' : `Envoyer bienvenue (${selectedIds.size})`}
                                    </Button>
                                </>
                            )}
                        </div>
                    )}

                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-2">
                                        <input
                                            type="checkbox"
                                            checked={recentClients.length > 0 && selectedIds.size === recentClients.length}
                                            onChange={toggleSelectAll}
                                        />
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Téléphone
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Boutique
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Ajouté le
                                    </TableCell>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {recentClients.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={5}>
                                            Aucun client en attente de message de bienvenue sur cette période.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {recentClients.map((client) => (
                                    <TableRow key={client.id}>
                                        <TableCell className="px-4 py-2.5">
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.has(client.id)}
                                                onChange={() => toggleSelected(client.id)}
                                            />
                                        </TableCell>
                                        <TableCell className="px-4 py-2.5 text-sm font-medium">
                                            <Link
                                                href={`/contacts/${client.id}`}
                                                className="text-gray-800 hover:text-brand-500 hover:underline dark:text-white/90"
                                            >
                                                {client.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{client.phone_e164}</TableCell>
                                        <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{client.store_name}</TableCell>
                                        <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{client.created_at}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </ComponentCard>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <ComponentCard title="Boutiques" className="lg:col-span-1" desc="Statut de connexion WhatsApp">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                    <TableRow>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Boutique
                                        </TableCell>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Contacts
                                        </TableCell>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Statut
                                        </TableCell>
                                    </TableRow>
                                </TableHeader>
                                <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {stores.map((store) => (
                                        <TableRow key={store.id}>
                                            <TableCell className="px-4 py-2.5 text-sm font-medium text-gray-800 dark:text-white/90">{store.name}</TableCell>
                                            <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{store.contacts_count}</TableCell>
                                            <TableCell className="px-4 py-2.5 text-sm">
                                                <Badge size="sm" color={store.connected ? 'success' : 'light'}>
                                                    {store.connected ? 'Connectée' : 'Non connectée'}
                                                </Badge>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <Link href="/stores" className="mt-4 inline-block text-sm font-medium text-brand-500 hover:text-brand-600">
                            Configurer les boutiques →
                        </Link>
                    </ComponentCard>

                    <ComponentCard title="Dernières campagnes" className="lg:col-span-2" desc="Sur la période sélectionnée">
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                    <TableRow>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Campagne
                                        </TableCell>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Boutique(s)
                                        </TableCell>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Statut
                                        </TableCell>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Envoyés / Échecs / Total
                                        </TableCell>
                                        <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                            Créée le
                                        </TableCell>
                                    </TableRow>
                                </TableHeader>
                                <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                    {recentCampaigns.length === 0 && (
                                        <TableRow>
                                            <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={5}>
                                                Aucune campagne sur cette période.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {recentCampaigns.map((campaign) => (
                                        <TableRow key={campaign.id}>
                                            <TableCell className="px-4 py-2.5 text-sm font-medium text-gray-800 dark:text-white/90">{campaign.name}</TableCell>
                                            <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{campaign.store_names}</TableCell>
                                            <TableCell className="px-4 py-2.5 text-sm">
                                                <Badge size="sm" color={statusColor[campaign.status] ?? 'light'}>
                                                    {campaign.status}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">
                                                {campaign.sent_count} / {campaign.failed_count} / {campaign.total}
                                            </TableCell>
                                            <TableCell className="px-4 py-2.5 text-sm text-gray-600 dark:text-gray-400">{campaign.created_at}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        <Link href="/campaigns" className="mt-4 inline-block text-sm font-medium text-brand-500 hover:text-brand-600">
                            Voir toutes les campagnes →
                        </Link>
                    </ComponentCard>
                </div>

                <ComponentCard title="Actions rapides">
                    <div className="flex flex-wrap gap-3">
                        <Link
                            href="/contacts"
                            className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand-500 px-4 py-3 text-sm text-white shadow-theme-xs transition hover:bg-brand-600"
                        >
                            Nouveau contact
                        </Link>
                        <Link
                            href="/purchases"
                            className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-3 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                        >
                            Importer un historique d'achats
                        </Link>
                        <Link
                            href="/campaigns"
                            className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-3 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                        >
                            Nouvelle campagne
                        </Link>
                        <Link
                            href="/stores"
                            className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-3 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                        >
                            Connecter une boutique
                        </Link>
                    </div>
                </ComponentCard>
            </div>
        </>
    );
}

DashboardIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default DashboardIndex;
