import { router } from '@inertiajs/react';
import { useState } from 'react';

import { Panel } from '../../components/page/Page';

/**
 * Envoi automatique par l'API WhatsApp Cloud (campagnes, modèles, bienvenue).
 * Désactivé, le CRM reste complet : relances manuelles par lien wa.me depuis
 * Actions du jour, envoyées depuis le téléphone de l'établissement.
 */
export default function WhatsAppApiCard({ enabled }: { enabled: boolean }) {
    const [busy, setBusy] = useState(false);

    function toggle() {
        const next = !enabled;
        const question = next
            ? 'Activer l’API WhatsApp ? Il faudra renseigner les identifiants Meta de chaque établissement dans Paramètres WhatsApp.'
            : 'Désactiver l’API WhatsApp ? Campagnes, modèles et paramètres WhatsApp seront masqués (rien n’est supprimé).';
        if (!confirm(question)) return;
        setBusy(true);
        router.put('/organisation/whatsapp-api', { enabled: next }, { preserveScroll: true, onFinish: () => setBusy(false) });
    }

    return (
        <Panel title="Envoi automatique par l'API WhatsApp" sub={enabled ? 'Activé' : 'Désactivé'} className="c12">
            <p className="mdash-scope" style={{ marginTop: 0 }}>
                {enabled
                    ? 'Campagnes ciblées, modèles Meta, messages de bienvenue et statuts remis / lu passent par l’API WhatsApp Cloud, avec le compte Meta vérifié de chaque établissement.'
                    : 'Sans compte Meta vérifié, Menentia fonctionne avec des relances manuelles : Actions du jour ouvre WhatsApp avec un message prérempli (lien wa.me), et l’équipe l’envoie depuis le téléphone de l’établissement. Activez l’API quand votre numéro est vérifié chez Meta.'}
            </p>
            <button type="button" className={`mdash-btn ${enabled ? 'ghost' : 'brand'}`} disabled={busy} onClick={toggle}>
                {enabled ? 'Désactiver l’API WhatsApp' : 'Activer l’API WhatsApp'}
            </button>
        </Panel>
    );
}
