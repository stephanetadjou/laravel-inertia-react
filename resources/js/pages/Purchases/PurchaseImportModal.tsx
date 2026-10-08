import { router, useForm } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

export interface PurchaseImportPreview {
    purchases_to_record: number;
    contacts_to_create: number;
    total_amount: number;
    consents: { opt_in: number; opt_out: number };
    skipped: string[];
    sample: {
        contact_name: string;
        store_name: string;
        category: string | null;
        quantity: number;
        amount: number;
        purchased_at: string;
        new_contact: boolean;
    }[];
}

interface PurchaseImportModalProps {
    isOpen: boolean;
    onClose: () => void;
    preview: PurchaseImportPreview | null;
}

function formatFcfa(amount: number) {
    return new Intl.NumberFormat('fr-FR').format(amount) + ' FCFA';
}

/**
 * Message affiché dans la fenêtre quand le serveur répond par une erreur HTTP
 * (au lieu de la fenêtre d'erreur générique d'Inertia, parfois vide).
 */
export function importHttpErrorMessage(status: number): string {
    if (status === 413) return 'Fichier trop volumineux pour le serveur : réduisez-le ou découpez-le en plusieurs fichiers.';
    if (status === 419) return 'Votre session a expiré : rechargez la page puis recommencez.';
    if (status === 429) return 'Trop de tentatives : patientez une minute avant de réessayer.';
    if (status === 403) return "Vous n'avez pas accès à cet import.";
    return `Le serveur n'a pas pu traiter le fichier (erreur ${status}). Si le disque du serveur est plein, l'envoi échoue : réessayez plus tard ou contactez l'administrateur.`;
}

export default function PurchaseImportModal({ isOpen, onClose, preview }: PurchaseImportModalProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{ file: File | null }>({ file: null });
    const [requestError, setRequestError] = useState<string | null>(null);

    // Erreurs de validation sans champ affiché (le champ fichier n'est visible
    // qu'avant l'aperçu), à montrer dans le bandeau d'erreur de la fenêtre.
    const otherErrors = Object.entries(errors)
        .filter(([field]) => field !== 'file' || preview !== null)
        .map(([, message]) => message);

    const requestCallbacks = {
        forceFormData: true,
        preserveScroll: true,
        onStart: () => setRequestError(null),
        onHttpException: (response: { status: number }) => {
            setRequestError(importHttpErrorMessage(response.status));
            return false;
        },
        onNetworkError: () => {
            setRequestError('Serveur injoignable : vérifiez la connexion puis réessayez.');
            return false;
        },
    };

    const previewFile: FormEventHandler = (e) => {
        e.preventDefault();
        post('/purchases/preview', requestCallbacks);
    };

    const confirmImport: FormEventHandler = (e) => {
        e.preventDefault();
        post('/purchases', {
            ...requestCallbacks,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    function close() {
        reset();
        clearErrors();
        setRequestError(null);

        if (preview) {
            router.reload({ only: ['preview'] });
        }

        onClose();
    }

    return (
        <Modal isOpen={isOpen} onClose={close} className="max-w-2xl p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Importer un historique d'achats</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Le numéro WhatsApp est l'identifiant du client. Si le contact n'existe pas encore dans la boutique concernée, il est créé
                automatiquement (consentement « unknown » par défaut — un achat n'est jamais considéré comme une preuve d'opt-in marketing).
            </p>

            <div className="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4 text-xs text-gray-600 dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-400">
                <p className="mb-2 font-medium text-gray-700 dark:text-gray-300">Format attendu (CSV ou Excel), en-tête obligatoire :</p>
                <ul className="list-inside list-disc space-y-1">
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">telephone</code> — obligatoire
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">boutique</code> — obligatoire, nom exact (Adidas, Carré, Mango,
                        Grand Store...)
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">date_achat</code> — obligatoire (ex. 2026-03-15)
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">nom</code> — optionnel, utilisé seulement si le contact est
                        créé (sinon le nom déjà enregistré est conservé)
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">quantite</code> — optionnel, défaut 1
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">consentement</code> — optionnel : <b>oui</b> si le client a accepté en caisse de recevoir les offres WhatsApp, <b>non</b> s'il a refusé ; vide = inchangé
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">prix</code> (prix unitaire) et/ou{' '}
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">montant</code> (total) — l'un des deux obligatoire ; si les
                        deux sont présents, <code className="rounded bg-gray-200 px-1 dark:bg-white/10">montant</code> est utilisé tel quel
                    </li>
                    <li>
                        <code className="rounded bg-gray-200 px-1 dark:bg-white/10">categorie</code> — optionnel
                    </li>
                </ul>
            </div>

            {(requestError || otherErrors.length > 0) && (
                <div role="alert" className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-500">
                    {requestError ?? otherErrors.join(' ')}
                </div>
            )}

            {!preview ? (
                <form onSubmit={previewFile} className="space-y-5">
                    <div>
                        <label htmlFor="purchase_file" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Fichier CSV ou Excel
                        </label>
                        <input
                            id="purchase_file"
                            type="file"
                            accept=".csv,.txt,.xlsx,.xls"
                            onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                            className="w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 file:mr-4 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-600 dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                        />
                        {errors.file && <p className="mt-1.5 text-sm text-error-500">{errors.file}</p>}
                    </div>

                    <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <Button type="button" variant="outline" onClick={close}>
                            Annuler
                        </Button>
                        <Button disabled={processing || !data.file}>{processing ? 'Analyse en cours...' : "Voir l'aperçu"}</Button>
                    </div>
                </form>
            ) : (
                <div className="space-y-5">
                    <div className="grid grid-cols-3 gap-4">
                        <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p className="text-xs text-gray-500 dark:text-gray-400">Achats à enregistrer</p>
                            <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{preview.purchases_to_record}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p className="text-xs text-gray-500 dark:text-gray-400">Nouveaux clients</p>
                            <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{preview.contacts_to_create}</p>
                        </div>
                        <div className="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-white/[0.03]">
                            <p className="text-xs text-gray-500 dark:text-gray-400">Montant total</p>
                            <p className="mt-1 text-xl font-semibold text-gray-800 dark:text-white/90">{formatFcfa(preview.total_amount)}</p>
                        </div>
                    </div>
                    {(preview.consents?.opt_in ?? 0) + (preview.consents?.opt_out ?? 0) > 0 ? (
                        <p className="rounded-lg bg-success-50 px-3 py-2 text-sm text-success-700 dark:bg-success-500/15 dark:text-success-400">
                            Consentement WhatsApp déclaré en caisse : <b>{preview.consents.opt_in} accord(s)</b> (opt-in) et <b>{preview.consents.opt_out} refus</b> (opt-out).
                        </p>
                    ) : (
                        <p className="text-xs text-gray-500 dark:text-gray-400">
                            Aucune colonne « consentement » renseignée : les nouveaux clients seront au consentement « inconnu » et ne recevront pas de campagne.
                        </p>
                    )}

                    {preview.sample.length > 0 && (
                        <div>
                            <p className="mb-2 text-sm font-medium text-gray-700 dark:text-gray-300">Échantillon</p>
                            <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
                                <Table>
                                    <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                        <TableRow>
                                            <TableCell isHeader className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Contact
                                            </TableCell>
                                            <TableCell isHeader className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Boutique
                                            </TableCell>
                                            <TableCell isHeader className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Qté
                                            </TableCell>
                                            <TableCell isHeader className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                                Montant
                                            </TableCell>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                        {preview.sample.map((row, index) => (
                                            <TableRow key={index}>
                                                <TableCell className="px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                                                    {row.contact_name}
                                                    {row.new_contact && (
                                                        <span className="ml-1.5 rounded-full bg-brand-50 px-1.5 py-0.5 text-[10px] font-medium text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
                                                            nouveau
                                                        </span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{row.store_name}</TableCell>
                                                <TableCell className="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{row.quantity}</TableCell>
                                                <TableCell className="px-3 py-2 text-sm text-gray-600 dark:text-gray-400">{formatFcfa(row.amount)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    )}

                    {preview.skipped.length > 0 && (
                        <div className="rounded-lg border border-warning-500/40 bg-warning-50 px-4 py-3 text-sm text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                            <p className="mb-1 font-medium">{preview.skipped.length} ligne(s) ignorée(s) :</p>
                            <ul className="list-inside list-disc space-y-0.5">
                                {preview.skipped.slice(0, 10).map((reason, index) => (
                                    <li key={index}>{reason}</li>
                                ))}
                                {preview.skipped.length > 10 && <li>... et {preview.skipped.length - 10} autre(s).</li>}
                            </ul>
                        </div>
                    )}

                    <form onSubmit={confirmImport} className="flex flex-wrap justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                        <Button type="button" variant="outline" onClick={close}>
                            Annuler
                        </Button>
                        <Button disabled={processing || preview.purchases_to_record === 0}>
                            {processing ? 'Import en cours...' : "Confirmer l'import"}
                        </Button>
                    </form>
                </div>
            )}
        </Modal>
    );
}
