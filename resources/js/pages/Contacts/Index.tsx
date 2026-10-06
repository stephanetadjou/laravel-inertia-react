import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { customerUrl } from '../../components/common/customerUrl';
import { Empty, Page, Pager, Paginated, Panel, PlusIcon, SearchFilter, SelectFilter, StatStrip, useQueryFilters } from '../../components/page/Page';
import NewContactModal from './NewContactModal';

interface ContactRow {
    id: number;
    customer_id: number | null;
    name: string;
    phone_e164: string;
    gender: string | null;
    birthdate: string | null;
    consent_status: 'opt_in' | 'opt_out' | 'unknown';
    store_name: string;
    group_names: string[];
}

interface Filters {
    store_id?: string;
    gender?: string;
    consent_status?: string;
    group_id?: string;
    q?: string;
}

interface PageProps {
    contacts: Paginated<ContactRow>;
    perPage: number;
    stores: { id: number; name: string }[];
    groups: { id: number; name: string }[];
    filters: Filters;
    counts: { total: number; opt_in: number; unknown: number; opt_out: number };
    [key: string]: unknown;
}

export const CONSENT: Record<ContactRow['consent_status'], [string, string]> = {
    opt_in: ['Opt-in', 'ok'],
    unknown: ['Inconnu', 'idle'],
    opt_out: ['Opt-out', 'bad'],
};

function ContactsIndex() {
    const { contacts, perPage, stores, groups, filters, counts } = usePage<PageProps>().props;
    const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());
    const [bulkGroupId, setBulkGroupId] = useState('');
    const [assigning, setAssigning] = useState(false);
    const [isNewContactOpen, setIsNewContactOpen] = useState(false);
    const apply = useQueryFilters('/contacts', { ...filters, per_page: perPage === 25 ? null : perPage });
    const filtered = Boolean(filters.store_id || filters.gender || filters.consent_status || filters.group_id || filters.q);
    const exportUrl = `/contacts/export?${new URLSearchParams(Object.entries(filters).filter(([, v]) => v) as [string, string][])}`;
    const allSelected = contacts.data.length > 0 && contacts.data.every((c) => selectedIds.has(c.id));

    function toggleSelected(id: number) {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }

    function assignToGroup() {
        if (!bulkGroupId || selectedIds.size === 0) return;
        setAssigning(true);
        router.post(
            '/contacts/assign-group',
            { group_id: bulkGroupId, contact_ids: Array.from(selectedIds) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedIds(new Set());
                    setBulkGroupId('');
                },
                onFinish: () => setAssigning(false),
            },
        );
    }

    return (
        <Page
            title="Contacts"
            subtitle="Un contact = un client dans une boutique (consentement propre à chaque numéro WhatsApp). La vue par personne est dans Fiches clients."
            actions={
                <>
                    <a href={exportUrl} className="mdash-btn ghost">
                        Exporter
                    </a>
                    <Link href="/imports" className="mdash-btn ghost">
                        Importer un fichier
                    </Link>
                    <button type="button" className="mdash-btn brand" onClick={() => setIsNewContactOpen(true)}>
                        <PlusIcon />
                        Nouveau contact
                    </button>
                </>
            }
            filters={
                <>
                    <SearchFilter value={filters.q ?? ''} placeholder="Nom ou numéro" onSearch={(q) => apply({ q })} />
                    {stores.length > 1 && (
                        <SelectFilter
                            label="Boutique"
                            value={filters.store_id}
                            allLabel="Toutes"
                            options={stores.map((s) => ({ value: s.id, label: s.name }))}
                            onChange={(store_id) => apply({ store_id })}
                        />
                    )}
                    <SelectFilter
                        label="Consentement"
                        value={filters.consent_status}
                        allLabel="Tous"
                        options={Object.entries(CONSENT).map(([value, [label]]) => ({ value, label }))}
                        onChange={(consent_status) => apply({ consent_status })}
                    />
                    <SelectFilter
                        label="Sexe"
                        value={filters.gender}
                        allLabel="Tous"
                        options={[
                            { value: 'F', label: 'Femme' },
                            { value: 'H', label: 'Homme' },
                        ]}
                        onChange={(gender) => apply({ gender })}
                    />
                    {groups.length > 0 && (
                        <SelectFilter
                            label="Groupe"
                            value={filters.group_id}
                            allLabel="Tous"
                            options={groups.map((g) => ({ value: g.id, label: g.name }))}
                            onChange={(group_id) => apply({ group_id })}
                        />
                    )}
                    {filtered && (
                        <button type="button" className="mdash-btn ghost sm" onClick={() => apply({ q: null, store_id: null, consent_status: null, gender: null, group_id: null })}>
                            Effacer les filtres
                        </button>
                    )}
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Contacts', value: counts.total },
                    { label: 'Opt-in', value: counts.opt_in, tone: 'ok', hint: 'reçoivent les campagnes' },
                    { label: 'Consentement inconnu', value: counts.unknown, hint: 'bienvenue possible' },
                    { label: 'Opt-out', value: counts.opt_out },
                ]}
            />

            <Panel>
                {selectedIds.size > 0 && (
                    <div className="mpage-selbar">
                        <b>
                            {selectedIds.size} contact{selectedIds.size > 1 ? 's' : ''} sélectionné{selectedIds.size > 1 ? 's' : ''}
                        </b>
                        {groups.length === 0 ? (
                            <span>
                                Aucun groupe : <Link href="/groups">créez-en un</Link> d'abord.
                            </span>
                        ) : (
                            <>
                                <select aria-label="Groupe" value={bulkGroupId} onChange={(e) => setBulkGroupId(e.target.value)}>
                                    <option value="">Choisir un groupe…</option>
                                    {groups.map((g) => (
                                        <option key={g.id} value={g.id}>
                                            {g.name}
                                        </option>
                                    ))}
                                </select>
                                <button type="button" className="mdash-btn brand sm" disabled={!bulkGroupId || assigning} onClick={assignToGroup}>
                                    {assigning ? 'Ajout…' : 'Ajouter au groupe'}
                                </button>
                            </>
                        )}
                        <button type="button" className="mdash-btn ghost sm" onClick={() => setSelectedIds(new Set())}>
                            Désélectionner
                        </button>
                    </div>
                )}

                {contacts.data.length === 0 ? (
                    <Empty title={filtered ? 'Aucun contact ne correspond aux filtres' : 'Aucun contact pour l’instant'}>
                        {filtered ? 'Modifiez ou effacez les filtres.' : 'Importez un fichier clients ou ajoutez un contact.'}
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th className="mpage-check">
                                        <input
                                            type="checkbox"
                                            aria-label="Tout sélectionner sur la page"
                                            checked={allSelected}
                                            onChange={() => setSelectedIds(allSelected ? new Set() : new Set(contacts.data.map((c) => c.id)))}
                                        />
                                    </th>
                                    <th>Contact</th>
                                    <th>Boutique</th>
                                    <th>Consentement</th>
                                    <th>Groupes</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {contacts.data.map((c) => (
                                    <tr key={c.id}>
                                        <td className="mpage-check">
                                            <input type="checkbox" aria-label={`Sélectionner ${c.name}`} checked={selectedIds.has(c.id)} onChange={() => toggleSelected(c.id)} />
                                        </td>
                                        <td className="cell-main">
                                            <b>
                                                <Link href={customerUrl(c)}>{c.name}</Link>
                                            </b>
                                            <span className="mono">
                                                {c.phone_e164}
                                                {c.gender ? ` · ${c.gender === 'F' ? 'Femme' : 'Homme'}` : ''}
                                            </span>
                                        </td>
                                        <td>{c.store_name}</td>
                                        <td>
                                            <span className={`mdash-pill ${CONSENT[c.consent_status]?.[1] ?? 'idle'}`}>{CONSENT[c.consent_status]?.[0] ?? c.consent_status}</span>
                                        </td>
                                        <td>
                                            {c.group_names.length === 0 ? (
                                                <span className="mpage-muted">—</span>
                                            ) : (
                                                <span className="mpage-chips">
                                                    {c.group_names.map((g) => (
                                                        <span key={g} className="mpage-chip">
                                                            {g}
                                                        </span>
                                                    ))}
                                                </span>
                                            )}
                                        </td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <Link href={customerUrl(c)}>Fiche client</Link>
                                                <Link href={`/chat/${c.id}?period=all`}>Conversation</Link>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
                <Pager page={contacts} unit="contacts" onPage={(page) => apply({ page })} perPage={perPage} onPerPage={(per_page) => apply({ per_page })} />
            </Panel>

            <NewContactModal isOpen={isNewContactOpen} onClose={() => setIsNewContactOpen(false)} stores={stores} />
        </Page>
    );
}

ContactsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ContactsIndex;
