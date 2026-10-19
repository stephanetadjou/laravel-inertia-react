import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, StatStrip } from '../../components/page/Page';
import BrandFormModal, { BrandFormValues } from './BrandFormModal';
import StoreFormModal from './StoreFormModal';
import LifecycleSettingsCard, { LifecycleSettings } from './LifecycleSettingsCard';
import PrivacyPolicyCard, { PrivacySettings } from './PrivacyPolicyCard';
import WhatsAppApiCard from './WhatsAppApiCard';

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
    lifecycleSettings: LifecycleSettings;
    lifecycleDefaults: LifecycleSettings;
    privacy: PrivacySettings;
    whatsappApiEnabled: boolean;
    [key: string]: unknown;
}

function OrganisationIndex() {
    const { organization, brands, lifecycleSettings, lifecycleDefaults, privacy, whatsappApiEnabled } = usePage<PageProps>().props;
    const [modalBrand, setModalBrand] = useState<BrandFormValues | null | undefined>(undefined);
    const [newStoreBrand, setNewStoreBrand] = useState<{ id: number; name: string } | null>(null);

    const { data, setData, put, processing, errors } = useForm({ name: organization.name });

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
        <Page
            title="Organisation"
            subtitle="Nom du Groupe, marques et boutiques, règles du parcours client"
            actions={
                <>
                    <Link href="/stores" className="mdash-btn ghost">
                        Connexions WhatsApp
                    </Link>
                    <button type="button" className="mdash-btn brand" onClick={() => setModalBrand(null)}>
                        <PlusIcon />
                        Nouvelle marque
                    </button>
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Marques', value: brands.length },
                    { label: 'Boutiques', value: brands.reduce((n, b) => n + b.stores_count, 0) },
                ]}
            />

            <div className="mdash-grid">
                <Panel title="Entreprise" className="c4">
                    <form onSubmit={submitOrganization} className="mpage-form">
                        <div className="mpage-field">
                            <label htmlFor="org_name">Nom de l'organisation</label>
                            <input id="org_name" type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <span className="err">{errors.name}</span>}
                        </div>
                        <div className="mpage-form-actions">
                            <button type="submit" className="mdash-btn brand" disabled={processing || !data.name.trim()}>
                                {processing ? 'Enregistrement…' : 'Enregistrer'}
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Marques et boutiques" sub="La connexion WhatsApp de chaque boutique se règle dans Marques & boutiques" className="c8">
                    {brands.length === 0 ? (
                        <Empty title="Aucune marque">Créez une marque, puis ajoutez-lui des boutiques.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Marque</th>
                                        <th>Boutiques</th>
                                        <th className="r">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {brands.map((brand) => (
                                        <tr key={brand.id}>
                                            <td className="cell-main">
                                                <b>{brand.name}</b>
                                                <span>
                                                    {brand.stores_count} boutique{brand.stores_count > 1 ? 's' : ''}
                                                </span>
                                            </td>
                                            <td className="wrap">
                                                {brand.stores.length === 0 ? (
                                                    <span className="mpage-muted">Aucune boutique</span>
                                                ) : (
                                                    <span className="mpage-chips">
                                                        {brand.stores.map((store) => (
                                                            <span key={store.id} className="mpage-chip">
                                                                {store.name}{' '}
                                                                <button
                                                                    type="button"
                                                                    className="morg-x"
                                                                    title={`Supprimer ${store.name}`}
                                                                    aria-label={`Supprimer la boutique ${store.name}`}
                                                                    onClick={() => destroyStore(store)}
                                                                >
                                                                    ×
                                                                </button>
                                                            </span>
                                                        ))}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="r">
                                                <span className="mpage-rowlinks">
                                                    <button type="button" onClick={() => setNewStoreBrand({ id: brand.id, name: brand.name })}>
                                                        + Boutique
                                                    </button>
                                                    <button type="button" onClick={() => setModalBrand({ id: brand.id, name: brand.name })}>
                                                        Renommer
                                                    </button>
                                                    {brand.stores_count === 0 && (
                                                        <button type="button" className="danger" onClick={() => destroyBrand(brand)}>
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

                <div className="c12">
                    <LifecycleSettingsCard settings={lifecycleSettings} defaults={lifecycleDefaults} />
                </div>

                <WhatsAppApiCard enabled={whatsappApiEnabled} />

                <PrivacyPolicyCard privacy={privacy} />
            </div>

            {modalBrand !== undefined && <BrandFormModal isOpen={true} onClose={() => setModalBrand(undefined)} brand={modalBrand} />}
            {newStoreBrand && <StoreFormModal isOpen={true} onClose={() => setNewStoreBrand(null)} brandId={newStoreBrand.id} brandName={newStoreBrand.name} />}
        </Page>
    );
}

OrganisationIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OrganisationIndex;
