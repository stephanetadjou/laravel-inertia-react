import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, StatStrip } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

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
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : isEditing ? 'Renommer' : 'Créer la catégorie'}</Button>
                </div>
            </form>
        </Modal>
    );
}
function CategoriesIndex() {
    const { categories } = usePage<PageProps>().props;
    const [modalCategory, setModalCategory] = useState<CategoryRow | null | undefined>(undefined);
    const [search, setSearch] = useState('');

    const q = search.trim().toLowerCase();
    const visible = categories.filter((c) => !q || c.name.toLowerCase().includes(q));
    const today = new Date().toISOString().slice(0, 10);

    function destroy(category: CategoryRow) {
        if (confirm(`Supprimer la catégorie « ${category.name} » ? Les achats déjà enregistrés ne seront pas modifiés.`)) {
            router.delete(`/categories/${category.id}`, { preserveScroll: true });
        }
    }

    return (
        <Page
            title="Catégories"
            subtitle="Types d'articles achetés (Chaussures, Vestes…), renseignés à l'import de l'historique d'achats"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModalCategory(null)}>
                    <PlusIcon />
                    Nouvelle catégorie
                </button>
            }
            filters={categories.length > 5 ? <SearchFilter value={search} placeholder="Nom de la catégorie" onSearch={setSearch} /> : undefined}
        >
            <StatStrip
                items={[
                    { label: 'Catégories', value: categories.length },
                    { label: 'Utilisées dans des achats', value: categories.filter((c) => c.purchases_count > 0).length },
                    { label: 'Achats classés', value: categories.reduce((n, c) => n + c.purchases_count, 0) },
                ]}
            />
            <div className="mpage-note">
                La liste sert de référence pour l'import d'achats et le filtre « Catégorie » de l'<Link href="/purchases">Historique d'achat</Link>. Un
                achat importé avec une catégorie inconnue est conservé tel quel.
            </div>

            <Panel>
                {visible.length === 0 ? (
                    <Empty title={categories.length === 0 ? 'Aucune catégorie pour l’instant' : 'Aucune catégorie ne correspond à la recherche'} />
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Catégorie</th>
                                    <th className="r">Achats</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((c) => (
                                    <tr key={c.id}>
                                        <td className="cell-main">
                                            <b>{c.name}</b>
                                        </td>
                                        <td className="r num">
                                            {c.purchases_count > 0 ? (
                                                <Link href={`/purchases?${new URLSearchParams({ category: c.name, from: '2000-01-01', to: today })}`} className="mdash-link">
                                                    {c.purchases_count}
                                                </Link>
                                            ) : (
                                                <span className="mpage-muted">0</span>
                                            )}
                                        </td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setModalCategory(c)}>
                                                    Renommer
                                                </button>
                                                <button type="button" className="danger" onClick={() => destroy(c)}>
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

            {modalCategory !== undefined && (
                <CategoryFormModal key={modalCategory?.id ?? 'new'} isOpen={true} onClose={() => setModalCategory(undefined)} category={modalCategory} />
            )}
        </Page>
    );
}

CategoriesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CategoriesIndex;
