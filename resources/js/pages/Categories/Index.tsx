import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, Page, Panel, PlusIcon, SearchFilter, SelectFilter, StatStrip, useQueryFilters } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import { Share, capitalize, customersUrl, money } from '../../components/families/families';
import './families.css';

interface CategoryRow {
    id: number;
    name: string;
    keywords: string | null;
    parent_id?: number | null;
    purchases_count: number;
}

interface FamilyStats {
    id: number;
    name: string;
    customers: number;
    amount: number;
    share: number;
    average_basket: number;
    repeat_rate: number;
    lapsed: number;
}

interface PropertyRow {
    value: string;
    customers: number;
    amount: number;
    share: number;
    average_basket: number;
    repeat_rate: number;
}

interface PageProps {
    categories: CategoryRow[];
    overview: { rows: FamilyStats[]; total: number; unclassified: number; customers: number };
    period: number;
    periods: { value: number; label: string }[];
    lapseDays: number;
    properties: { key: string; label: string; values: string[] }[];
    by: string | null;
    byProperty: PropertyRow[] | null;
    unclassified: { labels: number; purchases: number; amount: number };
    familyWord: string;
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function CategoryFormModal({ onClose, category, word, parents }: { onClose: () => void; category: CategoryRow | null; word: string; parents: CategoryRow[] }) {
    const isEditing = category !== null;
    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: category?.name ?? '',
        keywords: category?.keywords ?? '',
        parent_id: category?.parent_id ? String(category.parent_id) : '',
    });

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
        <Modal isOpen={true} onClose={onClose} className="max-w-md p-6">
            <h3 className="mb-6 text-lg font-semibold text-gray-800 dark:text-white/90">{isEditing ? `Modifier la ${word}` : `Nouvelle ${word}`}</h3>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom
                    </label>
                    <input id="name" type="text" value={data.name} onChange={(e) => setData('name', e.target.value)} placeholder="ex. Boissons, Chaussures, Cosmétiques…" className={fieldClasses()} autoFocus />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>
                <div>
                    <label htmlFor="keywords" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Mots-clés (facultatif)
                    </label>
                    <input id="keywords" type="text" value={data.keywords} onChange={(e) => setData('keywords', e.target.value)} placeholder="ex. coca, fanta, jus, eau" className={fieldClasses()} />
                    <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                        Séparés par des virgules. Un article importé qui contient l’un de ces mots est rangé ici automatiquement (« COCA 33CL » → Boissons).
                    </p>
                    {errors.keywords && <p className="mt-1.5 text-sm text-error-500">{errors.keywords}</p>}
                </div>

                <div>
                    <label htmlFor="parent_id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Rangée sous la catégorie (facultatif)
                    </label>
                    <select id="parent_id" value={data.parent_id} onChange={(e) => setData('parent_id', e.target.value)} className={fieldClasses()}>
                        <option value="">— Aucune (premier niveau) —</option>
                        {parents
                            .filter((p) => p.id !== category?.id && !p.parent_id)
                            .map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                    </select>
                    <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Ex. Sodas rangée sous Boissons : les analyses « par catégorie » regroupent ses ventes avec celles de Boissons.</p>
                    {errors.parent_id && <p className="mt-1.5 text-sm text-error-500">{errors.parent_id}</p>}
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement…' : isEditing ? 'Enregistrer' : 'Créer'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function CategoriesIndex() {
    const { categories, overview, period, periods, lapseDays, properties, by, byProperty, unclassified, familyWord } = usePage<PageProps>().props;
    const [modalCategory, setModalCategory] = useState<CategoryRow | null | undefined>(undefined);
    const [search, setSearch] = useState('');
    const applyFilters = useQueryFilters('/categories', { period: period === 12 ? null : period, by });

    const Word = capitalize(familyWord);
    const plural = familyWord === 'famille' ? 'familles' : 'catégories';
    const q = search.trim().toLowerCase();
    const byId = new Map(categories.map((c) => [c.id, c]));
    const rows = overview.rows.filter((r) => !q || r.name.toLowerCase().includes(q));
    const classifiedShare = overview.total > 0 ? Math.round(((overview.total - overview.unclassified) / overview.total) * 100) : 0;
    const property = properties.find((p) => p.key === by);

    function destroy(category: CategoryRow) {
        if (confirm(`Supprimer « ${category.name} » ? Ses ventes repasseront dans « À classer », rien n’est perdu.`)) {
            router.delete(`/categories/${category.id}`, { preserveScroll: true });
        }
    }

    return (
        <Page
            title={capitalize(plural)}
            subtitle={`Comment vos clients achètent, ${familyWord} par ${familyWord}, sans gérer chaque produit`}
            actions={
                <>
                    {unclassified.labels > 0 && (
                        <Link href="/categories/a-classer" className="mdash-btn ghost">
                            À classer ({formatNumber(unclassified.labels)})
                        </Link>
                    )}
                    <button type="button" className="mdash-btn brand" onClick={() => setModalCategory(null)}>
                        <PlusIcon />
                        Nouvelle {familyWord}
                    </button>
                </>
            }
            filters={
                <>
                    <SelectFilter
                        label="Période"
                        value={period === 12 ? null : period}
                        allLabel="12 derniers mois"
                        options={periods.filter((p) => p.value !== 12)}
                        onChange={(v) => applyFilters({ period: v })}
                    />
                    {properties.length > 0 && (
                        <SelectFilter
                            label="Analyser par"
                            value={by}
                            allLabel={Word}
                            options={properties.map((p) => ({ value: p.key, label: p.label }))}
                            onChange={(v) => applyFilters({ by: v })}
                        />
                    )}
                    {categories.length > 5 && !by && <SearchFilter value={search} placeholder={`Nom de la ${familyWord}`} onSearch={setSearch} />}
                </>
            }
        >
            <StatStrip
                items={[
                    { label: capitalize(plural), value: categories.length },
                    { label: 'Clients acheteurs', value: overview.customers },
                    { label: "Chiffre d'affaires", value: money(overview.total) },
                    { label: 'CA rangé dans une ' + familyWord, value: `${classifiedShare} %`, tone: classifiedShare < 80 && overview.total > 0 ? 'warn' : undefined },
                ]}
            />

            {unclassified.labels > 0 && (
                <div className="mfam-alert">
                    <span>
                        <b>{formatNumber(unclassified.labels)} libellé(s) importé(s)</b> ne sont rangés dans aucune {familyWord} ({formatNumber(unclassified.purchases)} ventes,{' '}
                        {money(unclassified.amount)}). Rangez-les une fois : les prochains imports s’en souviendront.
                    </span>
                    <Link href="/categories/a-classer" className="mdash-btn brand">
                        Classer maintenant
                    </Link>
                </div>
            )}

            {property && byProperty ? (
                <Panel title={`Par ${property.label.toLowerCase()}`} sub="Attribut importé avec les ventes (colonne libre du fichier) ou saisi sur les produits du catalogue">
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>{property.label}</th>
                                    <th className="r">Clients</th>
                                    <th className="r">CA</th>
                                    <th>Part du CA</th>
                                    <th className="r">Panier moyen</th>
                                    <th className="r">Réachat</th>
                                    <th className="r">Cibler</th>
                                </tr>
                            </thead>
                            <tbody>
                                {byProperty.map((r) => (
                                    <tr key={r.value}>
                                        <td className="cell-main">
                                            <b>{r.value}</b>
                                        </td>
                                        <td className="r num">{formatNumber(r.customers)}</td>
                                        <td className="r num">{money(r.amount)}</td>
                                        <td>
                                            <Share value={r.share} />
                                        </td>
                                        <td className="r num">{money(r.average_basket)}</td>
                                        <td className="r num">{r.repeat_rate} %</td>
                                        <td className="r">
                                            {r.value !== 'Non renseigné' && (
                                                <Link href={customersUrl({ prop: `${property.key}:${r.value}` })} className="mdash-link">
                                                    Clients
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            ) : (
                <Panel
                    title={`Comportement par ${familyWord}`}
                    sub={`Réachat : clients venus au moins deux jours différents. Abandons : n’ont plus rien acheté dans la ${familyWord} depuis ${lapseDays} jours mais achètent encore autre chose chez vous.`}
                >
                    {rows.length === 0 ? (
                        <Empty title={categories.length === 0 ? `Aucune ${familyWord} pour l’instant` : 'Aucun résultat'}>
                            {categories.length === 0 && `Créez vos ${plural} (Boissons, Épicerie, Hygiène…) ou importez vos ventes avec une colonne « categorie », puis rangez les libellés dans « À classer ».`}
                        </Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{Word}</th>
                                        <th className="r">Clients</th>
                                        <th className="r">CA</th>
                                        <th>Part du CA</th>
                                        <th className="r">Panier moyen</th>
                                        <th className="r">Réachat</th>
                                        <th className="r">Abandons</th>
                                        <th className="r">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map((r) => {
                                        const category = byId.get(r.id);
                                        return (
                                            <tr key={r.id}>
                                                <td className="cell-main">
                                                    <Link href={`/categories/${r.id}${period !== 12 ? `?period=${period}` : ''}`} className="mdash-link">
                                                        <b>{r.name}</b>
                                                    </Link>
                                                    {category?.keywords && <span title="Mots-clés">{category.keywords}</span>}
                                                </td>
                                                <td className="r num">{formatNumber(r.customers)}</td>
                                                <td className="r num">{money(r.amount)}</td>
                                                <td>
                                                    <Share value={r.share} />
                                                </td>
                                                <td className="r num">{money(r.average_basket)}</td>
                                                <td className="r num">{r.repeat_rate} %</td>
                                                <td className="r num">
                                                    {r.lapsed > 0 ? (
                                                        <Link
                                                            href={customersUrl({ family: r.id, family_mode: 'lapsed', family_days: lapseDays })}
                                                            className="mdash-link"
                                                            title="Voir ces clients pour les relancer"
                                                        >
                                                            {formatNumber(r.lapsed)}
                                                        </Link>
                                                    ) : (
                                                        <span className="mpage-muted">0</span>
                                                    )}
                                                </td>
                                                <td className="r">
                                                    {category && (
                                                        <span className="mpage-rowlinks">
                                                            <button type="button" onClick={() => setModalCategory(category)}>
                                                                Modifier
                                                            </button>
                                                            <button type="button" className="danger" onClick={() => destroy(category)}>
                                                                Supprimer
                                                            </button>
                                                        </span>
                                                    )}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            )}

            <div className="mpage-note">
                Les ventes importées sont rangées automatiquement par le nom de la {familyWord}, ses mots-clés, ou les correspondances choisies dans « À classer ». Les
                ventes encaissées dans NENTIX prennent la {familyWord} de l’article du catalogue. Cliquez une {familyWord} pour voir les achats liés et les clients à
                cibler.
            </div>

            {modalCategory !== undefined && <CategoryFormModal key={modalCategory?.id ?? 'new'} onClose={() => setModalCategory(undefined)} category={modalCategory} word={familyWord} parents={categories} />}
        </Page>
    );
}

CategoriesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CategoriesIndex;
