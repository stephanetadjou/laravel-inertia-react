import { Link, router, usePage } from '@inertiajs/react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { customersUrl } from '../../components/families/families';
import { Empty, Page, Panel, Seg, StatStrip } from '../../components/page/Page';
import AudienceActions from '../../components/audience/AudienceActions';

interface PerBrand {
    id: number;
    name: string;
    customers: number;
    new: number;
    returning: number;
    also_elsewhere: number;
    net: number;
    orders: number;
    segments: Record<string, number>;
}

interface PageProps {
    months: number;
    brands: { id: number; name: string }[];
    kpis: { customers: number; multi: number; multi_share: number; multi_spend: number; mono_spend: number; multi_orders: number; multi_avg_spend: number; mono_avg_spend: number };
    matrix: { brand_id: number; brand: string; customers: number; shared: (number | null)[] }[];
    since: string | null;
    perBrand: PerBrand[];
    segments: { key: string; label: string }[];
    currency: string;
    [key: string]: unknown;
}

function MultibrandIndex() {
    const { months, brands, kpis, matrix, perBrand, segments, currency, since } = usePage<PageProps>().props;
    const money = (n: number) => `${formatNumber(n)} ${currency}`;
    const windowFilter = since ? { bought_from: since } : {};

    return (
        <Page
            title="Multimarque"
            subtitle="Une fiche par client pour tout le groupe : qui achète dans plusieurs marques, et qui pourrait le faire"
            filters={
                <Seg
                    label="Clients ayant acheté sur"
                    value={String(months)}
                    options={[
                        { key: '6', label: '6 mois' },
                        { key: '12', label: '12 mois' },
                        { key: '24', label: '24 mois' },
                        { key: '0', label: 'Tout' },
                    ]}
                    onChange={(key) => router.get('/multibrand', { months: key }, { preserveState: true })}
                />
            }
        >
            <StatStrip
                items={[
                    { label: 'Clients identifiés', value: kpis.customers },
                    { label: 'Clients multimarques', value: kpis.multi, hint: `${kpis.multi_share} % des clients` },
                    { label: 'CA des multimarques', value: money(kpis.multi_spend), hint: `${formatNumber(kpis.multi_orders)} tickets` },
                    { label: 'CA moyen multimarque', value: money(kpis.multi_avg_spend), hint: `mono-marque : ${money(kpis.mono_avg_spend)}` },
                ]}
            />

            <div className="mpage-note" style={{ display: 'flex', gap: 14, flexWrap: 'wrap', alignItems: 'center' }}>
                <b>Exporter ou relancer :</b>
                <span>
                    Multimarques <AudienceActions compact filters={{ brands: 'multi', ...windowFilter }} label="Clients multimarques" />
                </span>
                <span>
                    Mono-marque <AudienceActions compact filters={{ brands: 'mono', ...windowFilter }} label="Clients mono-marque" />
                </span>
            </div>

            <div className="mdash-grid">
                <Panel title="Clients communs entre marques" sub="Lecture : parmi les clients de la marque en ligne, combien ont aussi acheté dans la marque en colonne" className="c12">
                    {brands.length < 2 ? (
                        <Empty title="Une seule marque">La matrice apparaît dès que le groupe a au moins deux marques.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Marque</th>
                                        <th className="r">Clients</th>
                                        {brands.map((b) => (
                                            <th key={b.id} className="r">
                                                aussi {b.name}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {matrix.map((row) => (
                                        <tr key={row.brand}>
                                            <td className="cell-main">
                                                <b>{row.brand}</b>
                                            </td>
                                            <td className="r num">{formatNumber(row.customers)}</td>
                                            {row.shared.map((n, i) => (
                                                <td key={i} className="r num">
                                                    {n === null ? '—' : `${formatNumber(n)} (${row.customers ? Math.round((n / row.customers) * 100) : 0} %)`}
                                                    {n ? (
                                                        <div>
                                                            <AudienceActions
                                                                compact
                                                                filters={{ brand: row.brand_id, with_brand: brands[i].id, ...windowFilter }}
                                                                label={`Clients ${row.brand} et ${brands[i].name}`}
                                                            />
                                                        </div>
                                                    ) : null}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <Panel title="Par marque" sub="Nouveaux : premier achat dans la marque depuis moins d’un an. Récurrents : au moins 2 tickets dans la marque" className="c12">
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Marque</th>
                                    <th className="r">Clients</th>
                                    <th className="r">Nouveaux</th>
                                    <th className="r">Récurrents</th>
                                    <th className="r">Achètent aussi ailleurs</th>
                                    <th className="r">CA net</th>
                                    <th className="r">Tickets</th>
                                    <th>Vente croisée</th>
                                    <th className="r">Clients</th>
                                </tr>
                            </thead>
                            <tbody>
                                {perBrand.map((b) => (
                                    <tr key={b.id}>
                                        <td className="cell-main">
                                            <Link href={customersUrl({ brand: b.id })} className="mdash-link">
                                                <b>{b.name}</b>
                                            </Link>
                                        </td>
                                        <td className="r num">{formatNumber(b.customers)}</td>
                                        <td className="r num">{formatNumber(b.new)}</td>
                                        <td className="r num">{formatNumber(b.returning)}</td>
                                        <td className="r num">{formatNumber(b.also_elsewhere)}</td>
                                        <td className="r num">{money(b.net)}</td>
                                        <td className="r num">{formatNumber(b.orders)}</td>
                                        <td>
                                            <Link href={`/actions?list=cross_sell&brand=${b.id}`} className="mdash-link">
                                                Fidèles à conquérir ailleurs
                                            </Link>
                                        </td>
                                        <td className="r">
                                            <AudienceActions compact filters={{ brand: b.id, ...windowFilter }} label={`Clients ${b.name}`} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <Panel title="Segments RFM par marque" sub="Calculés parmi les acheteurs de chaque marque ; cliquez pour cibler ces clients" className="c12">
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Segment</th>
                                    {perBrand.map((b) => (
                                        <th key={b.id} className="r">
                                            {b.name}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {segments.map((s) => (
                                    <tr key={s.key}>
                                        <td className="cell-main">
                                            <b>{s.label}</b>
                                        </td>
                                        {perBrand.map((b) => (
                                            <td key={b.id} className="r num">
                                                {b.segments[s.key] ? (
                                                    <>
                                                        <Link href={customersUrl({ brand: b.id, segment: s.key })} className="mdash-link">
                                                            {formatNumber(b.segments[s.key])}
                                                        </Link>
                                                        <div>
                                                            <AudienceActions compact filters={{ brand: b.id, segment: s.key }} label={`${s.label} ${b.name}`} />
                                                        </div>
                                                    </>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            </div>
        </Page>
    );
}

MultibrandIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default MultibrandIndex;
