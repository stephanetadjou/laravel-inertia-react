import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

interface CategoryRow {
    id: number;
    name: string;
    purchases_count: number;
}

interface PageProps {
    categories: CategoryRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function CategoryFormModal({ isOpen, onClose, category }: { isOpen: boolean; onClose: () => void; category: CategoryRow | null }) {
    const isEditing = category !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm({ name: category?.name ?? '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { reset(); onClose(); } };
        if (isEditing) {
            put(`/categories/${category.id}`, options);
        } else {
            post('/categories', options);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">{isEditing ? 'Renommer la catégorie' : 'Nouvelle catégorie'}</h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom de la catégorie
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. Chaussures, Vestes, Accessoires..."
                        className={fieldClasses()}
                        autoFocus
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Renommer' : 'Créer la catégorie'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function CategoriesIndex() {
    const { categories, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [modalCategory, setModalCategory] = useState<CategoryRow | null | undefined>(undefined);

    function destroy(category: CategoryRow) {
        if (confirm(`Supprimer la catégorie « ${category.name} » ? Les achats déjà enregistrés ne seront pas modifiés.`)) {
            router.delete(`/categories/${category.id}`, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Catégories" />

            <PageBreadcrumb pageTitle="Catégories" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <Alert
                    variant="info"
                    title="À quoi servent les catégories ?"
                    message="Une catégorie classe les articles achetés (ex. Chaussures, Vestes, Accessoires). Elle est renseignée lors de l'import d'un historique d'achats et sert à filtrer/segmenter les clients par type de produit acheté."
                />

                <div className="flex justify-end">
                    <Button type="button" onClick={() => setModalCategory(null)}>
                        Ajouter une catégorie
                    </Button>
                </div>

                <ComponentCard
                    title="Catégories de produits"
                    desc="Liste des catégories utilisables lors de l'import d'un historique d'achats."
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Achats
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3" />
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {categories.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={3}>
                                            Aucune catégorie. Créez-en une (ex. « Chaussures ») pour classer les achats importés.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {categories.map((category) => (
                                    <TableRow key={category.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">{category.name}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                            <Link href={`/purchases?category=${encodeURIComponent(category.name)}`} className="hover:text-brand-500 hover:underline">
                                                {category.purchases_count} achat{category.purchases_count > 1 ? 's' : ''}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() => setModalCategory(category)}
                                                className="mr-3 text-sm font-medium text-brand-500 hover:text-brand-600"
                                            >
                                                Renommer
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => destroy(category)}
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

            {modalCategory !== undefined && <CategoryFormModal isOpen={true} onClose={() => setModalCategory(undefined)} category={modalCategory} />}
        </>
    );
}

CategoriesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CategoriesIndex;
