import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

import { formatNumber } from '../../components/dashboard/Charts';

export interface IdentityData {
    type: 'person' | 'business';
    company_name: string | null;
    contact_name: string | null;
    external_code: string | null;
    phones: { phone: string; primary: boolean; shared: boolean; from: string | null; to: string | null }[];
    consents: { channel: string; purpose: string; status: string; store: string | null; date: string | null; source: string | null }[];
    other_phone_contacts: { id: number; phone: string; store: string; name: string }[];
}

interface Candidate {
    id: number;
    name: string;
    phone: string;
    purchases_count: number;
    total_spent: number;
}

const consentLabel: Record<string, string> = { opt_in: 'Accord', opt_out: 'Refus', unknown: 'Inconnu' };

function day(value: string | null): string {
    return value ? new Date(`${value.slice(0, 10)}T12:00:00`).toLocaleDateString('fr-FR', { day: '2-digit', month: 'short', year: 'numeric' }) : '';
}

/**
 * Fiche client › Identité : particulier ou entreprise, code client de la
 * caisse, numéros (principal, anciens, partagé), consentements par point de
 * vente, et fusion / séparation de fiches (journalisées).
 */
export default function IdentityPanel({ customerId, identity, canSteward }: { customerId: number; identity: IdentityData; canSteward: boolean }) {
    const [query, setQuery] = useState('');
    const [candidates, setCandidates] = useState<Candidate[]>([]);

    useEffect(() => {
        if (!canSteward || query.trim().length < 2) {
            setCandidates([]);
            return;
        }
        const timer = setTimeout(() => {
            fetch(`/customers/lookup?exclude=${customerId}&q=${encodeURIComponent(query.trim())}`, { headers: { Accept: 'application/json' } })
                .then((r) => (r.ok ? r.json() : []))
                .then(setCandidates)
                .catch(() => setCandidates([]));
        }, 300);
        return () => clearTimeout(timer);
    }, [query, customerId, canSteward]);

    function merge(c: Candidate) {
        if (confirm(`Fusionner « ${c.name} » (${c.phone}) dans cette fiche ? Ses ventes, contacts, points de fidélité et rendez-vous seront regroupés ici. L’opération est journalisée et peut être défaite (séparation).`)) {
            router.post(`/customers/${customerId}/merge`, { source_id: c.id }, { preserveScroll: true });
        }
    }

    function split(phone: string) {
        const ids = identity.other_phone_contacts.filter((c) => c.phone === phone).map((c) => c.id);
        if (confirm(`Séparer les contacts du numéro ${phone} dans une nouvelle fiche, avec leurs ventes ?`)) {
            router.post(`/customers/${customerId}/split`, { contact_ids: ids }, { preserveScroll: true });
        }
    }

    const otherPhones = [...new Set(identity.other_phone_contacts.map((c) => c.phone))];

    return (
        <article className="mdash-panel c6">
            <div className="mdash-ph">
                <div>
                    <h2>Identité</h2>
                    <span className="mdash-sub">{identity.type === 'business' ? 'Client entreprise' : 'Particulier'}</span>
                </div>
            </div>
            <dl className="mpage-dl" style={{ padding: '0 18px' }}>
                {identity.type === 'business' && (
                    <div className="mpage-dl-row">
                        <dt>Raison sociale</dt>
                        <dd>{identity.company_name ?? '—'}</dd>
                    </div>
                )}
                {identity.contact_name && (
                    <div className="mpage-dl-row">
                        <dt>Contact</dt>
                        <dd>{identity.contact_name}</dd>
                    </div>
                )}
                {identity.external_code && (
                    <div className="mpage-dl-row">
                        <dt>Code client (caisse)</dt>
                        <dd className="mono">{identity.external_code}</dd>
                    </div>
                )}
                <div className="mpage-dl-row">
                    <dt>Numéros</dt>
                    <dd>
                        {identity.phones.map((p) => (
                            <div key={p.phone} className="mono">
                                {p.phone} {p.primary ? <span className="mdash-pill ok">principal</span> : <span className="mdash-pill idle">ancien{p.to ? ` (jusqu’au ${day(p.to)})` : ''}</span>}
                                {p.shared && <span className="mdash-pill warn"> partagé</span>}
                            </div>
                        ))}
                    </dd>
                </div>
                <div className="mpage-dl-row">
                    <dt>Consentements</dt>
                    <dd>
                        {identity.consents.length === 0 ? (
                            <span className="mpage-muted">Aucun consentement recueilli</span>
                        ) : (
                            identity.consents.map((c, i) => (
                                <div key={i}>
                                    {c.channel} {c.purpose.toLowerCase()} · {c.store ?? 'tous points de vente'} :{' '}
                                    <b>{consentLabel[c.status] ?? c.status}</b>
                                    {c.date ? ` le ${day(c.date)}` : ''}
                                    {c.source ? <span className="mpage-muted"> ({c.source})</span> : null}
                                </div>
                            ))
                        )}
                    </dd>
                </div>
            </dl>

            {canSteward && (
                <div style={{ padding: '6px 18px 18px', display: 'grid', gap: 10 }}>
                    {otherPhones.map((phone) => (
                        <div key={phone} className="mpage-note" style={{ margin: 0 }}>
                            Contacts au numéro {phone} (fiche fusionnée).{' '}
                            <button type="button" className="mdash-btn ghost sm" onClick={() => split(phone)}>
                                Séparer
                            </button>
                        </div>
                    ))}
                    <div className="mpage-field">
                        <label htmlFor="merge_q">Fusionner un doublon dans cette fiche</label>
                        <input id="merge_q" type="search" placeholder="Nom, numéro ou code client du doublon" value={query} onChange={(e) => setQuery(e.target.value)} />
                    </div>
                    {candidates.map((c) => (
                        <div key={c.id} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
                            <span>
                                <b>{c.name}</b> <span className="mono mpage-muted">{c.phone}</span>
                                <span className="mpage-muted">
                                    {' '}
                                    · {formatNumber(c.purchases_count)} achat(s) · {formatNumber(c.total_spent)} F
                                </span>
                            </span>
                            <button type="button" className="mdash-btn ghost sm" onClick={() => merge(c)}>
                                Fusionner ici
                            </button>
                        </div>
                    ))}
                </div>
            )}
        </article>
    );
}
