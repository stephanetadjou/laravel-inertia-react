import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import StoreModal, { StoreRow } from './StoreModal';
import '../Dashboard/dashboard.css';
import './stores.css';

type Connection = StoreRow['connection'];

interface PageProps {
    stores: StoreRow[];
    brands: { id: number; name: string }[];
    canManage: boolean;
    sharedAccountConfigured: boolean;
    flash?: { success: string | null; error: string | null };
    [key: string]: unknown;
}

const CONNECTION: Record<Connection, { label: string; pill: string; detail: string }> = {
    own: { label: 'Numéro propre', pill: 'ok', detail: 'Identifiants propres à la boutique' },
    shared: { label: 'Compte partagé', pill: 'info', detail: "Numéro commun de l'organisation" },
    none: { label: 'Non connectée', pill: 'bad', detail: 'Aucun identifiant WhatsApp' },
};

const FILTERS: { key: Connection | 'all'; label: string }[] = [
    { key: 'all', label: 'Toutes' },
    { key: 'own', label: 'Numéro propre' },
    { key: 'shared', label: 'Compte partagé' },
    { key: 'none', label: 'Non connectées' },
];

/** « 1234567890123 » → « •••• 0123 » : l'identifiant complet reste dans la fenêtre de modification. */
function maskId(id: string): string {
    return id.length > 4 ? `•••• ${id.slice(-4)}` : id;
}

function StoresIndex() {
    const { stores, brands, canManage, sharedAccountConfigured, flash } = usePage<PageProps>().props;
    const [search, setSearch] = useState('');
    const [filter, setFilter] = useState<Connection | 'all'>('all');
    const [modal, setModal] = useState<{ open: boolean; store: StoreRow | null }>({ open: false, store: null });
    const [testingId, setTestingId] = useState<number | null>(null);

    const counts = useMemo(
        () => ({
            brands: new Set(stores.map((s) => s.brand_id)).size,
            own: stores.filter((s) => s.connection === 'own').length,
            shared: stores.filter((s) => s.connection === 'shared').length,
            none: stores.filter((s) => s.connection === 'none').length,
        }),
        [stores],
    );

    // Boutiques visibles, regroupées par marque.
    const groups = useMemo(() => {
        const q = search.trim().toLowerCase();
        const visible = stores.filter(
            (s) => (filter === 'all' || s.connection === filter) && (!q || s.name.toLowerCase().includes(q) || (s.brand_name ?? '').toLowerCase().includes(q)),
        );
        const byBrand = new Map<string, StoreRow[]>();
        visible.forEach((s) => {
            const key = s.brand_name ?? 'Sans marque';
            byBrand.set(key, [...(byBrand.get(key) ?? []), s]);
        });
        return [...byBrand.entries()].sort(([a], [b]) => a.localeCompare(b, 'fr'));
    }, [stores, search, filter]);

    function testConnection(store: StoreRow) {
        setTestingId(store.id);
        router.post(`/stores/${store.id}/test-connection`, {}, { preserveScroll: true, onFinish: () => setTestingId(null) });
    }

    function destroy(store: StoreRow) {
        if (confirm(`Supprimer la boutique « ${store.name} » ? Cette action est définitive.`)) {
            router.delete(`/stores/${store.id}`, { preserveScroll: true });
        }
    }

    return (
        <div className="mdash mstore">
            <Head title="Marques & boutiques" />

            <header className="mdash-top mstore-top">
                <div className="mdash-top-in">
                    <div>
                        <h1>Marques & boutiques</h1>
                        <small>Boutiques de votre périmètre et leur connexion WhatsApp Business</small>
                    </div>
                    <div className="mstore-actions">
                        <input
                            type="search"
                            className="mstore-search"
                            aria-label="Rechercher une boutique ou une marque"
                            placeholder="Rechercher une boutique ou une marque"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                        {canManage && (
                            <>
                                <Link href="/organisation" className="mdash-btn ghost">
                                    Gérer les marques
                                </Link>
                                <button type="button" className="mdash-btn brand" disabled={brands.length === 0} onClick={() => setModal({ open: true, store: null })}>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" aria-hidden="true">
                                        <path d="M12 5v14M5 12h14" />
                                    </svg>
                                    Nouvelle boutique
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            <div className="mchat-sum mstore-sum">
                <div className="mchat-stat">
                    <span>Marques</span>
                    <b className="num">{formatNumber(counts.brands)}</b>
                </div>
                <div className="mchat-stat">
                    <span>Boutiques</span>
                    <b className="num">{formatNumber(stores.length)}</b>
                </div>
                <div className="mchat-stat">
                    <span>Numéro propre</span>
                    <b className="num">{formatNumber(counts.own)}</b>
                </div>
                <div className="mchat-stat">
                    <span>Compte partagé</span>
                    <b className="num">{formatNumber(counts.shared)}</b>
                </div>
                <div className={`mchat-stat ${counts.none ? 'bad' : ''}`}>
                    <span>Non connectées</span>
                    <b className="num">{formatNumber(counts.none)}</b>
                </div>
            </div>

            <div className="mstore-note">
                {sharedAccountConfigured ? (
                    <>
                        <b>Compte partagé actif.</b> Une boutique sans identifiants propres envoie depuis le numéro commun de l'organisation (défini
                        dans .env).
                    </>
                ) : (
                    <>
                        <b>Aucun compte partagé configuré.</b> Seules les boutiques avec leurs propres identifiants peuvent envoyer des messages.
                    </>
                )}
            </div>

            <div className="mdash-seg mstore-filter" role="group" aria-label="Filtrer par connexion">
                {FILTERS.map((f) => (
                    <button key={f.key} type="button" aria-pressed={filter === f.key} onClick={() => setFilter(f.key)}>
                        {f.label}
                    </button>
                ))}
            </div>

            {groups.length === 0 ? (
                <div className="mdash-panel mstore-empty">
                    <b>{stores.length === 0 ? 'Aucune boutique dans votre périmètre' : 'Aucune boutique ne correspond'}</b>
                    <span>
                        {stores.length === 0
                            ? canManage
                                ? 'Créez une marque dans Organisation, puis ajoutez-lui une boutique.'
                                : 'Demandez à un administrateur de vous rattacher à une boutique.'
                            : 'Modifiez la recherche ou le filtre de connexion.'}
                    </span>
                </div>
            ) : (
                <div className="mstore-groups">
                    {groups.map(([brandName, brandStores]) => (
                        <section key={brandName} className="mdash-panel mstore-brand" aria-label={brandName}>
                            <div className="mdash-ph">
                                <h2>{brandName}</h2>
                                <span className="mdash-sub">{brandStores.length > 1 ? `${brandStores.length} boutiques` : '1 boutique'}</span>
                            </div>
                            <div className="mdash-tbl">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Boutique</th>
                                            <th className="r">Contacts</th>
                                            <th>WhatsApp</th>
                                            <th>Phone Number ID</th>
                                            <th className="r">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {brandStores.map((store) => {
                                            const c = CONNECTION[store.connection];
                                            return (
                                                <tr key={store.id}>
                                                    <td className="cell-main wrap">
                                                        <b>{store.name}</b>
                                                    </td>
                                                    <td className="r mono">{formatNumber(store.contacts_count)}</td>
                                                    <td>
                                                        <span className="mdash-stat">
                                                            <span className={`mdash-pill ${c.pill}`}>{c.label}</span>
                                                            <span className="mdash-src">{c.detail}</span>
                                                        </span>
                                                    </td>
                                                    <td className="mono">
                                                        {store.whatsapp_phone_number_id ? maskId(store.whatsapp_phone_number_id) : <span className="mdash-src">—</span>}
                                                    </td>
                                                    <td className="r">
                                                        <span className="mstore-row-actions">
                                                            <button
                                                                type="button"
                                                                className="mdash-btn ghost sm"
                                                                disabled={store.connection === 'none' || testingId === store.id}
                                                                title={store.connection === 'none' ? 'Configurez d’abord la connexion' : undefined}
                                                                onClick={() => testConnection(store)}
                                                            >
                                                                {testingId === store.id ? 'Test…' : 'Tester'}
                                                            </button>
                                                            <Link
                                                                href={`/stores/${store.id}/qr`}
                                                                className="mdash-btn ghost sm"
                                                                title="QR code et affiche pour recueillir l'accord WhatsApp en caisse"
                                                            >
                                                                QR code
                                                            </Link>
                                                            <button type="button" className="mdash-btn ghost sm" onClick={() => setModal({ open: true, store })}>
                                                                Modifier
                                                            </button>
                                                            {canManage && (
                                                                <button
                                                                    type="button"
                                                                    className="mdash-btn ghost sm danger"
                                                                    disabled={store.contacts_count > 0}
                                                                    title={store.contacts_count > 0 ? 'Impossible : des contacts sont rattachés à cette boutique' : 'Supprimer'}
                                                                    aria-label={`Supprimer ${store.name}`}
                                                                    onClick={() => destroy(store)}
                                                                >
                                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                                                                        <path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6" />
                                                                    </svg>
                                                                </button>
                                                            )}
                                                        </span>
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </section>
                    ))}
                </div>
            )}

            <StoreModal
                isOpen={modal.open}
                store={modal.store}
                onClose={() => setModal({ open: false, store: modal.store })}
                canManage={canManage}
                brands={brands}
                sharedAccountConfigured={sharedAccountConfigured}
            />
        </div>
    );
}

StoresIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default StoresIndex;
