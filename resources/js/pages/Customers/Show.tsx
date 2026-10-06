import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { VIP_PILL, stageInfo } from '../../components/dashboard/stages';
import CustomerEditModal from './CustomerEditModal';
import '../Dashboard/dashboard.css';
import './customers.css';

interface CustomerData {
    id: number;
    name: string;
    phone_e164: string;
    previous_phones: string[];
    gender: 'H' | 'F' | null;
    birthdate: string | null;
    identity_confirmed: boolean;
    stage: string;
    stage_since: string | null;
    created_at: string;
}

interface ContactRow {
    id: number;
    editable: boolean;
    phone_e164: string;
    name: string;
    store_name: string;
    brand_name: string | null;
    consent_status: 'unknown' | 'opt_in' | 'opt_out';
    is_vip: boolean;
    vip_since: string | null;
    welcomed: boolean;
    groups: string[];
    attributes: Record<string, string>;
    created_at: string;
}

interface Rfm {
    recency_days: number | null;
    last_purchase_at: string | null;
    frequency: number;
    frequency_12m: number;
    monetary: number;
    average_basket: number;
    scores: { r: number; f: number; m: number } | null;
    buyers: number;
    segment: { key: string; label: string; action: string };
}

interface PurchaseRow {
    id: number;
    date: string;
    store_name: string;
    product_category: string | null;
    quantity: number | null;
    amount: number;
}

interface MessageRow {
    kind: 'campaign' | 'welcome';
    label: string;
    store_name: string;
    status: 'sent' | 'failed';
    delivery: 'sent' | 'delivered' | 'read' | 'failed';
    at: string;
}

interface PageProps {
    customer: CustomerData;
    scoped: boolean;
    rfm: Rfm;
    firstPurchaseAt: string | null;
    contacts: ContactRow[];
    purchases: PurchaseRow[];
    messages: MessageRow[];
    messagesCount: number;
    stageHistory: { from: string | null; to: string; at: string }[];
    canEdit: boolean;
    welcomeTemplates: string[];
    groups: { id: number; name: string }[];
    memberOf: number[];
    flash?: { success: string | null; error: string | null };
    [key: string]: unknown;
}

const CONSENT: Record<ContactRow['consent_status'], [string, string]> = {
    unknown: ['Consentement inconnu', 'idle'],
    opt_in: ['Opt-in', 'ok'],
    opt_out: ['Opt-out', 'bad'],
};

// Couleur du badge de segment RFM (classes .mdash-pill).
const SEGMENT_PILL: Record<string, string> = {
    champions: 'ok',
    loyal: 'ok',
    new: 'info',
    potential: 'info',
    at_risk: 'warn',
    cant_lose: 'bad',
    hibernating: 'idle',
    lost: 'idle',
    none: 'idle',
};

function formatDate(day: string | null): string {
    if (!day) return '—';
    return new Date(day.length === 10 ? `${day}T12:00:00` : day).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' });
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .map((w) => w[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
}

function plural(count: number, word: string): string {
    return `${formatNumber(count)} ${word}${count > 1 ? 's' : ''}`;
}

/** Consentement résumé pour l'en-tête : opt-in dès qu'une boutique l'a obtenu. */
function overallConsent(contacts: ContactRow[]): ContactRow['consent_status'] {
    if (contacts.some((c) => c.consent_status === 'opt_in')) return 'opt_in';
    if (contacts.length > 0 && contacts.every((c) => c.consent_status === 'opt_out')) return 'opt_out';
    return 'unknown';
}

function ScoreGauge({ letter, score }: { letter: string; score: number }) {
    return (
        <span className="mcust-gauge" aria-label={`Score ${letter} : ${score} sur 5`}>
            <b>
                {letter}
                {score}
            </b>
            {[1, 2, 3, 4, 5].map((step) => (
                <i key={step} className={step <= score ? `on s${score}` : ''} />
            ))}
        </span>
    );
}

function RfmMetric({ title, value, detail, letter, score }: { title: string; value: React.ReactNode; detail: string; letter: string; score: number | null }) {
    return (
        <div className="mcust-rfm-metric">
            <span className="k-label">{title}</span>
            <span className="k-val num">{value}</span>
            <span className="k-foot">{detail}</span>
            {score !== null && <ScoreGauge letter={letter} score={score} />}
        </div>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="mcust-field">
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

function CustomerShow() {
    const { customer, scoped, rfm, firstPurchaseAt, contacts, purchases, messages, messagesCount, stageHistory, canEdit, welcomeTemplates, groups: allGroups, memberOf, flash } =
        usePage<PageProps>().props;
    const [editing, setEditing] = useState(false);
    const [welcomeTemplate, setWelcomeTemplate] = useState(welcomeTemplates[0] ?? '');
    const [busy, setBusy] = useState<number | null>(null);
    const stage = stageInfo(customer.stage);
    const consent = overallConsent(contacts);
    const { scores } = rfm;

    const attributes = contacts.flatMap((c) => Object.entries(c.attributes ?? {}).map(([key, value]) => ({ key, value, store: c.store_name })));
    const memberGroups = allGroups.filter((g) => memberOf.includes(g.id));
    const otherGroups = allGroups.filter((g) => !memberOf.includes(g.id));

    // Groupes de la personne (tous ses contacts du périmètre), cf. GroupController::addCustomers.
    function addToGroup(groupId: string) {
        if (groupId) router.post(`/groups/${groupId}/customers`, { customer_ids: [customer.id] }, { preserveScroll: true });
    }

    function removeFromGroup(group: { id: number; name: string }) {
        if (confirm(`Retirer ${customer.name} du groupe « ${group.name} » ?`)) {
            router.delete(`/groups/${group.id}/customers`, { data: { customer_ids: [customer.id] }, preserveScroll: true });
        }
    }
    const pendingWelcome = contacts.filter((c) => c.consent_status === 'unknown' && !c.welcomed);

    // « Tout voir » : historiques filtrés sur le numéro du client, sur toute la période.
    const purchasesUrl = `/purchases?${new URLSearchParams({
        q: customer.phone_e164,
        from: firstPurchaseAt ?? new Date().toISOString().slice(0, 10),
        to: new Date().toISOString().slice(0, 10),
    })}`;
    const messagesUrl = `${contacts[0] ? `/chat/${contacts[0].id}` : '/chat'}?${new URLSearchParams({ q: customer.phone_e164, period: 'all' })}`;

    function post(contact: ContactRow, url: string, data: Record<string, string> = {}) {
        setBusy(contact.id);
        router.post(url, data, { preserveScroll: true, onFinish: () => setBusy(null) });
    }

    function destroyContact(contact: ContactRow) {
        const last = contacts.length === 1 ? ' C’est sa seule boutique : la fiche client sera supprimée aussi.' : '';
        if (confirm(`Retirer ${customer.name} de ${contact.store_name} ? Ses achats et messages dans cette boutique seront supprimés.${last}`)) {
            router.delete(`/contacts/${contact.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="mdash mcust">
            <Head title={customer.name} />

            <Link href="/customers" className="mcust-back">
                ← Fiches clients
            </Link>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            <section className="mdash-panel" style={{ marginTop: 12 }}>
                <div className="mcust-head">
                    <span className="mcust-avatar">{initials(customer.name)}</span>
                    <div className="grow">
                        <h1>{customer.name}</h1>
                        <div className="meta">
                            <span className="mono">{customer.phone_e164}</span>
                            <span>{contacts.map((c) => (c.brand_name && c.brand_name !== c.store_name ? `${c.brand_name} · ${c.store_name}` : c.store_name)).join(', ')}</span>
                        </div>
                        <div className="meta">
                            <span className={`mdash-pill ${CONSENT[consent][1]}`}>{CONSENT[consent][0]}</span>
                            <span className={`mdash-pill ${SEGMENT_PILL[rfm.segment.key] ?? 'idle'}`} title="Segment RFM">
                                {rfm.segment.label}
                            </span>
                            <span className={`mdash-pill ${stage.pill}`} title={stage.hint}>
                                {stage.label}
                            </span>
                            {contacts
                                .filter((c) => c.is_vip)
                                .map((c) => (
                                    <span key={c.id} className={`mdash-pill ${VIP_PILL.pill}`} title={c.vip_since ? `VIP depuis le ${formatDate(c.vip_since)}` : VIP_PILL.hint}>
                                        ★ VIP {c.store_name}
                                    </span>
                                ))}
                        </div>
                    </div>
                    <div className="mcust-actions">
                        {contacts.length > 0 && (
                            <Link href={messagesUrl} className="mdash-btn ghost" title="Ouvrir la conversation WhatsApp avec ce client">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                    <path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z" />
                                </svg>
                                Conversation
                            </Link>
                        )}
                        {canEdit && (
                            <button type="button" className="mdash-btn brand" onClick={() => setEditing(true)}>
                                Modifier
                            </button>
                        )}
                    </div>
                </div>
                {scoped && <div className="mcust-note">Chiffres, achats et messages limités aux boutiques de votre périmètre. L'étape est celle du Groupe.</div>}
            </section>

            <div className="mdash-grid">
                <section className="mdash-panel c12" aria-labelledby="rfm-title">
                    <div className="mdash-ph">
                        <h2 id="rfm-title">Profil RFM</h2>
                        {scores && (
                            <span className="mdash-sub">
                                <b className="mcust-rfm-code">
                                    R{scores.r} · F{scores.f} · M{scores.m}
                                </b>{' '}
                                · scores de 1 à 5 comparés aux {plural(rfm.buyers, 'client')} acheteur{rfm.buyers > 1 ? 's' : ''}{' '}
                                {scoped ? 'de votre périmètre' : 'du Groupe'}
                            </span>
                        )}
                    </div>
                    <p className="mcust-rfm-action">
                        <span className={`mdash-pill ${SEGMENT_PILL[rfm.segment.key] ?? 'idle'}`}>{rfm.segment.label}</span>
                        {rfm.segment.action}
                    </p>
                    <div className="mcust-rfm">
                        <RfmMetric
                            title="Récence"
                            value={rfm.recency_days === null ? '—' : `${formatNumber(rfm.recency_days)} j`}
                            detail={rfm.last_purchase_at ? `dernier achat le ${formatDate(rfm.last_purchase_at)}` : 'aucun achat'}
                            letter="R"
                            score={scores?.r ?? null}
                        />
                        <RfmMetric
                            title="Fréquence"
                            value={plural(rfm.frequency, 'achat')}
                            detail={`${formatNumber(rfm.frequency_12m)} sur les 12 derniers mois`}
                            letter="F"
                            score={scores?.f ?? null}
                        />
                        <RfmMetric
                            title="Montant"
                            value={
                                <>
                                    {formatNumber(rfm.monetary)}
                                    <small>FCFA</small>
                                </>
                            }
                            detail={rfm.frequency > 0 ? `panier moyen ${formatNumber(rfm.average_basket)} FCFA` : 'panier moyen —'}
                            letter="M"
                            score={scores?.m ?? null}
                        />
                    </div>
                </section>

                <article className="mdash-panel c6">
                    <div className="mdash-ph">
                        <h2>Coordonnées et profil</h2>
                    </div>
                    <dl className="mcust-fields">
                        <Field label="Téléphone">
                            <span className="mono">{customer.phone_e164}</span>
                            {customer.previous_phones.length > 0 && <span className="sub">anciens : {customer.previous_phones.join(', ')}</span>}
                        </Field>
                        {customer.gender && <Field label="Genre">{customer.gender === 'F' ? 'Femme' : 'Homme'}</Field>}
                        {customer.birthdate && <Field label="Naissance">{formatDate(customer.birthdate)}</Field>}
                        <Field label={firstPurchaseAt ? 'Client depuis' : 'Fiche créée le'}>{formatDate(firstPurchaseAt ?? customer.created_at)}</Field>
                        <Field label="Parcours">
                            {stage.label}
                            {customer.stage_since && <span className="sub">depuis le {formatDate(customer.stage_since)}</span>}
                            {stageHistory[0]?.from && <span className="sub">avant : {stageInfo(stageHistory[0].from).label}</span>}
                        </Field>
                        {allGroups.length > 0 && (
                            <Field label="Groupes">
                                <span className="mcust-chips">
                                    {memberGroups.map((g) => (
                                        <span key={g.id} className="mcust-chip">
                                            <Link href={`/customers?group=${g.id}`}>{g.name}</Link>
                                            <button type="button" className="mcust-chip-x" aria-label={`Retirer du groupe ${g.name}`} onClick={() => removeFromGroup(g)}>
                                                ×
                                            </button>
                                        </span>
                                    ))}
                                    {otherGroups.length > 0 && (
                                        <select className="mcust-chip-add" aria-label="Ajouter à un groupe" value="" onChange={(e) => addToGroup(e.target.value)}>
                                            <option value="">+ Ajouter à un groupe</option>
                                            {otherGroups.map((g) => (
                                                <option key={g.id} value={g.id}>
                                                    {g.name}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                </span>
                            </Field>
                        )}
                        {attributes.map((a) => (
                            <Field key={`${a.store}-${a.key}`} label={a.key}>
                                {a.value}
                                {contacts.length > 1 && <span className="sub">{a.store}</span>}
                            </Field>
                        ))}
                        {customer.identity_confirmed && <Field label="Identité">✓ confirmée à la main</Field>}
                    </dl>
                </article>

                <article className="mdash-panel c6">
                    <div className="mdash-ph">
                        <h2>Consentement WhatsApp</h2>
                        <span className="mdash-sub">propre au numéro de chaque boutique</span>
                    </div>
                    <ul className="mcust-stores">
                        {contacts.map((c) => (
                            <li key={c.id}>
                                <div className="grow">
                                    <b>{c.store_name}</b>
                                    <span className="sub">
                                        ajouté le {formatDate(c.created_at)}
                                        {c.welcomed ? ' · bienvenue envoyée' : ''}
                                        {c.name !== customer.name ? ` · nommé « ${c.name} »` : ''}
                                    </span>
                                </div>
                                <span className={`mdash-pill ${CONSENT[c.consent_status][1]}`}>{CONSENT[c.consent_status][0]}</span>
                                <span className="mcust-row-links">
                                    <button type="button" disabled={busy === c.id} onClick={() => post(c, `/contacts/${c.id}/send-test`)}>
                                        Message test
                                    </button>
                                    <button type="button" className="danger" onClick={() => destroyContact(c)}>
                                        Retirer
                                    </button>
                                </span>
                            </li>
                        ))}
                    </ul>
                    {pendingWelcome.length > 0 && (
                        <div className="mcust-welcome">
                            {welcomeTemplates.length === 0 ? (
                                <span className="mdash-src">
                                    Bienvenue non envoyée. Ajoutez un modèle « utilitaire » actif dans <Link href="/templates">Modèles de messages</Link> pour l'envoyer.
                                </span>
                            ) : (
                                <>
                                    {welcomeTemplates.length > 1 && (
                                        <select aria-label="Modèle de bienvenue" value={welcomeTemplate} onChange={(e) => setWelcomeTemplate(e.target.value)}>
                                            {welcomeTemplates.map((t) => (
                                                <option key={t} value={t}>
                                                    {t}
                                                </option>
                                            ))}
                                        </select>
                                    )}
                                    {pendingWelcome.map((c) => (
                                        <button
                                            key={c.id}
                                            type="button"
                                            className="mdash-btn primary sm"
                                            disabled={busy === c.id || !welcomeTemplate}
                                            onClick={() => post(c, `/contacts/${c.id}/send-welcome`, { template_name: welcomeTemplate })}
                                        >
                                            Envoyer la bienvenue{contacts.length > 1 ? ` (${c.store_name})` : ''}
                                        </button>
                                    ))}
                                </>
                            )}
                        </div>
                    )}
                </article>

                <article className="mdash-panel c6">
                    <div className="mdash-ph">
                        <h2>Derniers achats</h2>
                        {rfm.frequency > 0 && (
                            <Link href={purchasesUrl} className="mdash-link">
                                Tout voir ({formatNumber(rfm.frequency)})
                            </Link>
                        )}
                    </div>
                    {purchases.length === 0 ? (
                        <div className="mdash-empty">Aucun achat enregistré.</div>
                    ) : (
                        <ul className="mcust-timeline">
                            {purchases.map((p) => (
                                <li key={p.id}>
                                    <span className="dot" />
                                    <span>
                                        {p.product_category ?? 'Achat'}
                                        {p.quantity && p.quantity > 1 ? ` × ${p.quantity}` : ''}
                                        <span className="sub">
                                            {p.store_name} · {formatDate(p.date)}
                                        </span>
                                    </span>
                                    <span className="mcust-amount">{formatNumber(p.amount)} F</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </article>

                <article className="mdash-panel c6">
                    <div className="mdash-ph">
                        <h2>Derniers messages WhatsApp</h2>
                        {messagesCount > 0 && (
                            <Link href={messagesUrl} className="mdash-link">
                                Tout voir ({formatNumber(messagesCount)})
                            </Link>
                        )}
                    </div>
                    {messages.length === 0 ? (
                        <div className="mdash-empty">Aucun message envoyé.</div>
                    ) : (
                        <ul className="mcust-timeline">
                            {messages.map((m, i) => (
                                <li key={i}>
                                    <span className={`dot ${m.status === 'failed' ? 'bad' : ''}`} />
                                    <span>
                                        {m.label}
                                        <span className="sub">
                                            {m.store_name} · {m.status === 'failed' ? 'échec' : m.delivery === 'read' ? 'lu' : m.delivery === 'delivered' ? 'remis' : 'envoyé'}
                                        </span>
                                    </span>
                                    <span className="when">{formatDate(m.at)}</span>
                                </li>
                            ))}
                        </ul>
                    )}
                </article>
            </div>

            {canEdit && <CustomerEditModal customer={customer} contacts={contacts} isOpen={editing} onClose={() => setEditing(false)} />}
        </div>
    );
}

CustomerShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default CustomerShow;
