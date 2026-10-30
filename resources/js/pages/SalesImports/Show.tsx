import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, formatDay, Page, Pager, Paginated, Panel, StatStrip } from '../../components/page/Page';
import { money } from '../../components/families/families';
import { BatchSummary } from './Index';
import { statusTone } from './status';

interface Preview {
    tickets: number;
    lines: number;
    returns: number;
    amount: number;
    anonymous: number;
    new_customers: number;
    unclassified: number;
    period: { from: string; to: string } | null;
    sample: { date: string; store: string; receipt: string | null; customer: string | null; anonymous: boolean; lines: number; amount: number }[];
}

interface ErrorRow {
    row_number: number;
    status: string;
    message: string | null;
    content: string;
}

interface PageProps {
    batch: BatchSummary & {
        headers: string[];
        mapping: Record<string, number>;
        default_category_id: number | null;
        default_category: string | null;
        error_message: string | null;
    };
    sample: string[][];
    preview: Preview | null;
    errors: Paginated<ErrorRow> & { data: ErrorRow[] };
    errorCodes: { code: string; label: string; total: number }[];
    fields: { key: string; label: string; help: string }[];
    categories: { id: number; name: string }[];
    mappings: { id: number; name: string; version: number }[];
    familyWord: string;
    [key: string]: unknown;
}

const OPEN = ['uploaded', 'mapping_required', 'validated'];

function MappingForm() {
    const { batch, sample, fields, categories, familyWord } = usePage<PageProps>().props;
    const formErrors = usePage<{ errors: Record<string, string> }>().props.errors;
    const [mapping, setMapping] = useState<Record<string, string>>(
        Object.fromEntries(Object.entries(batch.mapping ?? {}).map(([field, index]) => [field, String(index)])),
    );
    const [defaultCategory, setDefaultCategory] = useState(batch.default_category_id ? String(batch.default_category_id) : '');
    const [saveAs, setSaveAs] = useState('');
    const [busy, setBusy] = useState(false);

    function submit() {
        setBusy(true);
        router.post(
            `/sales-imports/${batch.id}/mapping`,
            {
                mapping: Object.fromEntries(Object.entries(mapping).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)])),
                default_category_id: defaultCategory ? Number(defaultCategory) : null,
                save_as: saveAs.trim() || null,
            },
            { preserveScroll: true, onFinish: () => setBusy(false) },
        );
    }

    const required = ['date', 'store', 'line_total', 'family'];

    return (
        <>
            <Panel title="1. Associer les colonnes" sub="Chaque champ de Menentia ← une colonne de votre fichier. Les colonnes non associées deviennent des attributs de la vente.">
                {formErrors?.mapping && <div className="mdash-flash bad">{formErrors.mapping}</div>}
                <div className="mpage-form-grid" style={{ padding: '4px 18px 18px' }}>
                    {fields.map((f) => (
                        <div key={f.key} className="mpage-field">
                            <label htmlFor={`map_${f.key}`}>
                                {f.label}
                                {required.includes(f.key) && (f.key !== 'store' || !batch.store) ? ' *' : ''}
                            </label>
                            <select id={`map_${f.key}`} value={mapping[f.key] ?? ''} onChange={(e) => setMapping((m) => ({ ...m, [f.key]: e.target.value }))}>
                                <option value="">— Aucune —</option>
                                {batch.headers.map((h, i) => (
                                    <option key={i} value={i}>
                                        {h || `Colonne ${i + 1}`}
                                    </option>
                                ))}
                            </select>
                            <small>{f.key === 'store' && batch.store ? `Fixé à l’import : ${batch.store}` : f.help}</small>
                        </div>
                    ))}
                    <div className="mpage-field">
                        <label htmlFor="default_category">
                            {familyWord === 'famille' ? 'Famille' : 'Catégorie'} par défaut
                        </label>
                        <select id="default_category" value={defaultCategory} onChange={(e) => setDefaultCategory(e.target.value)}>
                            <option value="">— Aucune —</option>
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                        <small>Pour les lignes sans {familyWord} (fichier de totaux d’un restaurant, par exemple).</small>
                    </div>
                    <div className="mpage-field">
                        <label htmlFor="save_as">Enregistrer ce modèle sous (facultatif)</label>
                        <input id="save_as" type="text" value={saveAs} placeholder="ex. Export caisse" onChange={(e) => setSaveAs(e.target.value)} />
                        <small>Il sera proposé automatiquement au prochain fichier qui a les mêmes colonnes.</small>
                    </div>
                </div>
                <div className="mpage-form-actions" style={{ padding: '0 18px 18px' }}>
                    <button type="button" className="mdash-btn brand" disabled={busy} onClick={submit}>
                        {busy ? 'Vérification…' : 'Vérifier le fichier'}
                    </button>
                </div>
            </Panel>

            {sample.length > 0 && (
                <Panel title="Aperçu du fichier" sub="Premières lignes, telles que lues">
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    {batch.headers.map((h, i) => (
                                        <th key={i}>{h || `Colonne ${i + 1}`}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {sample.map((row, i) => (
                                    <tr key={i}>
                                        {batch.headers.map((_, j) => (
                                            <td key={j}>{row[j] ?? ''}</td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
            )}
        </>
    );
}

function Errors() {
    const { batch, errors, errorCodes } = usePage<PageProps>().props;

    if (errors.total === 0) return null;

    return (
        <Panel
            title="Lignes rejetées et doublons"
            sub={errorCodes.map((c) => `${c.label} : ${formatNumber(c.total)}`).join(' · ')}
            actions={
                <>
                    <a className="mdash-btn ghost sm" href={`/sales-imports/${batch.id}/errors`}>
                        Rapport CSV
                    </a>
                    <a className="mdash-btn ghost sm" href={`/sales-imports/${batch.id}/errors?format=xlsx`}>
                        Rapport Excel
                    </a>
                </>
            }
        >
            <div className="mdash-tbl mpage-table">
                <table>
                    <thead>
                        <tr>
                            <th className="r">Ligne</th>
                            <th>Statut</th>
                            <th>Erreur</th>
                            <th>Contenu</th>
                        </tr>
                    </thead>
                    <tbody>
                        {errors.data.map((e) => (
                            <tr key={e.row_number}>
                                <td className="r num">{e.row_number}</td>
                                <td>
                                    <span className={`mdash-pill ${e.status === 'duplicate' ? 'idle' : 'bad'}`}>{e.status === 'duplicate' ? 'Doublon' : 'Rejetée'}</span>
                                </td>
                                <td className="wrap">{e.message}</td>
                                <td className="wrap mpage-muted">{e.content}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <Pager page={errors} onPage={(n) => router.get(`/sales-imports/${batch.id}`, { errors_page: n }, { preserveScroll: true, preserveState: true })} unit="lignes" />
        </Panel>
    );
}

function SalesImportShow() {
    const { batch, preview } = usePage<PageProps>().props;
    const isOpen = OPEN.includes(batch.status);
    const processed = ['completed', 'completed_with_errors'].includes(batch.status);

    function cancel() {
        const message = processed
            ? `Annuler cet import ? Ses ${formatNumber(batch.tickets_count)} ticket(s) seront supprimés (action journalisée) et le fichier pourra être déposé à nouveau.`
            : 'Abandonner cet import ?';
        if (confirm(message)) router.post(`/sales-imports/${batch.id}/cancel`, {}, { preserveScroll: true });
    }

    return (
        <Page
            title={batch.filename}
            subtitle={
                <>
                    <Link href="/sales-imports" className="mdash-link">
                        Imports de ventes
                    </Link>{' '}
                    › n° {batch.id} · déposé le {formatDay(batch.created_at, true)}
                    {batch.user ? ` par ${batch.user}` : ''}
                    {batch.store ? ` · ${batch.store}` : batch.brand ? ` · ${batch.brand}` : ''}
                </>
            }
            actions={
                <>
                    <span className={`mdash-pill ${statusTone(batch.status)}`}>{batch.status_label}</span>
                    {batch.status !== 'cancelled' && batch.status !== 'processing' && (
                        <button type="button" className="mdash-btn ghost" onClick={cancel}>
                            {processed ? 'Annuler l’import' : 'Abandonner'}
                        </button>
                    )}
                </>
            }
        >
            {batch.status === 'processing' && (
                <div className="mpage-note">
                    Import en cours de traitement. Rechargez la page dans quelques instants.{' '}
                    <button type="button" className="mdash-btn ghost sm" onClick={() => router.reload()}>
                        Actualiser
                    </button>
                </div>
            )}
            {batch.status === 'failed' && <div className="mdash-flash bad">Le traitement a échoué : {batch.error_message}</div>}

            {batch.status === 'validated' && preview && (
                <>
                    <StatStrip
                        items={[
                            { label: 'Lignes valides', value: batch.accepted_rows },
                            { label: 'Rejetées', value: batch.rejected_rows, tone: batch.rejected_rows > 0 ? 'bad' : undefined },
                            { label: 'Doublons', value: batch.duplicate_rows, tone: batch.duplicate_rows > 0 ? 'warn' : undefined },
                            { label: 'Tickets à créer', value: preview.tickets },
                            { label: 'Montant net', value: money(preview.amount) },
                            { label: 'Ventes anonymes', value: preview.anonymous, hint: 'sans numéro valide' },
                            { label: 'Nouveaux clients', value: preview.new_customers },
                            { label: 'Lignes à classer', value: preview.unclassified, tone: preview.unclassified > 0 ? 'warn' : undefined },
                        ]}
                    />
                    <Panel
                        title="2. Vérification"
                        sub={preview.period ? `Ventes du ${formatDay(preview.period.from)} au ${formatDay(preview.period.to)}${preview.returns ? ` · ${preview.returns} retour(s)` : ''}` : undefined}
                        actions={
                            <>
                                <button type="button" className="mdash-btn ghost" onClick={() => router.post(`/sales-imports/${batch.id}/mapping`, { mapping: batch.mapping, default_category_id: batch.default_category_id })}>
                                    Revérifier
                                </button>
                                <button type="button" className="mdash-btn brand" disabled={batch.accepted_rows === 0} onClick={() => router.post(`/sales-imports/${batch.id}/confirm`)}>
                                    Confirmer l’import de {formatNumber(preview.tickets)} ticket(s)
                                </button>
                            </>
                        }
                    >
                        {preview.sample.length === 0 ? (
                            <Empty title="Aucune ligne valide">Corrigez le fichier ou l’association des colonnes.</Empty>
                        ) : (
                            <div className="mdash-tbl mpage-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Point de vente</th>
                                            <th>Ticket</th>
                                            <th>Client</th>
                                            <th className="r">Lignes</th>
                                            <th className="r">Montant</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {preview.sample.map((t, i) => (
                                            <tr key={i}>
                                                <td>{formatDay(t.date)}</td>
                                                <td>{t.store}</td>
                                                <td>{t.receipt ?? <span className="mpage-muted">—</span>}</td>
                                                <td>{t.anonymous ? <span className="mdash-pill idle">Anonyme</span> : t.customer}</td>
                                                <td className="r num">{t.lines}</td>
                                                <td className="r num">{money(t.amount)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </Panel>
                </>
            )}

            {(processed || batch.status === 'cancelled') && (
                <StatStrip
                    items={[
                        { label: 'Lignes du fichier', value: batch.total_rows },
                        { label: 'Tickets créés', value: batch.tickets_count },
                        { label: 'Lignes de ticket', value: batch.lines_count },
                        { label: 'Rejetées', value: batch.rejected_rows, tone: batch.rejected_rows > 0 ? 'bad' : undefined },
                        { label: 'Doublons', value: batch.duplicate_rows, tone: batch.duplicate_rows > 0 ? 'warn' : undefined },
                        { label: 'Ventes anonymes', value: batch.anonymous_tickets },
                        { label: 'Nouveaux clients', value: batch.customers_created },
                        { label: 'Lignes à classer', value: batch.unclassified_lines, tone: batch.unclassified_lines > 0 ? 'warn' : undefined },
                    ]}
                />
            )}
            {processed && (
                <div className="mpage-note">
                    Import terminé le {formatDay(batch.completed_at, true)}.{' '}
                    <Link href={`/purchases?batch=${batch.id}`} className="mdash-link">
                        Voir les ventes de cet import
                    </Link>
                    {batch.unclassified_lines > 0 && (
                        <>
                            {' · '}
                            <Link href="/categories/a-classer" className="mdash-link">
                                Classer les libellés inconnus
                            </Link>
                        </>
                    )}
                </div>
            )}

            {isOpen && <MappingForm />}
            <Errors />
        </Page>
    );
}

SalesImportShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default SalesImportShow;
