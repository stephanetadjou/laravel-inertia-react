import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';
import ContactFormModal from './ContactFormModal';

interface ContactDetail {
    id: number;
    customer_id: number | null;
    name: string;
    phone_e164: string;
    gender: string | null;
    birthdate: string | null;
    consent_status: string;
    store_name: string;
    group_names: string[];
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
    product_category: string | null;
    amount: number;
    purchased_at: string;
}

interface CampaignMessageRow {
    id: number;
    campaign_name: string;
    status: string;
    sent_at: string;
}

interface PageProps {
    contact: ContactDetail;
    canEdit: boolean;
    rfm: Rfm;
    purchases: PurchaseRow[];
    campaignMessages: CampaignMessageRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

const genderLabel: Record<string, string> = { H: 'Homme', F: 'Femme' };

const consentColor: Record<string, 'success' | 'warning' | 'light'> = {
    opt_in: 'success',
    opt_out: 'warning',
    unknown: 'light',
};

const messageStatusColor: Record<string, 'success' | 'error' | 'light'> = {
    sent: 'success',
    failed: 'error',
    pending: 'light',
};

function formatFcfa(amount: number) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
}

function InfoField({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div>
            <p className="text-sm text-gray-500 dark:text-gray-400">{label}</p>
            <div className="mt-1 text-sm font-medium text-gray-800 dark:text-white/90">{value}</div>
        </div>
    );
}

const segmentColor: Record<string, 'success' | 'primary' | 'info' | 'warning' | 'error' | 'light' | 'dark'> = {
    champions: 'success',
    loyal: 'success',
    new: 'info',
    potential: 'primary',
    at_risk: 'warning',
    cant_lose: 'error',
    hibernating: 'light',
    lost: 'dark',
    none: 'light',
};

const scoreBarColor = ['bg-error-500', 'bg-warning-500', 'bg-gray-400', 'bg-success-400', 'bg-success-600'];

function ScoreGauge({ letter, score }: { letter: string; score: number }) {
    return (
        <div className="flex items-center gap-2" aria-label={`Score ${letter} : ${score} sur 5`}>
            <span className="w-6 text-sm font-semibold text-gray-800 dark:text-white/90">
                {letter}
                {score}
            </span>
            <div className="flex gap-0.5">
                {[1, 2, 3, 4, 5].map((step) => (
                    <span
                        key={step}
                        className={`h-2 w-4 rounded-sm ${step <= score ? scoreBarColor[score - 1] : 'bg-gray-200 dark:bg-gray-700'}`}
                    />
                ))}
            </div>
        </div>
    );
}

function RfmMetric({ title, value, detail, letter, score }: { title: string; value: string; detail: string; letter: string; score: number | null }) {
    return (
        <div className="rounded-xl border border-gray-100 p-4 dark:border-gray-800">
            <p className="text-sm text-gray-500 dark:text-gray-400">{title}</p>
            <p className="mt-1 text-xl font-bold text-gray-800 dark:text-white/90">{value}</p>
            <p className="mt-1 text-xs text-gray-500 dark:text-gray-400">{detail}</p>
            {score !== null && (
                <div className="mt-3">
                    <ScoreGauge letter={letter} score={score} />
                </div>
            )}
        </div>
    );
}

function RfmProfile({ rfm }: { rfm: Rfm }) {
    const { scores } = rfm;

    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 md:p-6 dark:border-gray-800 dark:bg-white/[0.03]">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h3 className="text-base font-medium text-gray-800 dark:text-white/90">Profil RFM</h3>
                    <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        Récence, fréquence et montant des achats dans cette boutique
                        {scores && ` · scores 1 à 5 comparés aux ${rfm.buyers} clients acheteurs de l'organisation`}
                    </p>
                </div>
                <div className="flex flex-col items-end gap-1">
                    <Badge size="md" color={segmentColor[rfm.segment.key] ?? 'light'}>
                        {rfm.segment.label}
                    </Badge>
                    {scores && (
                        <span className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                            R{scores.r} · F{scores.f} · M{scores.m}
                        </span>
                    )}
                </div>
            </div>

            <p className="mt-3 text-sm text-gray-700 dark:text-gray-300">{rfm.segment.action}</p>

            <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <RfmMetric
                    title="Récence"
                    value={rfm.recency_days === null ? '—' : `${rfm.recency_days} j`}
                    detail={rfm.last_purchase_at ? `Dernier achat le ${rfm.last_purchase_at}` : 'Aucun achat'}
                    letter="R"
                    score={scores?.r ?? null}
                />
                <RfmMetric
                    title="Fréquence"
                    value={`${rfm.frequency} achat${rfm.frequency > 1 ? 's' : ''}`}
                    detail={`${rfm.frequency_12m} sur les 12 derniers mois`}
                    letter="F"
                    score={scores?.f ?? null}
                />
                <RfmMetric
                    title="Montant"
                    value={formatFcfa(rfm.monetary)}
                    detail={rfm.frequency > 0 ? `Panier moyen ${formatFcfa(rfm.average_basket)}` : 'Panier moyen —'}
                    letter="M"
                    score={scores?.m ?? null}
                />
            </div>
        </div>
    );
}

function ContactsShow() {
    const { contact, canEdit, rfm, purchases, campaignMessages, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;
    const [sending, setSending] = useState(false);
    const [isEditing, setIsEditing] = useState(false);

    function sendTest() {
        setSending(true);
        router.post(`/contacts/${contact.id}/send-test`, {}, { preserveScroll: true, onFinish: () => setSending(false) });
    }

    function destroyContact() {
        if (confirm(`Supprimer le contact « ${contact.name} » ? Ses achats et historique de campagnes seront aussi supprimés.`)) {
            router.delete(`/contacts/${contact.id}`);
        }
    }

    return (
        <>
            <Head title={contact.name} />

            <PageBreadcrumb pageTitle={contact.name} />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <Link
                    href={contact.customer_id ? `/customers/${contact.customer_id}` : '/customers'}
                    className="text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white"
                >
                    {contact.customer_id ? '← Retour à la fiche client' : '← Fiches clients'}
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white/90">{contact.name}</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{contact.phone_e164}</p>
                    </div>
                    <div className="flex items-center gap-4">
                        {contact.customer_id && (
                            <Link
                                href={`/customers/${contact.customer_id}`}
                                className="text-sm font-medium text-brand-500 hover:text-brand-600 dark:text-brand-400"
                            >
                                Fiche client (toutes boutiques)
                            </Link>
                        )}
                        <Link
                            href={`/chat/${contact.id}`}
                            className="text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
                        >
                            Chat
                        </Link>
                        <button type="button" onClick={destroyContact} className="text-sm font-medium text-error-500 hover:text-error-600">
                            Supprimer
                        </button>
                        <Button variant="outline" size="sm" disabled={sending} onClick={sendTest}>
                            {sending ? 'Envoi...' : 'Envoyer un message test'}
                        </Button>
                        {canEdit && (
                            <Button size="sm" onClick={() => setIsEditing(true)}>
                                Modifier
                            </Button>
                        )}
                    </div>
                </div>

                <RfmProfile rfm={rfm} />

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="lg:col-span-1">
                        <div className="rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
                            <div className="flex items-center justify-between px-6 py-5">
                                <h3 className="text-base font-medium text-gray-800 dark:text-white/90">Informations</h3>
                                <Badge size="sm" color={consentColor[contact.consent_status] ?? 'light'}>
                                    {contact.consent_status}
                                </Badge>
                            </div>
                            <div className="space-y-5 border-t border-gray-100 p-6 dark:border-gray-800">
                                <InfoField label="Téléphone" value={contact.phone_e164} />
                                <InfoField label="Boutique" value={contact.store_name} />
                                <InfoField label="Sexe" value={contact.gender ? (genderLabel[contact.gender] ?? contact.gender) : '—'} />
                                <InfoField label="Naissance" value={contact.birthdate ?? '—'} />
                                <InfoField
                                    label="Groupes"
                                    value={
                                        contact.group_names.length === 0 ? (
                                            '—'
                                        ) : (
                                            <div className="flex flex-wrap gap-1">
                                                {contact.group_names.map((name) => (
                                                    <Badge key={name} size="sm" color="info">
                                                        {name}
                                                    </Badge>
                                                ))}
                                            </div>
                                        )
                                    }
                                />
                            </div>
                        </div>
                    </div>

                    <div className="space-y-4 lg:col-span-2">
                        <ComponentCard title="Historique d'achats" desc="Le plus récent en premier">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                        <TableRow>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Date
                                            </TableCell>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Catégorie
                                            </TableCell>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Montant
                                            </TableCell>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                        {purchases.length === 0 && (
                                            <TableRow>
                                                <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={3}>
                                                    Aucun achat enregistré pour ce contact.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {purchases.map((purchase) => (
                                            <TableRow key={purchase.id}>
                                                <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{purchase.purchased_at}</TableCell>
                                                <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                                    {purchase.product_category ?? '—'}
                                                </TableCell>
                                                <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                                    {formatFcfa(purchase.amount)}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </ComponentCard>

                        <ComponentCard title="Campagnes reçues">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                        <TableRow>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Campagne
                                            </TableCell>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Statut
                                            </TableCell>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Dernière mise à jour
                                            </TableCell>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                        {campaignMessages.length === 0 && (
                                            <TableRow>
                                                <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={3}>
                                                    Ce contact n'a encore reçu aucune campagne.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                        {campaignMessages.map((message) => (
                                            <TableRow key={message.id}>
                                                <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                                    {message.campaign_name}
                                                </TableCell>
                                                <TableCell className="px-4 py-3 text-sm">
                                                    <Badge size="sm" color={messageStatusColor[message.status] ?? 'light'}>
                                                        {message.status}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{message.sent_at}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </ComponentCard>
                    </div>
                </div>
            </div>

            {canEdit && isEditing && <ContactFormModal isOpen={true} onClose={() => setIsEditing(false)} contact={contact} />}
        </>
    );
}

ContactsShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ContactsShow;
