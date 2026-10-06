import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Page, Panel, StatStrip } from '../../components/page/Page';

interface StoreOption {
    id: number;
    name: string;
}

interface ImportPreview {
    contacts_to_create: number;
    contacts_to_update: number;
    purchases_to_record: number;
    consents: { opt_in: number; opt_out: number };
    total_valid: number;
    skipped: string[];
    sample: { name: string; phone_e164: string }[];
}

interface PageProps {
    stores: StoreOption[];
    preview: ImportPreview | null;
    [key: string]: unknown;
}

const COLUMNS: [string, string][] = [
    ['nom', 'obligatoire'],
    ['telephone', 'obligatoire, tout format gabonais (062…, +241…)'],
    ['consentement', 'oui / non : accord WhatsApp recueilli en caisse'],
    ['naissance, sexe', 'facultatifs'],
    ['categorie, montant, date_achat', 'facultatifs : enregistrent un achat'],
];

function ImportsIndex() {
    const { stores, preview } = usePage<PageProps>().props;

    const { data, setData, post, processing, errors, reset } = useForm<{ store_id: string; file: File | null }>({
        store_id: stores[0]?.id.toString() ?? '',
        file: null,
    });

    const previewFile: FormEventHandler = (e) => {
        e.preventDefault();
        post('/imports/preview', { forceFormData: true, preserveScroll: true });
    };

    const confirmImport: FormEventHandler = (e) => {
        e.preventDefault();
        post('/imports', { forceFormData: true, preserveScroll: true, onSuccess: () => reset() });
    };

    function cancelPreview() {
        reset('file');
        router.get('/imports', {}, { preserveScroll: true });
    }

    const storeName = stores.find((s) => String(s.id) === data.store_id)?.name;

    return (
        <Page
            title="Import de contacts"
            subtitle="Fichier CSV ou Excel par boutique : aperçu sans risque, puis confirmation"
            actions={
                <>
                    <Link href="/customers" className="mdash-btn ghost">
                        Fiches clients
                    </Link>
                </>
            }
        >
            {!preview ? (
                <div className="mdash-grid">
                    <Panel title="1. Choisir le fichier" className="c6">
                        <form onSubmit={previewFile} className="mpage-form">
                            <div className="mpage-field">
                                <label htmlFor="store_id">Boutique</label>
                                <select id="store_id" value={data.store_id} onChange={(e) => setData('store_id', e.target.value)}>
                                    {stores.map((store) => (
                                        <option key={store.id} value={store.id}>
                                            {store.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.store_id && <span className="err">{errors.store_id}</span>}
                            </div>
                            <div className="mpage-field">
                                <label htmlFor="file">Fichier CSV ou Excel (xlsx, xls)</label>
                                <input id="file" type="file" accept=".csv,.txt,.xlsx,.xls" onChange={(e) => setData('file', e.target.files?.[0] ?? null)} />
                                {errors.file && <span className="err">{errors.file}</span>}
                            </div>
                            <div className="mpage-form-actions">
                                <button type="submit" className="mdash-btn brand" disabled={processing || !data.file}>
                                    {processing ? 'Analyse en cours…' : 'Voir l’aperçu'}
                                </button>
                            </div>
                        </form>
                    </Panel>

                    <Panel title="Colonnes reconnues" sub="Première ligne = en-têtes" className="c6">
                        <dl className="mpage-dl">
                            {COLUMNS.map(([col, help]) => (
                                <div key={col} className="mpage-dl-row">
                                    <dt className="mono">{col}</dt>
                                    <dd>{help}</dd>
                                </div>
                            ))}
                        </dl>
                        <p className="mdash-scope">
                            Un numéro déjà présent dans la boutique met à jour le contact au lieu de créer un doublon. Sans colonne « consentement », les
                            nouveaux contacts restent au consentement inconnu et ne reçoivent aucune campagne marketing.
                        </p>
                    </Panel>
                </div>
            ) : (
                <>
                    <StatStrip
                        items={[
                            { label: 'Nouveaux contacts', value: preview.contacts_to_create, tone: 'ok' },
                            { label: 'Mis à jour', value: preview.contacts_to_update },
                            { label: 'Achats détectés', value: preview.purchases_to_record },
                            { label: 'Opt-in déclarés', value: preview.consents?.opt_in ?? 0 },
                            { label: 'Lignes ignorées', value: preview.skipped.length, tone: preview.skipped.length ? 'warn' : undefined },
                        ]}
                    />

                    <Panel title={`2. Vérifier l'aperçu${storeName ? ` · ${storeName}` : ''}`} sub="Rien n'est encore enregistré">
                        {preview.sample.length > 0 && (
                            <div className="mdash-tbl mpage-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Nom</th>
                                            <th>Téléphone</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.sample.map((row, index) => (
                                            <tr key={index}>
                                                <td>{row.name}</td>
                                                <td className="mono">{row.phone_e164}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <p className="mdash-scope">
                                    Échantillon : {preview.sample.length} sur {preview.total_valid} contacts valides.
                                </p>
                            </div>
                        )}

                        {preview.skipped.length > 0 && (
                            <div className="mdash-flash bad" style={{ marginTop: 12 }}>
                                <b>Lignes ignorées :</b>
                                <ul style={{ margin: '4px 0 0', paddingLeft: 18 }}>
                                    {preview.skipped.slice(0, 10).map((reason, index) => (
                                        <li key={index}>{reason}</li>
                                    ))}
                                    {preview.skipped.length > 10 && <li>… et {preview.skipped.length - 10} autre(s).</li>}
                                </ul>
                            </div>
                        )}

                        <form onSubmit={confirmImport} className="mpage-form-actions" style={{ marginTop: 16 }}>
                            <button type="button" className="mdash-btn ghost" onClick={cancelPreview}>
                                {preview.total_valid === 0 ? 'Choisir un autre fichier' : 'Annuler'}
                            </button>
                            {preview.total_valid > 0 && (
                                <button type="submit" className="mdash-btn brand" disabled={processing}>
                                    {processing ? 'Import en cours…' : `Confirmer l'import (${preview.total_valid})`}
                                </button>
                            )}
                        </form>
                        {preview.total_valid === 0 && <p className="mdash-scope">Aucune ligne valide à importer dans ce fichier.</p>}
                    </Panel>
                </>
            )}
        </Page>
    );
}

ImportsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ImportsIndex;
