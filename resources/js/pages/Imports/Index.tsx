import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

interface StoreOption {
    id: number;
    name: string;
}

interface ImportPreview {
    contacts_to_create: number;
    contacts_to_update: number;
    purchases_to_record: number;
    total_valid: number;
    skipped: string[];
    sample: { name: string; phone_e164: string }[];
}

interface PageProps {
    stores: StoreOption[];
    preview: ImportPreview | null;
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function ImportsIndex() {
    const { stores, preview, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;

    const { data, setData, post, processing, errors, reset } = useForm<{
        store_id: string;
        file: File | null;
    }>({
        store_id: stores[0]?.id.toString() ?? '',
        file: null,
    });

    const previewFile: FormEventHandler = (e) => {
        e.preventDefault();
        post('/imports/preview', {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    const confirmImport: FormEventHandler = (e) => {
        e.preventDefault();
        post('/imports', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    function cancelPreview() {
        reset('file');
        router.get('/imports', {}, { preserveScroll: true });
    }

    return (
        <>
            <Head title="Import clients" />

            <PageBreadcrumb pageTitle="Import clients" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Import terminé" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                {!preview ? (
                    <ComponentCard
                        title="Importer des contacts par boutique"
                        desc="Fichier CSV ou Excel (xlsx/xls) avec en-tête : nom, telephone (obligatoires), et optionnellement categorie, montant, date_achat (pour enregistrer un achat), naissance, sexe."
                    >
                        <form onSubmit={previewFile} className="space-y-5">
                            <div>
                                <label htmlFor="store_id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Boutique
                                </label>
                                <select
                                    id="store_id"
                                    value={data.store_id}
                                    onChange={(e) => setData('store_id', e.target.value)}
                                    className={fieldClasses()}
                                >
                                    {stores.map((store) => (
                                        <option key={store.id} value={store.id}>
                                            {store.name}
                                        </option>
                                    ))}
                                </select>
                                {errors.store_id && <p className="mt-1.5 text-sm text-error-500">{errors.store_id}</p>}
                            </div>

                            <div>
                                <label htmlFor="file" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Fichier CSV ou Excel
                                </label>
                                <input
                                    id="file"
                                    type="file"
                                    accept=".csv,.txt,.xlsx,.xls"
                                    onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 file:mr-4 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                                />
                                {errors.file && <p className="mt-1.5 text-sm text-error-500">{errors.file}</p>}
                            </div>

                            <Button disabled={processing || !data.file}>{processing ? 'Analyse en cours...' : 'Voir l’aperçu'}</Button>
                        </form>
                    </ComponentCard>
                ) : (
                    <ComponentCard
                        title="Aperçu de l'import"
                        desc="Rien n'est encore enregistré. Vérifiez le résumé puis confirmez, ou annulez pour changer de fichier."
                    >
                        <div className="space-y-5">
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                                <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                                    <p className="text-xs text-gray-500 dark:text-gray-400">Nouveaux contacts</p>
                                    <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{preview.contacts_to_create}</p>
                                </div>
                                <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                                    <p className="text-xs text-gray-500 dark:text-gray-400">Mis à jour</p>
                                    <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{preview.contacts_to_update}</p>
                                </div>
                                <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                                    <p className="text-xs text-gray-500 dark:text-gray-400">Achats détectés</p>
                                    <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{preview.purchases_to_record}</p>
                                </div>
                                <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                                    <p className="text-xs text-gray-500 dark:text-gray-400">Lignes ignorées</p>
                                    <p className={`mt-1 text-xl font-semibold ${preview.skipped.length > 0 ? 'text-warning-600' : 'text-gray-800 dark:text-white/90'}`}>
                                        {preview.skipped.length}
                                    </p>
                                </div>
                            </div>

                            {preview.sample.length > 0 && (
                                <div>
                                    <p className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Échantillon ({preview.sample.length} sur {preview.total_valid} contacts valides)
                                    </p>
                                    <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                        <Table>
                                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                                <TableRow>
                                                    <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                        Nom
                                                    </TableCell>
                                                    <TableCell isHeader className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                        Téléphone
                                                    </TableCell>
                                                </TableRow>
                                            </TableHeader>
                                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                                {preview.sample.map((row, index) => (
                                                    <TableRow key={index}>
                                                        <TableCell className="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{row.name}</TableCell>
                                                        <TableCell className="px-4 py-2 text-sm text-gray-600 dark:text-gray-400">{row.phone_e164}</TableCell>
                                                    </TableRow>
                                                ))}
                                            </TableBody>
                                        </Table>
                                    </div>
                                </div>
                            )}

                            {preview.skipped.length > 0 && (
                                <div className="rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                    <p className="mb-1 font-medium">Lignes ignorées :</p>
                                    <ul className="list-inside list-disc space-y-0.5">
                                        {preview.skipped.slice(0, 10).map((reason, index) => (
                                            <li key={index}>{reason}</li>
                                        ))}
                                        {preview.skipped.length > 10 && <li>... et {preview.skipped.length - 10} autre(s).</li>}
                                    </ul>
                                </div>
                            )}

                            {preview.total_valid === 0 ? (
                                <p className="text-sm text-error-500">Aucune ligne valide à importer dans ce fichier.</p>
                            ) : (
                                <form onSubmit={confirmImport} className="flex flex-wrap gap-2">
                                    <Button disabled={processing}>{processing ? 'Import en cours...' : "Confirmer l'import"}</Button>
                                    <Button type="button" variant="outline" onClick={cancelPreview}>
                                        Annuler, choisir un autre fichier
                                    </Button>
                                </form>
                            )}
                            {preview.total_valid === 0 && (
                                <Button type="button" variant="outline" onClick={cancelPreview}>
                                    Choisir un autre fichier
                                </Button>
                            )}
                        </div>
                    </ComponentCard>
                )}
            </div>
        </>
    );
}

ImportsIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default ImportsIndex;
