import { Head, router } from '@inertiajs/react';

import MenentiaLogo from '../components/common/MenentiaLogo';

/*
 * Page affichée aux utilisateurs d'une entreprise suspendue depuis le
 * back-office plateforme (cf. EnsureOrganizationActive).
 */

export default function Suspended({ organization, reason, contact }: { organization: string; reason: string | null; contact: string | null }) {
    return (
        <>
            <Head title="Compte suspendu" />

            <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4 dark:bg-gray-900">
                <div className="w-full max-w-md rounded-2xl border border-gray-200 bg-white p-8 text-center shadow-theme-md dark:border-gray-800 dark:bg-gray-900">
                    <h1 className="mb-6 flex justify-center">
                        <MenentiaLogo symbolClassName="h-10 w-10" wordmarkClassName="text-[28px]" />
                    </h1>
                    <h2 className="text-lg font-semibold text-gray-800 dark:text-white/90">Compte suspendu</h2>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        L’accès de <b>{organization}</b> à NENTIX est suspendu{reason ? ` : ${reason}` : ''}. Vos données sont conservées.
                    </p>
                    {contact && (
                        <p className="mt-2 text-sm text-gray-600 dark:text-gray-400">
                            Pour le rétablir, écrivez-nous à{' '}
                            <a href={`mailto:${contact}`} className="font-medium text-brand-500">
                                {contact}
                            </a>
                            .
                        </p>
                    )}
                    <button type="button" onClick={() => router.post('/logout')} className="mt-6 text-sm font-medium text-gray-500 hover:text-gray-700 dark:text-gray-400">
                        Se déconnecter
                    </button>
                </div>
            </div>
        </>
    );
}
