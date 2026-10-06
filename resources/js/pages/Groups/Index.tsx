import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, StatStrip, formatDay } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

interface GroupRow {
    id: number;
    name: string;
    contacts_count: number;
    customers_count: number;
    opt_in_count: number;
    campaigns_count: number;
    last_used_at: string | null;
    created_at: string | null;
}

interface PageProps {
    groups: GroupRow[];
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function GroupFormModal({ onClose, group }: { onClose: () => void; group: GroupRow | null }) {
    const isEditing = group !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm({ name: group?.name ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };
        if (isEditing) {
            put(`/groups/${group.id}`, options);
        } else {
            post('/groups', options);
        }
    };

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">{isEditing ? 'Renommer le groupe' : 'Nouveau groupe'}</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Ajoutez-y ensuite des clients depuis Fiches clients (cochez-les puis « Ajouter à un groupe ») ou depuis une fiche client.
            </p>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom du groupe
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. Invités soirée Mango, Clients mariage…"
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : isEditing ? 'Renommer' : 'Créer le groupe'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function GroupsIndex() {
    const { groups } = usePage<PageProps>().props;
    const [modalGroup, setModalGroup] = useState<GroupRow | null | undefined>(undefined);
    const [search, setSearch] = useState('');

    const q = search.trim().toLowerCase();
    const visible = groups.filter((g) => !q || g.name.toLowerCase().includes(q));

    function destroy(group: GroupRow) {
        if (confirm(`Supprimer le groupe « ${group.name} » ? Les contacts ne sont pas supprimés, seulement retirés du groupe.`)) {
            router.delete(`/groups/${group.id}`, { preserveScroll: true });
        }
    }

    return (
        <Page
            title="Groupes"
            subtitle="Listes de clients choisies à la main pour cibler une campagne"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModalGroup(null)}>
                    <PlusIcon />
                    Nouveau groupe
                </button>
            }
            filters={groups.length > 5 ? <SearchFilter value={search} placeholder="Nom du groupe" onSearch={setSearch} /> : undefined}
        >
            <StatStrip
                items={[
                    { label: 'Groupes', value: groups.length },
                    { label: 'Clients dans un groupe', value: groups.reduce((n, g) => n + g.customers_count, 0), hint: 'un client peut être dans plusieurs groupes' },
                    { label: 'Utilisés en campagne', value: groups.filter((g) => g.campaigns_count > 0).length },
                ]}
            />
            <div className="mpage-note">
                <b>À quoi sert un groupe ?</b> À réunir des clients qu'aucun critère automatique ne distingue : invités d'un événement, liste
                remise par une vendeuse, clients d'une opération ponctuelle… Pour cibler par boutique, étape du parcours, VIP ou sexe, inutile de
                créer un groupe : ces filtres existent déjà dans la création de campagne et dans <Link href="/customers">Fiches clients</Link>, et
                restent à jour tout seuls.
                <br />
                <b>Remplir un groupe :</b> dans Fiches clients, cochez des clients (ou tout un filtre) puis « Ajouter à un groupe » ; ou depuis
                la fiche d'un client. « Voir les clients » affiche les membres, d'où l'on peut aussi les retirer.
            </div>

            <Panel>
                {visible.length === 0 ? (
                    <Empty title={groups.length === 0 ? 'Aucun groupe pour l’instant' : 'Aucun groupe ne correspond à la recherche'}>
                        {groups.length === 0 && 'Créez un groupe, puis cochez des clients dans Fiches clients pour les y ajouter.'}
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Groupe</th>
                                    <th className="r">Clients</th>
                                    <th className="r">Joignables (opt-in)</th>
                                    <th className="r">Campagnes</th>
                                    <th className="r">Dernière campagne</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((g) => (
                                    <tr key={g.id}>
                                        <td className="cell-main">
                                            <b>{g.name}</b>
                                            {g.created_at && <span>créé le {formatDay(g.created_at)}</span>}
                                        </td>
                                        <td className="r num">
                                            <Link href={`/customers?group=${g.id}`} className="mdash-link" title="Voir les clients du groupe">
                                                {g.customers_count}
                                            </Link>
                                        </td>
                                        <td className="r num">{g.opt_in_count}</td>
                                        <td className="r num">{g.campaigns_count}</td>
                                        <td className="r mono">{formatDay(g.last_used_at)}</td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <Link href={`/customers?group=${g.id}`}>Voir les clients</Link>
                                                <Link href={`/campaigns?group=${g.id}`}>Créer une campagne</Link>
                                                <button type="button" onClick={() => setModalGroup(g)}>
                                                    Renommer
                                                </button>
                                                <button type="button" className="danger" onClick={() => destroy(g)}>
                                                    Supprimer
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {modalGroup !== undefined && <GroupFormModal key={modalGroup?.id ?? 'new'} onClose={() => setModalGroup(undefined)} group={modalGroup} />}
        </Page>
    );
}

GroupsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default GroupsIndex;
