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
    frequency: number;
    monetary: number;
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

function ContactsShow() {
    const { contact, rfm, purchases, campaignMessages, flash } = usePage<PageProps>().props;
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

                <Link href="/contacts" className="text-sm text-gray-500 hover:text-gray-800 dark:text-gray-400 dark:hover:text-white">
                    ← Retour aux contacts
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800 dark:text-white/90">{contact.name}</h1>
                        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">{contact.phone_e164}</p>
                    </div>
                    <div className="flex items-center gap-4">
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
                        <Button size="sm" onClick={() => setIsEditing(true)}>
                            Modifier
                        </Button>
                    </div>
                </div>

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
                        <ComponentCard title="Indicateurs RFM">
                            <div className="overflow-x-auto">
                                <Table>
                                    <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                        <TableRow>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Récence
                                            </TableCell>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Fréquence
                                            </TableCell>
                                            <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Montant
                                            </TableCell>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                        <TableRow>
                                            <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                                {rfm.recency_days === null ? '—' : `${rfm.recency_days} j`}
                                            </TableCell>
                                            <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                                {rfm.frequency}
                                            </TableCell>
                                            <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                                {formatFcfa(rfm.monetary)}
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </ComponentCard>

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

            {isEditing && <ContactFormModal isOpen={true} onClose={() => setIsEditing(false)} contact={contact} />}
        </>
    );
}

ContactsShow.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ContactsShow;
