import { Link, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, Page, Panel, SelectFilter, StatStrip, useQueryFilters } from '../../components/page/Page';
import { Share, capitalize, customersUrl, money } from '../../components/families/families';
import './families.css';

interface Stats {
    customers: number;
    amount: number;
    share: number;
    average_basket: number;
    repeat_rate: number;
    lapsed: number;
}

interface PageProps {
    category: { id: number; name: string; keywords: string | null };
    stats: Stats | null;
    affinities: { id: number; name: string; customers: number; share: number; lift: number }[];
    examples: { article: string; count: number; amount: number }[];
    period: number;
    periods: { value: number; label: string }[];
    lapseDays: number;
    familyWord: string;
    [key: string]: unknown;
}

function CategoryShow() {
    const { category, stats, affinities, examples, period, periods, lapseDays, familyWord } = usePage<PageProps>().props;
    const applyFilters = useQueryFilters(`/categories/${category.id}`, { period: period === 12 ? null : period });
    const plural = familyWord === 'famille' ? 'familles' : 'catégories';

    // Listes de clients prêtes à cibler (groupe, campagne, relance depuis Fiches clients).
    const targets: { title: string; text: string; params: Record<string, string | number> }[] = [
        { title: 'Acheteurs récents', text: `Ont acheté ${category.name} ces ${lapseDays} derniers jours`, params: { family: category.id, family_days: lapseDays } },
        { title: 'À relancer', text: `Achetaient ${category.name}, plus rien depuis ${lapseDays} jours`, params: { family: category.id, family_mode: 'lapsed', family_days: lapseDays } },
        { title: 'Tous les acheteurs', text: `Ont acheté ${category.name} au moins une fois`, params: { family: category.id, family_days: 0 } },
        ...affinities.slice(0, 2).map((a) => ({
            title: `Vente croisée : ${a.name}`,
            text: `Achètent ${a.name} mais jamais ${category.name}`,
            params: { family: a.id, family_days: 0, family_not: category.id },
        })),
    ];

    return (
        <Page
            title={category.name}
            subtitle={
                <>
                    <Link href="/categories" className="mdash-link">
                        {capitalize(plural)}
                    </Link>{' '}
                    › comportement d’achat des clients
                </>
            }
            filters={
                <SelectFilter
                    label="Période"
                    value={period === 12 ? null : period}
                    allLabel="12 derniers mois"
                    options={periods.filter((p) => p.value !== 12)}
                    onChange={(v) => applyFilters({ period: v })}
                />
            }
        >
            <StatStrip
                items={[
                    { label: 'Clients', value: stats?.customers ?? 0 },
                    { label: "Chiffre d'affaires", value: money(stats?.amount ?? 0), hint: `${(stats?.share ?? 0).toLocaleString('fr-FR')} % du total` },
                    { label: 'Panier moyen par visite', value: money(stats?.average_basket ?? 0) },
                    { label: 'Taux de réachat', value: `${stats?.repeat_rate ?? 0} %` },
                    { label: 'Abandons', value: stats?.lapsed ?? 0, hint: `plus d’achat depuis ${lapseDays} j`, tone: (stats?.lapsed ?? 0) > 0 ? 'warn' : undefined },
                ]}
            />

            <div className="mdash-grid" style={{ marginTop: 16 }}>
                <Panel title="Clients à cibler" sub="Ouvre la liste filtrée : créez un groupe, une campagne ou relancez depuis Fiches clients" className="c12">
                    <div className="mfam-targets">
                        {targets.map((t) => (
                            <Link key={t.title} href={customersUrl(t.params)} className="mfam-target">
                                <b>{t.title}</b>
                                <span>{t.text}</span>
                            </Link>
                        ))}
                    </div>
                </Panel>

                <Panel title="Achats liés" sub={`Parmi les clients qui achètent ${category.name}, part de ceux qui achètent aussi…`} className="c8">
                    {affinities.length === 0 ? (
                        <Empty title="Pas encore assez de ventes pour voir des liens" />
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>{capitalize(familyWord)}</th>
                                        <th className="r">Clients en commun</th>
                                        <th>Part</th>
                                        <th className="r" title="Au-dessus de 1 : lien plus fort que la moyenne des clients">
                                            Indice
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {affinities.map((a) => (
                                        <tr key={a.id}>
                                            <td className="cell-main">
                                                <Link href={`/categories/${a.id}`} className="mdash-link">
                                                    <b>{a.name}</b>
                                                </Link>
                                            </td>
                                            <td className="r num">{formatNumber(a.customers)}</td>
                                            <td>
                                                <Share value={a.share} />
                                            </td>
                                            <td className="r num">{a.lift.toLocaleString('fr-FR')}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel title="Articles les plus vendus" sub="Libellés tels qu’importés, aucune fiche produit n’est gérée" className="c4">
                    {examples.length === 0 ? (
                        <Empty title="Aucun article importé" />
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <tbody>
                                    {examples.map((e) => (
                                        <tr key={e.article}>
                                            <td className="cell-main">
                                                <b>{e.article}</b>
                                                <span>{formatNumber(e.count)} vente(s)</span>
                                            </td>
                                            <td className="r num">{money(e.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>
            </div>

            {category.keywords && <div className="mpage-note">Mots-clés de reconnaissance : {category.keywords}</div>}
        </Page>
    );
}

CategoryShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CategoryShow;
