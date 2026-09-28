import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Button from '../../components/ui/button/Button';
import BrandFormModal, { BrandFormValues } from './BrandFormModal';
import StoreFormModal from './StoreFormModal';

interface StoreRow {
    id: number;
    name: string;
}

interface BrandRow {
    id: number;
    name: string;
    stores_count: number;
    stores: StoreRow[];
}

interface PageProps {
    organization: { id: number; name: string };
    brands: BrandRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function OrganisationIndex() {
    const { organization, brands, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [modalBrand, setModalBrand] = useState<BrandFormValues | null | undefined>(undefined);
    const [newStoreBrand, setNewStoreBrand] = useState<{ id: number; name: string } | null>(null);

    const { data, setData, put, processing } = useForm({ name: organization.name });

    const submitOrganization: FormEventHandler = (e) => {
        e.preventDefault();
        put('/organisation', { preserveScroll: true });
    };

    function destroyBrand(brand: BrandRow) {
        if (confirm(`Supprimer la marque « ${brand.name} » ? Impossible si elle a encore des boutiques.`)) {
            router.delete(`/brands/${brand.id}`, { preserveScroll: true });
        }
    }

    function destroyStore(store: StoreRow) {
        if (confirm(`Supprimer la boutique « ${store.name} » ? Impossible si elle a encore des contacts.`)) {
            router.delete(`/stores/${store.id}`, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Organisation" />

            <PageBreadcrumb pageTitle="Organisation" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <ComponentCard title="Entreprise">
                    <form onSubmit={submitOrganization} className="flex flex-wrap items-end gap-3">
                        <div className="min-w-64">
                            <label htmlFor="org_name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Nom de l'organisation
                            </label>
                            <input
                                id="org_name"
                                type="text"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                className={fieldClasses()}
                            />
                        </div>
                        <Button disabled={processing}>{processing ? 'Enregistrement...' : 'Enregistrer'}</Button>
                    </form>
                </ComponentCard>

                <div className="flex justify-end">
                    <Button type="button" onClick={() => setModalBrand(null)}>
                        Nouvelle marque
                    </Button>
                </div>

                <div className="space-y-4">
                    {brands.length === 0 && (
                        <ComponentCard title="Marques">
                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                Aucune marque. Créez-en une pour commencer à organiser vos boutiques.
                            </p>
                        </ComponentCard>
                    )}

                    {brands.map((brand) => (
                        <ComponentCard
                            key={brand.id}
                            title={brand.name}
                            desc={`${brand.stores_count} boutique${brand.stores_count > 1 ? 's' : ''}`}
                        >
                            <div className="mb-4 flex flex-wrap gap-3">
                                <button
                                    type="button"
                                    onClick={() => setModalBrand({ id: brand.id, name: brand.name })}
                                    className="text-sm font-medium text-brand-500 hover:text-brand-600"
                                >
                                    Renommer
                                </button>
                                <button
                                    type="button"
                                    onClick={() => destroyBrand(brand)}
                                    className="text-sm font-medium text-error-500 hover:text-error-600"
                                >
                                    Supprimer
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setNewStoreBrand({ id: brand.id, name: brand.name })}
                                    className="ml-auto text-sm font-medium text-brand-500 hover:text-brand-600"
                                >
                                    + Ajouter une boutique
                                </button>
                            </div>

                            <ul className="divide-y divide-gray-100 dark:divide-gray-800">
                                {brand.stores.length === 0 && (
                                    <li className="py-3 text-sm text-gray-400 dark:text-gray-500">Aucune boutique pour l'instant.</li>
                                )}
                                {brand.stores.map((store) => (
                                    <li key={store.id} className="flex items-center justify-between py-2.5">
                                        <span className="text-sm text-gray-700 dark:text-gray-300">{store.name}</span>
                                        <div className="flex items-center gap-3">
                                            <Link href="/stores" className="text-xs font-medium text-brand-500 hover:text-brand-600">
                                                Config. WhatsApp →
                                            </Link>
                                            <button
                                                type="button"
                                                onClick={() => destroyStore(store)}
                                                className="text-xs font-medium text-error-500 hover:text-error-600"
                                            >
                                                Supprimer
                                            </button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </ComponentCard>
                    ))}
                </div>
            </div>

            {modalBrand !== undefined && <BrandFormModal isOpen={true} onClose={() => setModalBrand(undefined)} brand={modalBrand} />}
            {newStoreBrand && (
                <StoreFormModal
                    isOpen={true}
                    onClose={() => setNewStoreBrand(null)}
                    brandId={newStoreBrand.id}
                    brandName={newStoreBrand.name}
                />
            )}
        </>
    );
}

OrganisationIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OrganisationIndex;
