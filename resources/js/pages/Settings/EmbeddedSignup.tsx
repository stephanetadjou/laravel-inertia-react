import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

import Button from '../../components/ui/button/Button';

/**
 * Connexion WhatsApp en 5 minutes : fenêtre Meta « inscription intégrée »
 * (Facebook Login for Business). Avec la coexistence, le numéro de
 * l'application WhatsApp Business reste sur le téléphone.
 */
export interface EmbeddedSettings {
    app_id: string;
    config_id: string;
    api_version: string;
    coexistence: boolean;
}

interface FacebookSdk {
    init(options: Record<string, unknown>): void;
    login(callback: (response: { authResponse?: { code?: string } | null }) => void, options: Record<string, unknown>): void;
}

declare global {
    interface Window {
        FB?: FacebookSdk;
        fbAsyncInit?: () => void;
    }
}

function loadSdk(settings: EmbeddedSettings): Promise<FacebookSdk> {
    return new Promise((resolve, reject) => {
        if (window.FB) {
            resolve(window.FB);
            return;
        }
        window.fbAsyncInit = () => {
            window.FB!.init({ appId: settings.app_id, autoLogAppEvents: true, xfbml: false, version: settings.api_version });
            resolve(window.FB!);
        };
        const script = document.createElement('script');
        script.src = 'https://connect.facebook.net/fr_FR/sdk.js';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.onerror = () => reject(new Error('sdk'));
        document.body.appendChild(script);
    });
}

export default function EmbeddedSignup({ storeId, settings }: { storeId: number; settings: EmbeddedSettings }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const session = useRef<{ phone_number_id?: string; waba_id?: string; coexistence?: boolean }>({});

    // Meta renvoie le numéro et le compte choisis par un message de la fenêtre.
    useEffect(() => {
        function onMessage(event: MessageEvent) {
            if (!event.origin.endsWith('facebook.com')) return;
            try {
                const data = typeof event.data === 'string' ? JSON.parse(event.data) : event.data;
                if (data?.type !== 'WA_EMBEDDED_SIGNUP') return;
                if (data.event === 'FINISH' || data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING') {
                    session.current = {
                        phone_number_id: data.data?.phone_number_id,
                        waba_id: data.data?.waba_id,
                        coexistence: data.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
                    };
                } else if (data.event === 'CANCEL') {
                    setError('Connexion interrompue avant la fin. Vous pouvez recommencer.');
                }
            } catch {
                // Message d'une autre origine Facebook : ignoré.
            }
        }
        window.addEventListener('message', onMessage);
        return () => window.removeEventListener('message', onMessage);
    }, []);

    async function connect() {
        setError(null);
        setBusy(true);
        try {
            const fb = await loadSdk(settings);
            fb.login(
                (response) => {
                    const code = response.authResponse?.code;
                    const { phone_number_id, waba_id, coexistence } = session.current;
                    if (!code || !phone_number_id || !waba_id) {
                        setBusy(false);
                        setError('La fenêtre Meta s’est fermée sans choisir de numéro. Recommencez ou faites-vous aider.');
                        return;
                    }
                    router.post(
                        `/settings/whatsapp/${storeId}/embedded`,
                        { code, phone_number_id, waba_id, coexistence: !!coexistence },
                        { preserveScroll: true, onFinish: () => setBusy(false) },
                    );
                },
                {
                    config_id: settings.config_id,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: {
                        setup: {},
                        sessionInfoVersion: '3',
                        ...(settings.coexistence ? { featureType: 'whatsapp_business_app_onboarding' } : {}),
                    },
                },
            );
        } catch {
            setBusy(false);
            setError('Impossible de charger la fenêtre Meta. Vérifiez votre connexion ou un bloqueur de publicités.');
        }
    }

    return (
        <div className="mx-5 mt-5 rounded-xl border border-brand-200 bg-brand-50/60 p-4 dark:border-brand-500/30 dark:bg-brand-500/10">
            <p className="font-medium text-gray-800 dark:text-white/90">Connexion en 5 minutes, sans changer de numéro</p>
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
                Connectez-vous avec Facebook, choisissez votre numéro WhatsApp Business, et c’est tout.
                {settings.coexistence && ' Vous gardez l’application WhatsApp Business sur votre téléphone : mêmes conversations, même numéro.'}
            </p>
            <div className="mt-3 flex flex-wrap items-center gap-3">
                <Button type="button" disabled={busy} onClick={connect}>
                    {busy ? 'Connexion en cours…' : 'Connecter WhatsApp'}
                </Button>
                {error && <span className="text-sm text-error-600">{error}</span>}
            </div>
        </div>
    );
}
