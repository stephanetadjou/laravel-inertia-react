import { Link, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, SelectFilter } from '../../components/page/Page';
import { stageInfo } from '../../components/dashboard/stages';
import WhatsAppIcon from '../../components/common/WhatsAppIcon';
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
    store_id: number;
    name: string;
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
    messages: Record<string, string>;
    defaultMessages: Record<string, string>;
    canEditMessages: boolean;
    apiEnabled: boolean;
    [key: string]: unknown;
}

const VARIABLES = '{prenom}, {nom}, {boutique}, {date_rdv}, {heure_rdv}, {prestation}';

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
    const { lists, active, rows, limit, store, stores, messages, defaultMessages, canEditMessages, apiEnabled } = usePage<PageProps>().props;
    const [editing, setEditing] = useState(false);
    const [done, setDone] = useState<Set<string>>(new Set());
    const [drafts, setDrafts] = useState<Record<string, string>>({});
    const [open, setOpen] = useState<string | null>(null);
    const current = lists.find((l) => l.key === active) ?? lists[0];

    function go(changes: Record<string, string | number | null>) {
        const params: Record<string, string | number> = {};
        Object.entries({ list: active, store, ...changes }).forEach(([k, v]) => {
            if (v !== null && v !== undefined && v !== '') params[k] = v;
        });
        router.get('/actions', params, { preserveScroll: true, preserveState: false });
    }

    /** Enregistre la relance ; la ligne reste visible (barrée) jusqu'au prochain chargement. */
    function record(row: Row, channel: 'whatsapp_manual' | 'call', message?: string) {
        setDone((prev) => new Set(prev).add(row.key));
        router.post(
            '/actions/done',
            { action: active, customer_id: row.customer_id, appointment_id: row.appointment_id, store_id: row.store_id, channel, message: message ?? null },
            { preserveScroll: true, preserveState: true, only: ['flash', 'lists'] },
        );
    }

    function whatsappUrl(row: Row): string {
        const text = drafts[row.key];
        return text === undefined ? row.whatsapp_url : `https://wa.me/${row.phone_e164.replace(/\D+/g, '')}?text=${encodeURIComponent(text)}`;
    }

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
            filters={stores.length > 1 ? <SelectFilter label="Établissement" value={store} allLabel="Tous les établissements" options={stores.map((s) => ({ value: s.id, label: s.name }))} onChange={(v) => go({ store: v })} /> : undefined}
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

            <Panel title={current.label} sub={current.description}>
                {rows.length === 0 ? (
                    <Empty title="Rien à faire dans cette liste">Les clients relancés en sortent pendant quelques jours, puis reviennent s'ils n'ont pas réagi.</Empty>
                ) : (
                    <ul className="mact-rows">
                        {rows.map((row) => {
                            const isDone = done.has(row.key);
                            const stage = stageInfo(row.stage);
                            return (
                                <li key={row.key} className={isDone ? 'done' : ''}>
                                    <div className="mact-who">
                                        <Link href={`/customers/${row.customer_id}`}>
                                            <b>{row.name}</b>
                                        </Link>
                                        <span className="mono">{row.phone_e164}</span>
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
                )}
                {rows.length >= limit && <p className="mpage-muted">Les {limit} premiers clients sont affichés : relancez-les, les suivants apparaîtront.</p>}
            </Panel>

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
