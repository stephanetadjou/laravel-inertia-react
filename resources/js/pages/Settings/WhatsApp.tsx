import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import Button from '../../components/ui/button/Button';
import '../Dashboard/dashboard.css';

interface StoreSettings {
    id: number;
    name: string;
    brand_name: string | null;
    business_account_id: string | null;
    phone_number_id: string | null;
    app_id: string | null;
    has_access_token: boolean;
    has_app_secret: boolean;
    connected: boolean;
    shared_account: boolean;
    verified_name: string | null;
    display_phone_number: string | null;
    quality_rating: string | null;
    messaging_limit_tier: string | null;
    daily_limit: number | null;
    checked_at: string | null;
    registered_at: string | null;
}

interface PageProps {
    stores: StoreSettings[];
    flash?: { success: string | null; error: string | null };
    [key: string]: unknown;
}

const QUALITY: Record<string, { label: string; pill: string }> = {
    GREEN: { label: 'Qualité élevée', pill: 'ok' },
    YELLOW: { label: 'Qualité moyenne', pill: 'warn' },
    RED: { label: 'Qualité faible', pill: 'bad' },
};

const input =
    'w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 text-sm text-gray-800 placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const labelClass = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';
const hint = 'mt-1.5 text-xs text-gray-500 dark:text-gray-400';

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString('fr-FR', { dateStyle: 'short', timeStyle: 'short' }) : '—';
}

function StoreCard({ store }: { store: StoreSettings }) {
    const { data, setData, put, processing, errors } = useForm({
        business_account_id: store.business_account_id ?? '',
        phone_number_id: store.phone_number_id ?? '',
        app_id: store.app_id ?? '',
        access_token: '',
        app_secret: '',
    });
    const [pin, setPin] = useState('');
    const [busy, setBusy] = useState<'test' | 'register' | null>(null);
    const [pinError, setPinError] = useState<string | null>(null);

    const save: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/settings/whatsapp/${store.id}`, {
            preserveScroll: true,
            onSuccess: () => setData((d) => ({ ...d, access_token: '', app_secret: '' })),
        });
    };

    function test() {
        setBusy('test');
        router.post(`/settings/whatsapp/${store.id}/test`, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    }

    function register() {
        setBusy('register');
        setPinError(null);
        router.post(
            `/settings/whatsapp/${store.id}/register`,
            { pin },
            {
                preserveScroll: true,
                onSuccess: () => setPin(''),
                onError: (errs) => setPinError(errs.pin ?? null),
                onFinish: () => setBusy(null),
            },
        );
    }

    const quality = store.quality_rating ? QUALITY[store.quality_rating] : null;

    return (
        <section className="mdash-panel" aria-label={store.name}>
            <div className="mdash-ph">
                <h2>
                    {store.name}
                    {store.brand_name && store.brand_name !== store.name && <span className="mdash-sub"> · {store.brand_name}</span>}
                </h2>
                <span className="mdash-stat">
                    {store.connected ? (
                        <span className={`mdash-pill ${store.shared_account ? 'info' : 'ok'}`}>{store.shared_account ? 'Compte partagé' : 'Identifiants saisis'}</span>
                    ) : (
                        <span className="mdash-pill bad">Non connecté</span>
                    )}
                    {store.registered_at && <span className="mdash-pill ok">Numéro enregistré</span>}
                </span>
            </div>

            <div className="grid gap-6 p-5 lg:grid-cols-[1fr_320px]">
                <form onSubmit={save} className="space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div>
                            <label htmlFor={`waba-${store.id}`} className={labelClass}>
                                WABA ID
                            </label>
                            <input
                                id={`waba-${store.id}`}
                                inputMode="numeric"
                                value={data.business_account_id}
                                onChange={(e) => setData('business_account_id', e.target.value)}
                                className={input}
                            />
                            {errors.business_account_id && <p className="mt-1.5 text-sm text-error-500">{errors.business_account_id}</p>}
                        </div>
                        <div>
                            <label htmlFor={`phone-${store.id}`} className={labelClass}>
                                Phone Number ID
                            </label>
                            <input
                                id={`phone-${store.id}`}
                                inputMode="numeric"
                                value={data.phone_number_id}
                                onChange={(e) => setData('phone_number_id', e.target.value)}
                                className={input}
                            />
                            {errors.phone_number_id && <p className="mt-1.5 text-sm text-error-500">{errors.phone_number_id}</p>}
                        </div>
                        <div>
                            <label htmlFor={`app-${store.id}`} className={labelClass}>
                                App ID
                            </label>
                            <input id={`app-${store.id}`} inputMode="numeric" value={data.app_id} onChange={(e) => setData('app_id', e.target.value)} className={input} />
                            {errors.app_id && <p className="mt-1.5 text-sm text-error-500">{errors.app_id}</p>}
                        </div>
                    </div>
                    <p className={hint}>Meta Business › WhatsApp Manager (WABA ID, Phone Number ID) et developers.facebook.com › votre application (App ID).</p>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label htmlFor={`token-${store.id}`} className={labelClass}>
                                Token d'accès permanent
                            </label>
                            <input
                                id={`token-${store.id}`}
                                type="password"
                                autoComplete="new-password"
                                value={data.access_token}
                                onChange={(e) => setData('access_token', e.target.value)}
                                placeholder={store.has_access_token ? '•••••••• (enregistré, inchangé)' : 'Token de l’utilisateur système'}
                                className={input}
                            />
                            {errors.access_token && <p className="mt-1.5 text-sm text-error-500">{errors.access_token}</p>}
                        </div>
                        <div>
                            <label htmlFor={`secret-${store.id}`} className={labelClass}>
                                App Secret
                            </label>
                            <input
                                id={`secret-${store.id}`}
                                type="password"
                                autoComplete="new-password"
                                value={data.app_secret}
                                onChange={(e) => setData('app_secret', e.target.value)}
                                placeholder={store.has_app_secret ? '•••••••• (enregistré, inchangé)' : 'Paramètres › Général de l’application'}
                                className={input}
                            />
                            {errors.app_secret && <p className="mt-1.5 text-sm text-error-500">{errors.app_secret}</p>}
                        </div>
                    </div>
                    <p className={hint}>Le token et l'App Secret sont chiffrés et ne sont jamais réaffichés. Laissez vide pour conserver la valeur enregistrée.</p>

                    <div className="flex flex-wrap gap-2">
                        <Button disabled={processing}>{processing ? 'Enregistrement…' : 'Enregistrer'}</Button>
                        <Button type="button" variant="outline" disabled={!store.connected || busy !== null} onClick={test}>
                            {busy === 'test' ? 'Test en cours…' : 'Tester'}
                        </Button>
                    </div>
                </form>

                <aside className="space-y-4 rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-800">
                    <div>
                        <h3 className="mb-2 font-semibold text-gray-800 dark:text-white/90">Dernier test</h3>
                        {store.checked_at ? (
                            <dl className="space-y-1 text-gray-600 dark:text-gray-400">
                                <div>
                                    <dt className="inline">Nom vérifié : </dt>
                                    <dd className="inline font-medium text-gray-800 dark:text-white/90">{store.verified_name ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="inline">Numéro : </dt>
                                    <dd className="inline">{store.display_phone_number ?? '—'}</dd>
                                </div>
                                <div>
                                    <dt className="inline">Qualité : </dt>
                                    <dd className="inline">{quality ? <span className={`mdash-pill ${quality.pill}`}>{quality.label}</span> : 'inconnue'}</dd>
                                </div>
                                <div>
                                    <dt className="inline">Limite : </dt>
                                    <dd className="inline">
                                        {store.daily_limit ? `${store.daily_limit.toLocaleString('fr-FR')} destinataires / 24 h` : (store.messaging_limit_tier ?? 'non communiquée')}
                                    </dd>
                                </div>
                                <div className="text-xs">Testé le {formatDate(store.checked_at)}</div>
                            </dl>
                        ) : (
                            <p className="text-gray-500 dark:text-gray-400">Pas encore testé.</p>
                        )}
                    </div>

                    <div className="border-t border-gray-100 pt-4 dark:border-gray-800">
                        <h3 className="mb-1 font-semibold text-gray-800 dark:text-white/90">Enregistrer le numéro</h3>
                        <p className={`${hint} mb-2`}>
                            Obligatoire une fois avant le premier envoi. PIN de vérification en deux étapes à 6 chiffres : il est défini à cette occasion
                            s'il n'existe pas encore. Il n'est pas conservé.
                        </p>
                        <div className="flex gap-2">
                            <input
                                aria-label={`PIN de ${store.name}`}
                                inputMode="numeric"
                                maxLength={6}
                                value={pin}
                                onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
                                placeholder="PIN"
                                className={`${input} max-w-28 font-mono`}
                            />
                            <Button type="button" variant="outline" disabled={!store.connected || pin.length !== 6 || busy !== null} onClick={register}>
                                {busy === 'register' ? 'Envoi…' : 'Enregistrer le numéro'}
                            </Button>
                        </div>
                        {pinError && <p className="mt-1.5 text-sm text-error-500">{pinError}</p>}
                        {store.registered_at && <p className={hint}>Enregistré le {formatDate(store.registered_at)}.</p>}
                    </div>
                </aside>
            </div>
        </section>
    );
}

function WhatsAppSettings() {
    const { stores, flash } = usePage<PageProps>().props;

    return (
        <div className="mdash">
            <Head title="Paramètres WhatsApp" />

            <header className="mdash-top">
                <div className="mdash-top-in">
                    <div>
                        <h1>Paramètres WhatsApp</h1>
                        <small>Connexion de chaque établissement à son compte WhatsApp Business Cloud API</small>
                    </div>
                </div>
            </header>

            {flash?.success && <div className="mdash-flash ok">{flash.success}</div>}
            {flash?.error && <div className="mdash-flash bad">{flash.error}</div>}

            {stores.length === 0 ? (
                <div className="mdash-panel p-6 text-sm text-gray-500">Aucun établissement : créez-en un dans Marques & boutiques.</div>
            ) : (
                <div className="space-y-6">
                    {stores.map((store) => (
                        <StoreCard key={store.id} store={store} />
                    ))}
                </div>
            )}
        </div>
    );
}

WhatsAppSettings.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default WhatsAppSettings;
