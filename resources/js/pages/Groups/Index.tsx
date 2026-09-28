import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

interface GroupRow {
    id: number;
    name: string;
    contacts_count: number;
}

interface PageProps {
    groups: GroupRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function GroupFormModal({ isOpen, onClose, group }: { isOpen: boolean; onClose: () => void; group: GroupRow | null }) {
    const isEditing = group !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm({ name: group?.name ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (isEditing) {
            put(`/groups/${group.id}`, options);
        } else {
            post('/groups', options);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">{isEditing ? 'Renommer le groupe' : 'Nouveau groupe'}</h3>

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
                        placeholder="ex. VIP, Mango, Féminin..."
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Renommer' : 'Créer le groupe'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function GroupsIndex() {
    const { groups, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [modalGroup, setModalGroup] = useState<GroupRow | null | undefined>(undefined);

    function destroy(group: GroupRow) {
        if (confirm(`Supprimer le groupe « ${group.name} » ? Les contacts ne seront pas supprimés, seulement retirés du groupe.`)) {
            router.delete(`/groups/${group.id}`, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Groupes" />

            <PageBreadcrumb pageTitle="Groupes" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <Alert
                    variant="info"
                    title="À quoi servent les groupes ?"
                    message="Un groupe est une étiquette libre pour des classifications métier qui n'ont pas de champ dédié (ex. VIP, cohorte d'un événement, liste ponctuelle). Pour cibler par boutique ou par sexe, utilisez les filtres dédiés dans les campagnes et sur la page Contacts : ils s'appuient sur les vraies données du contact et restent à jour automatiquement, contrairement à un groupe qui doit être affecté à la main et peut se désynchroniser."
                />

                <div className="flex justify-end">
                    <Button type="button" onClick={() => setModalGroup(null)}>
                        Nouveau groupe
                    </Button>
                </div>

                <ComponentCard
                    title="Groupes de contacts"
                    desc="Étiquettes libres pour cibler une campagne (ex. VIP, cohorte, liste ponctuelle). Affectez des contacts à un groupe depuis la page Contacts."
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Contacts
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3" />
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {groups.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={3}>
                                            Aucun groupe. Créez-en un (ex. « VIP »), puis affectez des contacts depuis la page Contacts.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {groups.map((group) => (
                                    <TableRow key={group.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{group.name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            <Link href={`/contacts?group_id=${group.id}`} className="hover:text-brand-500 hover:underline">
                                                {group.contacts_count} contact{group.contacts_count > 1 ? 's' : ''}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() => setModalGroup(group)}
                                                className="mr-3 text-sm font-medium text-brand-500 hover:text-brand-600"
                                            >
                                                Renommer
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => destroy(group)}
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

            {modalGroup !== undefined && <GroupFormModal isOpen={true} onClose={() => setModalGroup(undefined)} group={modalGroup} />}
        </>
    );
}

GroupsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default GroupsIndex;
