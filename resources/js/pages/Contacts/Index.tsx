import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';
import NewContactModal from './NewContactModal';

interface ContactRow {
    id: number;
    name: string;
    phone_e164: string;
    gender: string | null;
    birthdate: string | null;
    consent_status: string;
    store_name: string;
    group_names: string[];
}

interface StoreOption {
    id: number;
    name: string;
}

interface GroupOption {
    id: number;
    name: string;
}

interface Filters {
    store_id?: string;
    gender?: string;
    consent_status?: string;
    group_id?: string;
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

interface PageProps {
    contacts: Paginated<ContactRow>;
    perPage: number;
    stores: StoreOption[];
    groups: GroupOption[];
    filters: Filters;
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

const consentColor: Record<string, 'success' | 'warning' | 'light'> = {
    opt_in: 'success',
    opt_out: 'warning',
    unknown: 'light',
};

const genderLabel: Record<string, string> = {
    H: 'Homme',
    F: 'Femme',
};

function selectClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function ContactsIndex() {
    const { contacts, perPage, stores, groups, filters, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [bulkGroupId, setBulkGroupId] = useState('');
    const [assigning, setAssigning] = useState(false);
    const [isNewContactOpen, setIsNewContactOpen] = useState(false);

    function applyFilter(key: keyof Filters, value: string) {
        router.get(
            '/contacts',
            { ...filters, [key]: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function changePerPage(value: string) {
        router.get('/contacts', { ...filters, per_page: value }, { preserveState: true, replace: true });
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
        setSelectedIds((prev) => (prev.size === contacts.data.length ? new Set() : new Set(contacts.data.map((c) => c.id))));
    }

    function assignToGroup() {
        if (!bulkGroupId || selectedIds.size === 0) {
            return;
        }
        setAssigning(true);
        router.post(
            '/contacts/assign-group',
            { group_id: bulkGroupId, contact_ids: Array.from(selectedIds) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedIds(new Set());
                    setBulkGroupId('');
                },
                onFinish: () => setAssigning(false),
            },
        );
    }

    const exportUrl = '/contacts/export?'.concat(new URLSearchParams(filters as Record<string, string>).toString());

    return (
        <>
            <Head title="Contacts" />

            <PageBreadcrumb pageTitle="Contacts" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <div className="flex justify-end gap-2">
                    <a
                        href={exportUrl}
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-3 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                    >
                        Exporter (Excel/CSV)
                    </a>
                    <Link
                        href="/imports"
                        className="inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 px-4 py-3 text-sm text-gray-700 transition hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                    >
                        Importer un fichier
                    </Link>
                    <Button type="button" onClick={() => setIsNewContactOpen(true)}>
                        Nouveau contact
                    </Button>
                </div>

                <ComponentCard title="Contacts" desc="Liste des contacts, tous boutiques confondues">
                    <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <select value={filters.store_id ?? ''} onChange={(e) => applyFilter('store_id', e.target.value)} className={selectClasses()}>
                            <option value="">Toutes les boutiques</option>
                            {stores.map((store) => (
                                <option key={store.id} value={store.id}>
                                    {store.name}
                                </option>
                            ))}
                        </select>

                        <select value={filters.gender ?? ''} onChange={(e) => applyFilter('gender', e.target.value)} className={selectClasses()}>
                            <option value="">Tous sexes</option>
                            <option value="H">Homme</option>
                            <option value="F">Femme</option>
                        </select>

                        <select
                            value={filters.consent_status ?? ''}
                            onChange={(e) => applyFilter('consent_status', e.target.value)}
                            className={selectClasses()}
                        >
                            <option value="">Tous statuts de consentement</option>
                            <option value="opt_in">opt_in</option>
                            <option value="opt_out">opt_out</option>
                            <option value="unknown">unknown</option>
                        </select>

                        <select value={filters.group_id ?? ''} onChange={(e) => applyFilter('group_id', e.target.value)} className={selectClasses()}>
                            <option value="">Tous groupes</option>
                            {groups.map((group) => (
                                <option key={group.id} value={group.id}>
                                    {group.name}
                                </option>
                            ))}
                        </select>
                    </div>

                    {selectedIds.size > 0 && (
                        <div className="mb-5 flex flex-wrap items-center gap-3 rounded-lg border border-brand-200 bg-brand-50 px-4 py-3 dark:border-brand-500/30 dark:bg-brand-500/10">
                            <span className="text-sm font-medium text-brand-700 dark:text-brand-400">
                                {selectedIds.size} contact{selectedIds.size > 1 ? 's' : ''} sélectionné{selectedIds.size > 1 ? 's' : ''}
                            </span>
                            <select value={bulkGroupId} onChange={(e) => setBulkGroupId(e.target.value)} className={selectClasses() + ' w-auto'}>
                                <option value="">Choisir un groupe...</option>
                                {groups.map((group) => (
                                    <option key={group.id} value={group.id}>
                                        {group.name}
                                    </option>
                                ))}
                            </select>
                            <Button size="sm" type="button" disabled={!bulkGroupId || assigning} onClick={assignToGroup}>
                                {assigning ? 'Ajout...' : 'Ajouter au groupe'}
                            </Button>
                            {groups.length === 0 && (
                                <span className="text-xs text-gray-500 dark:text-gray-400">
                                    Aucun groupe créé — <Link href="/groups" className="underline">créez-en un</Link> d'abord.
                                </span>
                            )}
                        </div>
                    )}

                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3">
                                        <input
                                            type="checkbox"
                                            checked={contacts.data.length > 0 && selectedIds.size === contacts.data.length}
                                            onChange={toggleSelectAll}
                                        />
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Téléphone
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Boutique
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Sexe
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Groupes
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Consentement
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Actions
                                    </TableCell>
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {contacts.data.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={8}>
                                            Aucun contact ne correspond à ces filtres.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {contacts.data.map((contact) => (
                                    <TableRow key={contact.id}>
                                        <TableCell className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={selectedIds.has(contact.id)}
                                                onChange={() => toggleSelected(contact.id)}
                                            />
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm font-medium">
                                            <Link href={`/contacts/${contact.id}`} className="text-gray-800 hover:text-brand-500 hover:underline dark:text-white/90">
                                                {contact.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{contact.phone_e164}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{contact.store_name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {contact.gender ? (genderLabel[contact.gender] ?? contact.gender) : '—'}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            <div className="flex flex-wrap gap-1">
                                                {contact.group_names.length === 0 && <span className="text-gray-400">—</span>}
                                                {contact.group_names.map((name) => (
                                                    <Badge key={name} size="sm" color="info">
                                                        {name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            <Badge size="sm" color={consentColor[contact.consent_status] ?? 'light'}>
                                                {contact.consent_status}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-right whitespace-nowrap">
                                            <Link
                                                href={`/contacts/${contact.id}`}
                                                className="mr-3 text-sm font-medium text-brand-500 hover:text-brand-600"
                                            >
                                                Consulter
                                            </Link>
                                            <Link
                                                href={`/chat/${contact.id}`}
                                                className="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                                            >
                                                Chat
                                            </Link>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <div className="flex items-center gap-2 text-sm text-gray-500 dark:text-gray-400">
                            <span>
                                {contacts.total === 0
                                    ? 'Aucun contact'
                                    : `Page ${contacts.current_page} sur ${contacts.last_page} (${contacts.total} au total)`}
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
                            {contacts.links.map((link, index) => (
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

            <NewContactModal isOpen={isNewContactOpen} onClose={() => setIsNewContactOpen(false)} stores={stores} />
        </>
    );
}

ContactsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ContactsIndex;
