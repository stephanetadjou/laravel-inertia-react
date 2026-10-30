import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, StatStrip } from '../../components/page/Page';
import BrandFormModal, { BrandFormValues } from './BrandFormModal';
import StoreFormModal, { StoreFormValues } from './StoreFormModal';
import LifecycleSettingsCard, { LifecycleSettings } from './LifecycleSettingsCard';
import PrivacyPolicyCard, { PrivacySettings } from './PrivacyPolicyCard';
import WhatsAppApiCard from './WhatsAppApiCard';

interface StoreRow {
    id: number;
    name: string;
    code: string | null;
    city: string | null;
    is_active: boolean;
}

interface Option {
    value: string;
    label: string;
}

interface BrandRow {
    id: number;
    name: string;
    stores_count: number;
    stores: StoreRow[];
}

interface PageProps {
    organization: { id: number; name: string; sector: string | null; currency: string; timezone: string; product_analysis_level: string };
    currencies: Option[];
    timezones: string[];
    analysisLevels: Option[];
    sectors: { key: string; label: string; description: string }[];
    brands: BrandRow[];
    lifecycleSettings: LifecycleSettings;
    lifecycleDefaults: LifecycleSettings;
    privacy: PrivacySettings;
    whatsappApiEnabled: boolean;
    [key: string]: unknown;
}

function OrganisationIndex() {
    const { organization, sectors, currencies, timezones, analysisLevels, brands, lifecycleSettings, lifecycleDefaults, privacy, whatsappApiEnabled } = usePage<PageProps>().props;
    const [modalBrand, setModalBrand] = useState<BrandFormValues | null | undefined>(undefined);
    const [newStoreBrand, setNewStoreBrand] = useState<{ id: number; name: string } | null>(null);
    const [editStore, setEditStore] = useState<{ brand: BrandRow; store: StoreFormValues } | null>(null);

    const { data, setData, put, processing, errors } = useForm({
        name: organization.name,
        sector: organization.sector ?? '',
        currency: organization.currency,
        timezone: organization.timezone,
        product_analysis_level: organization.product_analysis_level,
    });
    const sector = sectors.find((s) => s.key === data.sector);

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
            title="Entreprise"
            subtitle="Nom, marques et boutiques, règles du parcours client"
            actions={
                <>
                    {whatsappApiEnabled && (
                        <Link href="/settings/whatsapp" className="mdash-btn ghost">
                            Connexions WhatsApp
                        </Link>
                    )}
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
                        <div className="mpage-field">
                            <label htmlFor="org_sector">Secteur d’activité</label>
                            <select id="org_sector" value={data.sector} onChange={(e) => setData('sector', e.target.value)}>
                                {!organization.sector && <option value="">Non précisé (tout afficher)</option>}
                                {sectors.map((s) => (
                                    <option key={s.key} value={s.key}>
                                        {s.label}
                                    </option>
                                ))}
                            </select>
                            <small>{sector ? sector.description : 'Le secteur adapte le menu : par exemple, pas d’agenda pour le commerce.'}</small>
                            {errors.sector && <span className="err">{errors.sector}</span>}
                        </div>
                        <div className="mpage-form-grid">
                            <div className="mpage-field">
                                <label htmlFor="org_currency">Devise</label>
                                <select id="org_currency" value={data.currency} onChange={(e) => setData('currency', e.target.value)}>
                                    {currencies.map((c) => (
                                        <option key={c.value} value={c.value}>
                                            {c.label}
                                        </option>
                                    ))}
                                </select>
                                {errors.currency && <span className="err">{errors.currency}</span>}
                            </div>
                            <div className="mpage-field">
                                <label htmlFor="org_timezone">Fuseau horaire</label>
                                <select id="org_timezone" value={data.timezone} onChange={(e) => setData('timezone', e.target.value)}>
                                    {timezones.map((t) => (
                                        <option key={t} value={t}>
                                            {t.replace('_', ' ')}
                                        </option>
                                    ))}
                                </select>
                                {errors.timezone && <span className="err">{errors.timezone}</span>}
                            </div>
                        </div>
                        <div className="mpage-field">
                            <label htmlFor="org_level">Analyser les ventes par</label>
                            <select id="org_level" value={data.product_analysis_level} onChange={(e) => setData('product_analysis_level', e.target.value)}>
                                {analysisLevels.map((l) => (
                                    <option key={l.value} value={l.value}>
                                        {l.label}
                                    </option>
                                ))}
                            </select>
                            <small>
                                Article : chaque libellé vu dans vos imports. Famille : regroupement d’articles (ex. Sodas). Catégorie : niveau au-dessus des familles (ex.
                                Boissons). Analyses, ciblage et rapports suivent ce choix.
                            </small>
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
                                                            <span key={store.id} className="mpage-chip" style={store.is_active ? undefined : { opacity: 0.6 }}>
                                                                <button
                                                                    type="button"
                                                                    className="morg-x"
                                                                    style={{ fontWeight: 600 }}
                                                                    title={`Modifier ${store.name}`}
                                                                    onClick={() => setEditStore({ brand, store })}
                                                                >
                                                                    {store.name}
                                                                    {store.code ? ` · ${store.code}` : ''}
                                                                    {store.city ? ` · ${store.city}` : ''}
                                                                    {store.is_active ? '' : ' (fermé)'}
                                                                </button>{' '}
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
            {editStore && (
                <StoreFormModal isOpen={true} onClose={() => setEditStore(null)} brandId={editStore.brand.id} brandName={editStore.brand.name} store={editStore.store} />
            )}
            {newStoreBrand && <StoreFormModal isOpen={true} onClose={() => setNewStoreBrand(null)} brandId={newStoreBrand.id} brandName={newStoreBrand.name} />}
        </Page>
    );
}

OrganisationIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default OrganisationIndex;
