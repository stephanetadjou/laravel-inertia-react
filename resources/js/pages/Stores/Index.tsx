import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

interface StoreRow {
    id: number;
    name: string;
    brand_name: string;
    whatsapp_phone_number_id: string | null;
    has_token: boolean;
}

interface PageProps {
    stores: StoreRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function StoreRowForm({ store }: { store: StoreRow }) {
    const [phoneNumberId, setPhoneNumberId] = useState(store.whatsapp_phone_number_id ?? '');
    const [token, setToken] = useState('');
    const [saving, setSaving] = useState(false);
    const [testing, setTesting] = useState(false);

    function save() {
        setSaving(true);
        router.put(
            `/stores/${store.id}`,
            { whatsapp_phone_number_id: phoneNumberId, whatsapp_access_token: token },
            { preserveScroll: true, onFinish: () => { setSaving(false); setToken(''); } },
        );
    }

    function testConnection() {
        setTesting(true);
        router.post(`/stores/${store.id}/test-connection`, {}, { preserveScroll: true, onFinish: () => setTesting(false) });
    }

    return (
        <TableRow>
            <TableCell className="px-4 py-3 align-top text-sm">
                <p className="font-medium text-gray-800 dark:text-white/90">{store.name}</p>
                <p className="text-xs text-gray-400 dark:text-gray-500">{store.brand_name}</p>
            </TableCell>
            <TableCell className="px-4 py-3 align-top">
                <input
                    type="text"
                    value={phoneNumberId}
                    onChange={(e) => setPhoneNumberId(e.target.value)}
                    placeholder="Phone Number ID"
                    className="w-48 rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
                />
            </TableCell>
            <TableCell className="px-4 py-3 align-top">
                <input
                    type="password"
                    value={token}
                    onChange={(e) => setToken(e.target.value)}
                    placeholder={store.has_token ? '•••• (laisser vide pour garder)' : 'Access token'}
                    className="w-56 rounded-lg border border-gray-300 bg-transparent px-3 py-2 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:text-white/90"
                />
            </TableCell>
            <TableCell className="px-4 py-3 align-top whitespace-nowrap">
                <Badge size="sm" color={store.has_token ? 'success' : 'light'}>
                    {store.has_token ? 'configuré' : 'repli sur .env global'}
                </Badge>
            </TableCell>
            <TableCell className="px-4 py-3 text-right align-top whitespace-nowrap">
                <div className="flex justify-end gap-2">
                    <Button size="sm" variant="outline" disabled={saving} onClick={save}>
                        {saving ? 'Enregistrement...' : 'Enregistrer'}
                    </Button>
                    <Button size="sm" disabled={testing} onClick={testConnection}>
                        {testing ? 'Test...' : 'Tester la connexion'}
                    </Button>
                </div>
            </TableCell>
        </TableRow>
    );
}

function StoresIndex() {
    const { stores, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;

    return (
        <>
            <Head title="Boutiques" />

            <PageBreadcrumb pageTitle="Boutiques" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <ComponentCard title="Connexion WhatsApp par boutique" desc="Tant qu'une boutique n'a pas ses propres identifiants, les envois utilisent les identifiants globaux définis dans .env.">
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Boutique
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Phone Number ID
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Access Token
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Statut
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3" />
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {stores.map((store) => (
                                    <StoreRowForm key={store.id} store={store} />
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </ComponentCard>
            </div>
        </>
    );
}

StoresIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default StoresIndex;
