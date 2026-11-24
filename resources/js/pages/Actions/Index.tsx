import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, SelectFilter } from '../../components/page/Page';
import { stageInfo } from '../../components/dashboard/stages';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
import SelectionCampaignModal, { CampaignTemplate } from '../Customers/SelectionCampaignModal';
import '../Customers/customers.css';
import './actions.css';

interface ListInfo {
    key: string;
    label: string;
    description: string;
    count: number;
}

interface Row {
    key: string;
    customer_id: number;
    appointment_id: number | null;
    /** Événement précis relancé (tranche, séance…), pour ne le proposer qu'une fois. */
    reference?: string | null;
    store_id: number;
    name: string;
    /** Personne suivie : responsable à qui le message est envoyé. */
    recipient_name?: string | null;
    phone_e164: string;
    store_name: string;
    stage: string;
    score: number | null;
    churn_risk: 'medium' | 'high' | null;
    consent: 'unknown' | 'opt_in' | 'opt_out';
    reason: string;
    message: string;
    whatsapp_url: string;
}

interface PageProps {
    lists: ListInfo[];
    active: string;
    rows: Row[];
    limit: number;
    store: number | null;
    stores: { id: number; name: string }[];
    brand: number | null;
    brands: { id: number; name: string }[];
    group: number | null;
    groups: { id: number; name: string }[];
    messages: Record<string, string>;
    defaultMessages: Record<string, string>;
    canEditMessages: boolean;
    apiEnabled: boolean;
    templates: CampaignTemplate[];
    [key: string]: unknown;
}

const VARIABLES = '{prenom}, {nom}, {boutique}, {date_rdv}, {heure_rdv}, {prestation}, {autre_marque}';

function MessagesEditor({ lists, messages, defaults, onClose }: { lists: ListInfo[]; messages: Record<string, string>; defaults: Record<string, string>; onClose: () => void }) {
    const { data, setData, put, processing } = useForm({ messages: { ...messages } });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/actions/messages', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Panel title="Messages préremplis" sub={`Variables disponibles : ${VARIABLES}`}>
            <form onSubmit={submit} className="mact-editor">
                {lists.map((l) => (
                    <label key={l.key}>
                        <span>{l.label}</span>
                        <textarea rows={3} value={data.messages[l.key] ?? ''} onChange={(e) => setData('messages', { ...data.messages, [l.key]: e.target.value })} />
                        {data.messages[l.key] !== defaults[l.key] && (
                            <button type="button" className="mact-reset" onClick={() => setData('messages', { ...data.messages, [l.key]: defaults[l.key] })}>
                                Revenir au message par défaut
                            </button>
                        )}
                    </label>
                ))}
                <div className="mact-editor-actions">
                    <button type="button" className="mdash-btn ghost" onClick={onClose}>
                        Annuler
                    </button>
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        {processing ? 'Enregistrement…' : 'Enregistrer les messages'}
                    </button>
                </div>
            </form>
        </Panel>
    );
}

function ActionsIndex() {
    const { lists, active, rows, limit, store, stores, brand, brands, group, groups, messages, defaultMessages, canEditMessages, apiEnabled, templates } = usePage<PageProps>().props;
    // Sélection multiple (même principe que la page Clients) : clés des lignes cochées.
    const [selected, setSelected] = useState<Set<string>>(new Set());
    // Envoi WhatsApp à la suite : une discussion ouverte par clic (sans l'API, WhatsApp ne permet pas l'envoi groupé).
    const [queue, setQueue] = useState<string[]>([]);
    const [queueSize, setQueueSize] = useState(0);
    const [isCampaignOpen, setIsCampaignOpen] = useState(false);
    const [editing, setEditing] = useState(false);
    const [done, setDone] = useState<Set<string>>(new Set());
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [open, setOpen] = useState<string | null>(null);
    const current = lists.find((l) => l.key === active) ?? lists[0];

    const canExport = usePage<{ auth?: { user?: { can_export?: boolean } | null } }>().props.auth?.user?.can_export ?? false;
    const listParams = Object.fromEntries(
        Object.entries({ list: active, store, brand, group: active === 'group' ? group : null }).filter(([, v]) => v !== null && v !== undefined),
    ) as Record<string, string | number>;
    const listQuery = new URLSearchParams(Object.entries(listParams).map(([k, v]) => [k, String(v)])).toString();

    function go(changes: Record<string, string | number | null>) {
        const params: Record<string, string | number> = {};
        Object.entries({ list: active, store, brand, group: active === 'group' ? group : null, ...changes }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params[k] = v;
        });
        router.get('/actions', params, { preserveScroll: true, preserveState: false });
    }

    /** Enregistre la relance ; la ligne reste visible (barrée) jusqu'au prochain chargement. */
    function record(row: Row, channel: 'whatsapp_manual' | 'call', message?: string) {
        setDone((prev) => new Set(prev).add(row.key));
        router.post(
            '/actions/done',
            { action: active, customer_id: row.customer_id, appointment_id: row.appointment_id, reference: row.reference ?? null, store_id: row.store_id, channel, message: message ?? null },
            { preserveScroll: true, preserveState: true, only: ['flash', 'lists'] },
        );
    }

    function whatsappUrl(row: Row): string {
        const text = drafts[row.key];
        return text === undefined ? row.whatsapp_url : `https://wa.me/${row.phone_e164.replace(/\D+/g, '')}?text=${encodeURIComponent(text)}`;
    }

    const selectable = rows.filter((r) => !done.has(r.key)).map((r) => r.key);
    const allSelected = selectable.length > 0 && selectable.every((k) => selected.has(k));
    const someSelected = !allSelected && selectable.some((k) => selected.has(k));
    const selectedRows = rows.filter((r) => selected.has(r.key) && !done.has(r.key));

    function toggleOne(key: string) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    }

    function toggleAll() {
        setSelected(allSelected ? new Set() : new Set(selectable));
    }

    function clearSelection() {
        setSelected(new Set());
        setQueue([]);
    }

    /** « Marquer comme fait » : toutes les lignes cochées sont tracées d'un coup. */
    function markSelectedDone() {
        const keys = selectedRows.map((r) => r.key);
        if (keys.length === 0) return;
        setDone((prev) => new Set([...prev, ...keys]));
        router.post('/actions/done-many', { ...listParams, keys, channel: 'other' }, { preserveScroll: true, preserveState: true, only: ['flash', 'lists'], onSuccess: clearSelection });
    }

    function groupSelected() {
        const name = window.prompt(`Ranger les ${selectedRows.length} client(s) sélectionné(s) dans le groupe :`, current.label);
        if (name && name.trim()) router.post('/actions/group', { ...listParams, keys: selectedRows.map((r) => r.key), name: name.trim() }, { preserveScroll: true, onSuccess: clearSelection });
    }

    function startQueue() {
        const keys = selectedRows.map((r) => r.key);
        setQueue(keys);
        setQueueSize(keys.length);
    }

    const queueRow = queue.length > 0 ? rows.find((r) => r.key === queue[0]) : undefined;

    const total = lists.reduce((n, l) => n + l.count, 0);

    return (
        <Page
            title="Actions du jour"
            subtitle={`${total} client${total > 1 ? 's' : ''} à relancer`}
            actions={
                canEditMessages && (
                    <button type="button" className="mdash-btn ghost" onClick={() => setEditing((v) => !v)}>
                        Modifier les messages
                    </button>
                )
            }
            filters={
                stores.length > 1 || brands.length > 1 || active === 'group' ? (
                    <>
                        {brands.length > 1 && (
                            <SelectFilter label="Marque" value={brand} allLabel="Toutes les marques" options={brands.map((b) => ({ value: b.id, label: b.name }))} onChange={(v) => go({ brand: v, store: null })} />
                        )}
                        {active === 'group' && groups.length > 0 && (
                            <SelectFilter label="Groupe" value={group} allLabel="Choisir un groupe" options={groups.map((g) => ({ value: g.id, label: g.name }))} onChange={(v) => go({ group: v })} />
                        )}
                        {stores.length > 1 && (
                            <SelectFilter label="Établissement" value={store} allLabel="Tous les établissements" options={stores.map((s) => ({ value: s.id, label: s.name }))} onChange={(v) => go({ store: v })} />
                        )}
                    </>
                ) : undefined
            }
        >
            {editing && <MessagesEditor lists={lists} messages={messages} defaults={defaultMessages} onClose={() => setEditing(false)} />}

            <nav className="mact-lists" aria-label="Listes d'actions">
                {lists.map((l) => (
                    <button key={l.key} type="button" aria-pressed={l.key === active} className={l.count === 0 ? 'empty' : ''} onClick={() => go({ list: l.key })}>
                        <b className="num">{l.count}</b>
                        <span>{l.label}</span>
                    </button>
                ))}
            </nav>

            <Panel
                title={current.label}
                sub={current.description}
                actions={
                    rows.length > 0 ? (
                        <>
                            {canExport && (
                                <a className="mdash-btn ghost sm" href={`/actions/export?${listQuery}&format=xlsx`}>
                                    Exporter la liste
                                </a>
                            )}
                            <button
                                type="button"
                                className="mdash-btn ghost sm"
                                onClick={() => {
                                    const name = window.prompt('Ranger ces clients dans le groupe :', current.label);
                                    if (name && name.trim()) router.post('/actions/group', { ...listParams, name: name.trim() }, { preserveScroll: true });
                                }}
                            >
                                Ranger dans un groupe
                            </button>
                        </>
                    ) : undefined
                }
            >
                {rows.length === 0 ? (
                    <Empty title="Rien à faire dans cette liste">Les clients relancés en sortent pendant quelques jours, puis reviennent s'ils n'ont pas réagi.</Empty>
                ) : (
                    <>
                    <label className="mact-selectall">
                        <input
                            type="checkbox"
                            checked={allSelected}
                            ref={(el) => {
                                if (el) el.indeterminate = someSelected;
                            }}
                            onChange={toggleAll}
                        />
                        Tout sélectionner
                    </label>
                    <ul className="mact-rows">
                        {rows.map((row) => {
                            const isDone = done.has(row.key);
                            const stage = stageInfo(row.stage);
                            return (
                                <li key={row.key} className={`${isDone ? 'done' : ''} ${selected.has(row.key) && !isDone ? 'selected' : ''}`}>
                                    <span className="mact-check">
                                        <input type="checkbox" aria-label={`Sélectionner ${row.name}`} disabled={isDone} checked={selected.has(row.key) && !isDone} onChange={() => toggleOne(row.key)} />
                                    </span>
                                    <div className="mact-who">
                                        <Link href={`/customers/${row.customer_id}`}>
                                            <b>{row.name}</b>
                                        </Link>
                                        <span className="mono">{row.phone_e164}</span>
                                        {row.recipient_name && <span className="mact-meta">écrire à {row.recipient_name}</span>}
                                        <span className="mact-tags">
                                            {row.churn_risk && <span className={`mdash-pill ${row.churn_risk === 'high' ? 'bad' : 'warn'}`}>{row.churn_risk === 'high' ? 'Risque élevé' : 'Risque moyen'}</span>}
                                            <span className="mact-meta">
                                                {[stage.label, row.score !== null ? `score ${row.score}` : null, stores.length > 1 ? row.store_name : null].filter(Boolean).join(' · ')}
                                            </span>
                                        </span>
                                    </div>
                                    <div className="mact-why">
                                        <span>{row.reason}</span>
                                        <button type="button" className="mact-preview" onClick={() => setOpen(open === row.key ? null : row.key)} aria-expanded={open === row.key}>
                                            {open === row.key ? 'Masquer le message' : 'Voir / modifier le message'}
                                        </button>
                                        {open === row.key && (
                                            <textarea rows={4} aria-label={`Message pour ${row.name}`} value={drafts[row.key] ?? row.message} onChange={(e) => setDrafts({ ...drafts, [row.key]: e.target.value })} />
                                        )}
                                    </div>
                                    <div className="mact-do">
                                        {isDone ? (
                                            <span className="mdash-pill ok">✓ Relance enregistrée</span>
                                        ) : (
                                            <>
                                                <a
                                                    href={whatsappUrl(row)}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="mdash-btn wa sm"
                                                    onClick={() => record(row, 'whatsapp_manual', drafts[row.key] ?? row.message)}
                                                >
                                                    <WhatsAppIcon />
                                                    Ouvrir WhatsApp
                                                </a>
                                                <button type="button" className="mdash-btn ghost sm" onClick={() => record(row, 'call')} title="Client joint autrement (appel, visite) : il sort de la liste">
                                                    Fait
                                                </button>
                                            </>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                    </>
                )}
                {rows.length >= limit && <p className="mpage-muted">Les {limit} premiers clients sont affichés : relancez-les, les suivants apparaîtront.</p>}
            </Panel>

            {queueRow && (
                <div className="mcust-selbar" role="region" aria-label="Envoi WhatsApp à la suite">
                    <span>
                        <b className="num">
                            {queueSize - queue.length + 1} / {queueSize}
                        </b>{' '}
                        · {queueRow.name}
                    </span>
                    <a
                        href={whatsappUrl(queueRow)}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mdash-btn wa sm"
                        onClick={() => {
                            record(queueRow, 'whatsapp_manual', drafts[queueRow.key] ?? queueRow.message);
                            const rest = queue.slice(1);
                            setQueue(rest);
                            if (rest.length === 0) setSelected(new Set());
                        }}
                    >
                        <WhatsAppIcon />
                        {queue.length > 1 ? 'Ouvrir WhatsApp, puis suivant' : 'Ouvrir WhatsApp (dernier)'}
                    </a>
                    <button type="button" className="mdash-btn ghost sm" onClick={() => setQueue(queue.slice(1))}>
                        Passer
                    </button>
                    <button type="button" className="mdash-btn ghost sm" onClick={() => setQueue([])}>
                        Arrêter
                    </button>
                </div>
            )}

            {!queueRow && selectedRows.length > 0 && (
                <div className="mcust-selbar" role="region" aria-label="Relances sélectionnées">
                    <span>
                        <b className="num">
                            {selectedRows.length} sélectionné{selectedRows.length > 1 ? 's' : ''}
                        </b>
                    </span>
                    <button type="button" className="mdash-btn ghost sm" onClick={clearSelection}>
                        Tout désélectionner
                    </button>
                    <button type="button" className="mdash-btn wa sm" onClick={startQueue}>
                        <WhatsAppIcon />
                        Envoyer sur WhatsApp
                    </button>
                    <button type="button" className="mdash-btn primary sm" onClick={markSelectedDone}>
                        Marquer comme fait
                    </button>
                    <button type="button" className="mdash-btn ghost sm" onClick={groupSelected}>
                        Ranger dans un groupe
                    </button>
                    {canExport && (
                        <a className="mdash-btn ghost sm" href={`/actions/export?${listQuery}&format=xlsx&keys=${encodeURIComponent(selectedRows.map((r) => r.key).join(','))}`}>
                            Exporter
                        </a>
                    )}
                    {apiEnabled && (
                        <button type="button" className="mdash-btn ghost sm" onClick={() => setIsCampaignOpen(true)}>
                            Campagne WhatsApp
                        </button>
                    )}
                </div>
            )}

            {apiEnabled && (
                <SelectionCampaignModal
                    isOpen={isCampaignOpen}
                    onClose={() => setIsCampaignOpen(false)}
                    customerIds={[...new Set(selectedRows.map((r) => r.customer_id))]}
                    filters={{}}
                    defaultName={current.label}
                    templates={templates}
                />
            )}

            <div className="mpage-note">
                « Ouvrir WhatsApp » ouvre la discussion avec le message prérempli : relisez, puis envoyez depuis le téléphone de l'établissement. La relance
                est notée sur la fiche. Les clients qui ont répondu « Stop » n'apparaissent jamais.
                {!apiEnabled && ' Les campagnes automatiques s’activeront dans Paramètres une fois votre compte Meta vérifié.'}
            </div>
        </Page>
    );
}

ActionsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ActionsIndex;
