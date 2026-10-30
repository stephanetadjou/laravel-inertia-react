import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, formatDay, Page, Pager, Paginated, Panel } from '../../components/page/Page';
import { statusTone } from './status';

export interface BatchSummary {
    id: number;
    filename: string;
    status: string;
    status_label: string;
    user: string | null;
    brand: string | null;
    store: string | null;
    created_at: string | null;
    completed_at: string | null;
    total_rows: number;
    accepted_rows: number;
    rejected_rows: number;
    duplicate_rows: number;
    tickets_count: number;
    lines_count: number;
    anonymous_tickets: number;
    unclassified_lines: number;
    customers_created: number;
}

interface PageProps {
    batches: Paginated<BatchSummary>;
    brands: { id: number; name: string }[];
    stores: { id: number; name: string; brand_id: number; code: string | null }[];
    fields: { key: string; label: string; help: string }[];
    [key: string]: unknown;
}

function SalesImportsIndex() {
    const { batches, brands, stores, fields } = usePage<PageProps>().props;
    const { data, setData, post, processing, errors } = useForm<{ file: File | null; brand_id: string; store_id: string }>({ file: null, brand_id: '', store_id: '' });
    const brandStores = data.brand_id ? stores.filter((s) => String(s.brand_id) === data.brand_id) : stores;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/sales-imports', { forceFormData: true });
    };

    return (
        <Page title="Imports de ventes" subtitle="Déposez l’export de votre caisse ou de votre ERP : Menentia range chaque vente sur la bonne fiche client">
            <div className="mdash-grid">
                <Panel title="Nouvel import" sub="CSV ou Excel, une ligne par article vendu (ou par ticket)" className="c5">
                    <form onSubmit={submit} className="mpage-form">
                        <div className="mpage-field">
                            <label htmlFor="file">Fichier</label>
                            <input id="file" type="file" accept=".csv,.txt,.xlsx,.xls" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                            {errors.file && <span className="err">{errors.file}</span>}
                        </div>
                        <div className="mpage-form-grid">
                            {brands.length > 1 && (
                                <div className="mpage-field">
                                    <label htmlFor="brand">Marque (facultatif)</label>
                                    <select id="brand" value={data.brand_id} onChange={(e) => setData((d) => ({ ...d, brand_id: e.target.value, store_id: '' }))}>
                                        <option value="">Toutes</option>
                                        {brands.map((b) => (
                                            <option key={b.id} value={b.id}>
                                                {b.name}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                            {stores.length > 1 && (
                                <div className="mpage-field">
                                    <label htmlFor="store">Point de vente (facultatif)</label>
                                    <select id="store" value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                        <option value="">Lu dans le fichier</option>
                                        {brandStores.map((s) => (
                                            <option key={s.id} value={s.id}>
                                                {s.name}
                                                {s.code ? ` (${s.code})` : ''}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}
                        </div>
                        <small className="mpage-muted">
                            Fixez le point de vente si le fichier n’en contient qu’un ; sinon il est lu dans une colonne (nom ou code du point de vente).
                        </small>
                        <div className="mpage-form-actions">
                            <button type="submit" className="mdash-btn brand" disabled={processing || !data.file}>
                                {processing ? 'Envoi…' : 'Déposer et associer les colonnes'}
                            </button>
                        </div>
                    </form>
                </Panel>

                <Panel title="Ce que Menentia sait lire" sub="Les colonnes sont reconnues automatiquement, puis vous vérifiez" className="c7">
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <tbody>
                                {fields.map((f) => (
                                    <tr key={f.key}>
                                        <td className="cell-main">
                                            <b>{f.label}</b>
                                        </td>
                                        <td className="wrap mpage-muted">{f.help}</td>
                                    </tr>
                                ))}
                                <tr>
                                    <td className="cell-main">
                                        <b>Autres colonnes</b>
                                    </td>
                                    <td className="wrap mpage-muted">Gardées comme attributs de la vente (marque, taille, canal, région…) et utilisables dans les analyses.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Panel>

                <Panel title="Historique des imports" className="c12">
                    {batches.data.length === 0 ? (
                        <Empty title="Aucun import">Vos fichiers de ventes apparaîtront ici avec leur bilan.</Empty>
                    ) : (
                        <div className="mdash-tbl mpage-table">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Fichier</th>
                                        <th>Déposé</th>
                                        <th>Statut</th>
                                        <th className="r">Lignes</th>
                                        <th className="r">Rejets</th>
                                        <th className="r">Doublons</th>
                                        <th className="r">Tickets</th>
                                        <th className="r">Anonymes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {batches.data.map((b) => (
                                        <tr key={b.id} className="mpage-clickable" onClick={() => router.visit(`/sales-imports/${b.id}`)}>
                                            <td className="cell-main">
                                                <Link href={`/sales-imports/${b.id}`}>
                                                    <b>{b.filename}</b>
                                                </Link>
                                                <span>
                                                    N° {b.id}
                                                    {b.store ? ` · ${b.store}` : b.brand ? ` · ${b.brand}` : ''}
                                                    {b.user ? ` · ${b.user}` : ''}
                                                </span>
                                            </td>
                                            <td>{formatDay(b.created_at, true)}</td>
                                            <td>
                                                <span className={`mdash-pill ${statusTone(b.status)}`}>{b.status_label}</span>
                                            </td>
                                            <td className="r num">{formatNumber(b.total_rows)}</td>
                                            <td className="r num">{formatNumber(b.rejected_rows)}</td>
                                            <td className="r num">{formatNumber(b.duplicate_rows)}</td>
                                            <td className="r num">{formatNumber(b.tickets_count)}</td>
                                            <td className="r num">{formatNumber(b.anonymous_tickets)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pager page={batches} onPage={(n) => router.get('/sales-imports', { page: n }, { preserveScroll: true })} unit="imports" />
                </Panel>
            </div>
        </Page>
    );
}

SalesImportsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default SalesImportsIndex;
