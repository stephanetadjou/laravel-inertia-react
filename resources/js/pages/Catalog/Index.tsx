import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, Page, Panel, PlusIcon, SearchFilter, Seg } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

/*
 * Paramètres › Catalogue : prestations (durée, réservables dans l'agenda) et
 * produits (vendus en caisse), dans une seule liste. Un article vendu ou
 * réservé s'archive plutôt que de disparaître.
 */

export interface CatalogItem {
    id: number;
    type: 'service' | 'product';
    name: string;
    category_id: number | null;
    category: string | null;
    price: number;
    duration_minutes: number | null;
    reference: string | null;
    active: boolean;
    bookable_online: boolean;
    store_ids: number[];
    sold?: number;
    revenue?: number;
}

interface PageProps {
    items: CatalogItem[];
    categories: { id: number; name: string }[];
    stores: { id: number; name: string }[];
    types: Record<string, string>;
    [key: string]: unknown;
}

type Tab = 'service' | 'product' | 'archived';

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

export function formatDuration(minutes: number | null): string {
    if (!minutes) return '—';
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return h ? `${h} h${m ? ` ${String(m).padStart(2, '0')}` : ''}` : `${m} min`;
}

function ItemModal({ item, type, categories, stores, onClose }: { item: CatalogItem | null; type: 'service' | 'product'; categories: PageProps['categories']; stores: PageProps['stores']; onClose: () => void }) {
    const { data, setData, post, put, processing, errors } = useForm({
        type: item?.type ?? type,
        name: item?.name ?? '',
        category_id: item?.category_id ? String(item.category_id) : '',
        price: item ? String(item.price) : '',
        duration_minutes: item?.duration_minutes ? String(item.duration_minutes) : '30',
        reference: item?.reference ?? '',
        store_ids: item?.store_ids ?? ([] as number[]),
        bookable_online: item?.bookable_online ?? true,
    });
    const isService = data.type === 'service';

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: onClose };
        if (item) put(`/catalog/${item.id}`, options);
        else post('/catalog', options);
    };

    function toggleStore(id: number) {
        setData('store_ids', data.store_ids.includes(id) ? data.store_ids.filter((s) => s !== id) : [...data.store_ids, id]);
    }

    return (
        <Modal isOpen={true} onClose={onClose} className="max-w-lg p-6">
            <h3 className="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">{item ? `Modifier « ${item.name} »` : isService ? 'Nouvelle prestation' : 'Nouveau produit'}</h3>
            <form onSubmit={submit} className="space-y-4">
                <div className="flex gap-2" role="group" aria-label="Type">
                    {(['service', 'product'] as const).map((t) => (
                        <button
                            key={t}
                            type="button"
                            aria-pressed={data.type === t}
                            onClick={() => setData('type', t)}
                            className={`flex-1 rounded-lg border px-3 py-2 text-sm ${data.type === t ? 'border-gray-800 font-semibold text-gray-900 dark:border-white dark:text-white' : 'border-gray-300 text-gray-500 dark:border-gray-700'}`}
                        >
                            {t === 'service' ? 'Prestation' : 'Produit'}
                            <span className="block text-xs font-normal text-gray-500">{t === 'service' ? 'a une durée, se réserve dans l’agenda' : 'se vend, ne se réserve pas'}</span>
                        </button>
                    ))}
                </div>

                <div>
                    <label htmlFor="cat-name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom
                    </label>
                    <input id="cat-name" value={data.name} onChange={(e) => setData('name', e.target.value)} className={fieldClasses()} placeholder={isService ? 'ex. Coupe femme, Tresses, Essayage' : 'ex. Shampoing 250 ml, Sac en cuir'} autoFocus />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label htmlFor="cat-price" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Prix (FCFA)
                        </label>
                        <input id="cat-price" type="number" min={0} step={500} value={data.price} onChange={(e) => setData('price', e.target.value)} className={fieldClasses()} />
                        {errors.price && <p className="mt-1.5 text-sm text-error-500">{errors.price}</p>}
                    </div>
                    {isService ? (
                        <div>
                            <label htmlFor="cat-duration" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Durée (minutes)
                            </label>
                            <input id="cat-duration" type="number" min={5} max={600} step={5} value={data.duration_minutes} onChange={(e) => setData('duration_minutes', e.target.value)} className={fieldClasses()} />
                            {errors.duration_minutes && <p className="mt-1.5 text-sm text-error-500">{errors.duration_minutes}</p>}
                        </div>
                    ) : (
                        <div>
                            <label htmlFor="cat-ref" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                Référence (facultatif)
                            </label>
                            <input id="cat-ref" value={data.reference} onChange={(e) => setData('reference', e.target.value)} className={fieldClasses()} placeholder="code article, code-barres" />
                        </div>
                    )}
                </div>

                <div>
                    <label htmlFor="cat-category" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Catégorie
                    </label>
                    <select id="cat-category" value={data.category_id} onChange={(e) => setData('category_id', e.target.value)} className={fieldClasses()}>
                        <option value="">Sans catégorie</option>
                        {categories.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </div>

                {isService && (
                    <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" checked={data.bookable_online} onChange={(e) => setData('bookable_online', e.target.checked)} />
                        Réservable en ligne par les clients
                    </label>
                )}

                {stores.length > 1 && (
                    <fieldset>
                        <legend className="mb-1.5 text-sm font-medium text-gray-700 dark:text-gray-300">Proposé dans</legend>
                        <div className="flex flex-wrap gap-2">
                            {stores.map((s) => (
                                <label key={s.id} className="flex items-center gap-1.5 rounded-lg border border-gray-200 px-2.5 py-1.5 text-sm dark:border-gray-700">
                                    <input type="checkbox" checked={data.store_ids.includes(s.id)} onChange={() => toggleStore(s.id)} />
                                    {s.name}
                                </label>
                            ))}
                        </div>
                        <p className="mt-1 text-xs text-gray-500">Aucun coché : proposé dans tous les établissements.</p>
                    </fieldset>
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : item ? 'Enregistrer' : 'Ajouter au catalogue'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function CatalogIndex() {
    const { items, categories, stores } = usePage<PageProps>().props;
    const [tab, setTab] = useState<Tab>('service');
    const [search, setSearch] = useState('');
    const [modal, setModal] = useState<CatalogItem | null | undefined>(undefined);

    const q = search.trim().toLowerCase();
    const visible = items
        .filter((i) => (tab === 'archived' ? !i.active : i.active && i.type === tab))
        .filter((i) => !q || i.name.toLowerCase().includes(q) || (i.category ?? '').toLowerCase().includes(q));
    const count = (t: Tab) => items.filter((i) => (t === 'archived' ? !i.active : i.active && i.type === t)).length;
    const storeName = (ids: number[]) => (ids.length === 0 ? 'Tous' : ids.map((id) => stores.find((s) => s.id === id)?.name).filter(Boolean).join(', '));

    return (
        <Page
            title="Catalogue"
            subtitle="Prestations à réserver et produits à vendre"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModal(null)}>
                    <PlusIcon />
                    {tab === 'product' ? 'Nouveau produit' : 'Nouvelle prestation'}
                </button>
            }
            filters={
                <>
                    <Seg
                        label="Type"
                        value={tab}
                        options={[
                            { key: 'service', label: `Prestations · ${count('service')}` },
                            { key: 'product', label: `Produits · ${count('product')}` },
                            { key: 'archived', label: `Archivés · ${count('archived')}` },
                        ]}
                        onChange={setTab}
                    />
                    <SearchFilter value={search} placeholder="Nom ou catégorie" onSearch={setSearch} />
                </>
            }
        >
            <Panel>
                {visible.length === 0 ? (
                    <Empty title={tab === 'archived' ? 'Aucun article archivé' : tab === 'service' ? 'Aucune prestation' : 'Aucun produit'}>
                        {tab === 'service' && 'Ajoutez vos prestations avec leur durée : elles se choisiront dans l’agenda et se calculeront à l’encaissement.'}
                        {tab === 'product' && 'Ajoutez les produits que vous vendez : ils s’ajouteront à l’encaissement d’un rendez-vous.'}
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>{tab === 'product' ? 'Produit' : tab === 'service' ? 'Prestation' : 'Article'}</th>
                                    <th>Catégorie</th>
                                    {tab !== 'product' && <th className="r">Durée</th>}
                                    <th className="r">Prix</th>
                                    {stores.length > 1 && <th>Établissements</th>}
                                    <th className="r">Vendus</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((i) => (
                                    <tr key={i.id}>
                                        <td className="cell-main">
                                            <b>{i.name}</b>
                                            <span>{tab === 'archived' ? (i.type === 'service' ? 'Prestation' : 'Produit') : i.reference}</span>
                                        </td>
                                        <td>{i.category ?? '—'}</td>
                                        {tab !== 'product' && <td className="r mono">{formatDuration(i.duration_minutes)}</td>}
                                        <td className="r mono">{formatNumber(i.price)} F</td>
                                        {stores.length > 1 && <td>{storeName(i.store_ids)}</td>}
                                        <td className="r mono" title={i.revenue ? `${formatNumber(i.revenue)} FCFA encaissés` : undefined}>
                                            {formatNumber(i.sold ?? 0)}
                                        </td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setModal(i)}>
                                                    Modifier
                                                </button>
                                                <button type="button" className={i.active ? 'danger' : ''} onClick={() => router.post(`/catalog/${i.id}/toggle`, {}, { preserveScroll: true })}>
                                                    {i.active ? 'Archiver' : 'Réactiver'}
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

            {modal !== undefined && <ItemModal key={modal?.id ?? 'new'} item={modal} type={tab === 'product' ? 'product' : 'service'} categories={categories} stores={stores} onClose={() => setModal(undefined)} />}
        </Page>
    );
}

CatalogIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CatalogIndex;
