import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

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
    const { roles } = usePage<PageProps>().props;
    const [modalRole, setModalRole] = useState<RoleRow | null | undefined>(undefined);

    function destroy(role: RoleRow) {
        if (confirm(`Supprimer le rôle « ${role.name} » ?`)) {
            router.delete(`/roles/${role.id}`, { preserveScroll: true });
        }
    }

    return (
        <Page
            title="Rôles"
            subtitle="Ce que chaque utilisateur voit (Groupe, marque ou boutique) et s'il peut administrer l'organisation"
            actions={
                <>
                    <Link href="/users" className="mdash-btn ghost">
                        ← Utilisateurs
                    </Link>
                    <button type="button" className="mdash-btn brand" onClick={() => setModalRole(null)}>
                        <PlusIcon />
                        Nouveau rôle
                    </button>
                </>
            }
        >
            <div className="mpage-note">
                Les 4 rôles fournis par défaut peuvent être modifiés. « Administre l'organisation » donne accès aux marques, boutiques, utilisateurs et
                rôles. Un rôle encore attribué à un utilisateur ne peut pas être supprimé.
            </div>

            <Panel>
                {roles.length === 0 ? (
                    <Empty title="Aucun rôle" />
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Rôle</th>
                                    <th>Périmètre</th>
                                    <th>Administre l'organisation</th>
                                    <th className="r">Utilisateurs</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {roles.map((role) => (
                                    <tr key={role.id}>
                                        <td className="cell-main">
                                            <b>{role.name}</b>
                                        </td>
                                        <td>{scopeLabel[role.scope]}</td>
                                        <td>{role.can_manage_organization ? <span className="mdash-pill ok">Oui</span> : <span className="mdash-pill idle">Non</span>}</td>
                                        <td className="r num">{role.users_count}</td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setModalRole(role)}>
                                                    Modifier
                                                </button>
                                                {role.users_count === 0 && (
                                                    <button type="button" className="danger" onClick={() => destroy(role)}>
                                                        Supprimer
                                                    </button>
                                                )}
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {modalRole !== undefined && <RoleFormModal key={modalRole?.id ?? 'new'} isOpen={true} onClose={() => setModalRole(undefined)} role={modalRole} />}
        </Page>
    );
}

RolesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default RolesIndex;
