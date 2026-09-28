import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';
import UserFormModal, { RoleOption, UserFormValues } from './UserFormModal';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role_id: number | null;
    role_name: string | null;
    brand_id: number | null;
    brand_name: string | null;
    store_id: number | null;
    store_name: string | null;
}

interface BrandOption {
    id: number;
    name: string;
}

interface StoreOption {
    id: number;
    name: string;
    brand_id: number;
}

interface PageProps {
    users: UserRow[];
    brands: BrandOption[];
    stores: StoreOption[];
    roles: RoleOption[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function UsersIndex() {
    const { users, brands, stores, roles, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [modalUser, setModalUser] = useState<UserFormValues | null | undefined>(undefined);

    function destroy(user: UserRow) {
        if (confirm(`Supprimer l'utilisateur « ${user.name} » ?`)) {
            router.delete(`/users/${user.id}`, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Users & Rôles" />

            <PageBreadcrumb pageTitle="Users & Rôles" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <div className="flex justify-end gap-2">
                    <Link href="/roles" className="inline-flex">
                        <Button type="button" variant="outline">
                            Gérer les rôles
                        </Button>
                    </Link>
                    <Button type="button" onClick={() => setModalUser(null)}>
                        Nouvel utilisateur
                    </Button>
                </div>

                <ComponentCard
                    title="Utilisateurs"
                    desc="Le périmètre visible (organisation entière, marque ou boutique) et le droit de gérer l'organisation dépendent du rôle attribué. Gérez la liste des rôles depuis « Gérer les rôles »."
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Email
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Rôle
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Périmètre
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3" />
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {users.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{user.name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{user.email}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            <Badge size="sm" color="info">
                                                {user.role_name ?? '—'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {user.brand_name ?? user.store_name ?? '—'}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    setModalUser({
                                                        id: user.id,
                                                        name: user.name,
                                                        email: user.email,
                                                        role_id: user.role_id ?? roles[0]?.id ?? 0,
                                                        brand_id: user.brand_id,
                                                        store_id: user.store_id,
                                                    })
                                                }
                                                className="mr-3 text-sm font-medium text-brand-500 hover:text-brand-600"
                                            >
                                                Modifier
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => destroy(user)}
                                                className="text-sm font-medium text-error-500 hover:text-error-600"
                                            >
                                                Supprimer
                                            </button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </ComponentCard>
            </div>

            {modalUser !== undefined && (
                <UserFormModal
                    isOpen={true}
                    onClose={() => setModalUser(undefined)}
                    user={modalUser}
                    brands={brands}
                    stores={stores}
                    roles={roles}
                />
            )}
        </>
    );
}

UsersIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default UsersIndex;
