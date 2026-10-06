import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, SelectFilter, StatStrip } from '../../components/page/Page';
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

interface PageProps {
    users: UserRow[];
    brands: { id: number; name: string }[];
    stores: { id: number; name: string; brand_id: number }[];
    roles: RoleOption[];
    auth?: { user?: { id: number } };
    [key: string]: unknown;
}

function UsersIndex() {
    const { users, brands, stores, roles, auth } = usePage<PageProps>().props;
    const [modalUser, setModalUser] = useState<UserFormValues | null | undefined>(undefined);
    const [search, setSearch] = useState('');
    const [roleId, setRoleId] = useState<string | null>(null);

    const q = search.trim().toLowerCase();
    const visible = users.filter(
        (u) => (!q || u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)) && (!roleId || String(u.role_id) === roleId),
    );

    function destroy(user: UserRow) {
        if (confirm(`Supprimer l'utilisateur « ${user.name} » ? Il ne pourra plus se connecter.`)) {
            router.delete(`/users/${user.id}`, { preserveScroll: true });
        }
    }

    return (
        <Page
            title="Utilisateurs"
            subtitle="Comptes du back-office : le rôle fixe le périmètre visible (Groupe, marque ou boutique) et les droits"
            actions={
                <>
                    <Link href="/roles" className="mdash-btn ghost">
                        Gérer les rôles
                    </Link>
                    <button type="button" className="mdash-btn brand" onClick={() => setModalUser(null)}>
                        <PlusIcon />
                        Nouvel utilisateur
                    </button>
                </>
            }
            filters={
                <>
                    <SearchFilter value={search} placeholder="Nom ou e-mail" onSearch={setSearch} />
                    <SelectFilter label="Rôle" value={roleId} allLabel="Tous" options={roles.map((r) => ({ value: r.id, label: r.name }))} onChange={setRoleId} />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Utilisateurs', value: users.length },
                    { label: 'Périmètre Groupe', value: users.filter((u) => !u.brand_id && !u.store_id).length },
                    { label: 'Périmètre marque', value: users.filter((u) => u.brand_id).length },
                    { label: 'Périmètre boutique', value: users.filter((u) => u.store_id).length },
                ]}
            />

            <Panel>
                {visible.length === 0 ? (
                    <Empty title="Aucun utilisateur ne correspond aux filtres" />
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Utilisateur</th>
                                    <th>Rôle</th>
                                    <th>Périmètre</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((u) => (
                                    <tr key={u.id}>
                                        <td className="cell-main">
                                            <b>
                                                {u.name}
                                                {auth?.user?.id === u.id && <span className="mpage-muted"> (vous)</span>}
                                            </b>
                                            <span>{u.email}</span>
                                        </td>
                                        <td>
                                            <span className="mdash-pill info">{u.role_name ?? '—'}</span>
                                        </td>
                                        <td>{u.store_name ? `Boutique ${u.store_name}` : u.brand_name ? `Marque ${u.brand_name}` : 'Tout le Groupe'}</td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        setModalUser({
                                                            id: u.id,
                                                            name: u.name,
                                                            email: u.email,
                                                            role_id: u.role_id ?? roles[0]?.id ?? 0,
                                                            brand_id: u.brand_id,
                                                            store_id: u.store_id,
                                                        })
                                                    }
                                                >
                                                    Modifier
                                                </button>
                                                {auth?.user?.id !== u.id && (
                                                    <button type="button" className="danger" onClick={() => destroy(u)}>
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

            {modalUser !== undefined && (
                <UserFormModal isOpen={true} onClose={() => setModalUser(undefined)} user={modalUser} brands={brands} stores={stores} roles={roles} />
            )}
        </Page>
    );
}

UsersIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default UsersIndex;
