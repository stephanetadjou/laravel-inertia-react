import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Button from '../../components/ui/button/Button';
import DateRangeFilter from '../../components/common/DateRangeFilter';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';
import PurchaseImportModal, { PurchaseImportPreview } from './PurchaseImportModal';

interface PurchaseRow {
    id: number;
    contact_name: string;
    contact_id: number;
    store_name: string;
    product_category: string | null;
    quantity: number;
    amount: number;
    purchased_at: string;
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

interface StoreOption {
    id: number;
    name: string;
}

interface Stats {
    total_purchases: number;
    total_amount: number;
    total_quantity: number;
}

interface Filters {
    store_id?: string;
    category?: string;
    from?: string;
    to?: string;
}

interface PageProps {
    purchases: Paginated<PurchaseRow>;
    stats: Stats;
    perPage: number;
    stores: StoreOption[];
    categories: string[];
    filters: Filters;
    preview: PurchaseImportPreview | null;
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function formatFcfa(amount: number) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
}

function selectClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function PurchasesIndex() {
    const { purchases, stats, perPage, stores, categories, filters, preview, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [isImportOpen, setIsImportOpen] = useState(false);

    function applyFilter(key: keyof Filters, value: string) {
        router.get('/purchases', { ...filters, [key]: value || undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function applyDateRange(from: string, to: string) {
        router.get('/purchases', { ...filters, from, to }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function clearDateRange() {
        router.get('/purchases', { ...filters, from: undefined, to: undefined }, { preserveState: true, preserveScroll: true, replace: true });
    }

    function changePerPage(value: string) {
        router.get('/purchases', { ...filters, per_page: value }, { preserveState: true, replace: true });
    }

    return (
        <>
            <Head title="Historique d'achats" />

            <PageBreadcrumb pageTitle="Historique d'achats" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <div className="flex justify-start">
                    <DateRangeFilter from={filters.from} to={filters.to} onApply={applyDateRange} onClear={clearDateRange} />
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="text-sm text-gray-500 dark:text-gray-400">Achats enregistrés</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{stats.total_purchases}</p>
                    </div>
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="text-sm text-gray-500 dark:text-gray-400">Montant total</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{formatFcfa(stats.total_amount)}</p>
                    </div>
                    <div className="rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                        <p className="text-sm text-gray-500 dark:text-gray-400">Quantité totale</p>
                        <p className="mt-1 text-2xl font-semibold text-gray-800 dark:text-white/90">{stats.total_quantity}</p>
                    </div>
                </div>

                <div className="flex justify-end gap-2">
                    <Link href="/categories" className="inline-flex">
                        <Button type="button" variant="outline">
                            Gérer les catégories
                        </Button>
                    </Link>
                    <Button type="button" onClick={() => setIsImportOpen(true)}>
                        Importer des achats
                    </Button>
                </div>

                <ComponentCard
                    title="Historique d'achats"
                    desc="Sert au calcul futur du score RFM (Récence, Fréquence, Montant) par contact."
                >
                    <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <select value={filters.store_id ?? ''} onChange={(e) => applyFilter('store_id', e.target.value)} className={selectClasses()}>
                            <option value="">Toutes les boutiques</option>
                            {stores.map((store) => (
                                <option key={store.id} value={store.id}>
                                    {store.name}
                                </option>
                            ))}
                        </select>
                        <select value={filters.category ?? ''} onChange={(e) => applyFilter('category', e.target.value)} className={selectClasses()}>
                            <option value="">Toutes les catégories</option>
                            {categories.map((category) => (
                                <option key={category} value={category}>
                                    {category}
                                </option>
                            ))}
                        </select>
                    </div>

                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Contact
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Boutique
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Catégorie
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Quantité
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Montant
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Date
                                    </TableCell>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {purchases.data.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={6}>
                                            Aucun achat enregistré. Importez un historique de ventes pour commencer.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {purchases.data.map((purchase) => (
                                    <TableRow key={purchase.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium">
                                            <Link
                                                href={`/contacts/${purchase.contact_id}`}
                                                className="text-gray-800 hover:text-brand-500 hover:underline dark:text-white/90"
                                            >
                                                {purchase.contact_name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{purchase.store_name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {purchase.product_category ?? '—'}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{purchase.quantity}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                            {formatFcfa(purchase.amount)}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{purchase.purchased_at}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <div className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <span>
                                {purchases.total === 0
                                    ? 'Aucun achat'
                                    : `Page ${purchases.current_page} sur ${purchases.last_page} (${purchases.total} au total)`}
                            </span>
                            <select
                                value={perPage}
                                onChange={(e) => changePerPage(e.target.value)}
                                className="rounded-lg border border-gray-300 px-2 py-1 text-sm text-gray-700 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300"
                            >
                                <option value={25}>25 / page</option>
                                <option value={50}>50 / page</option>
                                <option value={100}>100 / page</option>
                            </select>
                        </div>

                        <div className="flex gap-1">
                            {purchases.links.map((link, index) => (
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

            <PurchaseImportModal isOpen={isImportOpen} onClose={() => setIsImportOpen(false)} preview={preview} />
        </>
    );
}

PurchasesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default PurchasesIndex;
