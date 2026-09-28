import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

type Scope = 'organization' | 'brand' | 'store';

interface RoleRow {
    id: number;
    name: string;
    scope: Scope;
    can_manage_organization: boolean;
    users_count: number;
}

interface PageProps {
    roles: RoleRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

const scopeLabel: Record<Scope, string> = {
    organization: 'Toute l\'organisation',
    brand: 'Une marque (et ses boutiques)',
    store: 'Une seule boutique',
};

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function RoleFormModal({ isOpen, onClose, role }: { isOpen: boolean; onClose: () => void; role: RoleRow | null }) {
    const isEditing = role !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: role?.name ?? '',
        scope: role?.scope ?? ('organization' as Scope),
        can_manage_organization: role?.can_manage_organization ?? false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (isEditing) {
            put(`/roles/${role.id}`, options);
        } else {
            post('/roles', options);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">{isEditing ? 'Modifier le rôle' : 'Nouveau rôle'}</h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom du rôle
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. Responsable Marketing"
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div>
                    <label htmlFor="scope" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Périmètre visible
                    </label>
                    <select id="scope" value={data.scope} onChange={(e) => setData('scope', e.target.value as Scope)} className={fieldClasses()}>
                        <option value="organization">{scopeLabel.organization}</option>
                        <option value="brand">{scopeLabel.brand}</option>
                        <option value="store">{scopeLabel.store}</option>
                    </select>
                    <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        Détermine quels contacts, achats et campagnes un utilisateur avec ce rôle peut voir. Si « Une marque » ou « Une seule
                        boutique » est choisi, la marque/boutique sera à sélectionner sur la fiche de chaque utilisateur.
                    </p>
                    {errors.scope && <p className="mt-1.5 text-sm text-error-500">{errors.scope}</p>}
                </div>

                <div className="flex items-start gap-2">
                    <input
                        id="can_manage_organization"
                        type="checkbox"
                        checked={data.can_manage_organization}
                        onChange={(e) => setData('can_manage_organization', e.target.checked)}
                        className="mt-1 h-4 w-4 rounded border-gray-300 text-brand-500 focus:ring-brand-300"
                    />
                    <label htmlFor="can_manage_organization" className="text-sm text-gray-700 dark:text-gray-300">
                        Peut gérer l'organisation
                        <span className="block text-xs text-gray-500 dark:text-gray-400">
                            Accès aux marques, boutiques, utilisateurs et rôles (onglets « Users &amp; Rôles » et « Organisation »).
                        </span>
                    </label>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Enregistrer' : 'Créer le rôle'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function RolesIndex() {
    const { roles, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [modalRole, setModalRole] = useState<RoleRow | null | undefined>(undefined);

    function destroy(role: RoleRow) {
        if (confirm(`Supprimer le rôle « ${role.name} » ?`)) {
            router.delete(`/roles/${role.id}`, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Rôles" />

            <PageBreadcrumb pageTitle="Rôles" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <Alert
                    variant="info"
                    title="À quoi servent les rôles ?"
                    message="Un rôle définit ce qu'un utilisateur peut voir (toute l'organisation, une marque ou une seule boutique) et s'il peut gérer l'organisation (marques, boutiques, utilisateurs, rôles). Modifiez les 4 rôles fournis par défaut ou créez-en de nouveaux, puis attribuez-les depuis Users & Rôles."
                />

                <div className="flex justify-end">
                    <Button type="button" onClick={() => setModalRole(null)}>
                        Nouveau rôle
                    </Button>
                </div>

                <ComponentCard title="Rôles" desc="Périmètre de visibilité et droit de gestion de l'organisation, par rôle.">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Périmètre
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Gère l'organisation
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Utilisateurs
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3" />
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {roles.map((role) => (
                                    <TableRow key={role.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{role.name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{scopeLabel[role.scope]}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            {role.can_manage_organization ? (
                                                <Badge size="sm" color="success">Oui</Badge>
                                            ) : (
                                                <Badge size="sm" color="light">Non</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            {role.users_count} utilisateur{role.users_count > 1 ? 's' : ''}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() => setModalRole(role)}
                                                className="mr-3 text-sm font-medium text-brand-500 hover:text-brand-600"
                                            >
                                                Modifier
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => destroy(role)}
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

            {modalRole !== undefined && <RoleFormModal isOpen={true} onClose={() => setModalRole(undefined)} role={modalRole} />}
        </>
    );
}

RolesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default RolesIndex;
