import { useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

import { Panel } from '../../components/page/Page';

export interface PrivacySettings {
    url: string;
    legal_name: string | null;
    email: string | null;
    phone: string | null;
    address: string | null;
    extra: string | null;
}

/**
 * Coordonnées affichées sur la politique de confidentialité publique de
 * l'entreprise, dont l'adresse est à saisir dans l'app Meta (Paramètres de
 * l'app › Général › URL de la politique de confidentialité).
 */
export default function PrivacyPolicyCard({ privacy }: { privacy: PrivacySettings }) {
    const { data, setData, put, processing, errors } = useForm({
        legal_name: privacy.legal_name ?? '',
        email: privacy.email ?? '',
        phone: privacy.phone ?? '',
        address: privacy.address ?? '',
        extra: privacy.extra ?? '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        put('/organisation/privacy', { preserveScroll: true });
    };

    return (
        <Panel title="Politique de confidentialité" sub="Page publique à déclarer dans votre app Meta" className="c12">
            <p className="mdash-scope" style={{ marginTop: 0 }}>
                Adresse :{' '}
                <a href={privacy.url} target="_blank" rel="noreferrer" className="mdash-link mono">
                    {privacy.url}
                </a>{' '}
                <button type="button" className="mdash-btn ghost sm" onClick={() => navigator.clipboard?.writeText(privacy.url)}>
                    Copier
                </button>
            </p>
            <form onSubmit={submit} className="mpage-form">
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px, 1fr))', gap: 12 }}>
                    <div className="mpage-field">
                        <label htmlFor="privacy_legal_name">Raison sociale (sinon nom de l'organisation)</label>
                        <input id="privacy_legal_name" value={data.legal_name} onChange={(e) => setData('legal_name', e.target.value)} />
                        {errors.legal_name && <span className="err">{errors.legal_name}</span>}
                    </div>
                    <div className="mpage-field">
                        <label htmlFor="privacy_email">Courriel de contact *</label>
                        <input id="privacy_email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                        {errors.email && <span className="err">{errors.email}</span>}
                    </div>
                    <div className="mpage-field">
                        <label htmlFor="privacy_phone">Téléphone</label>
                        <input id="privacy_phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} />
                    </div>
                    <div className="mpage-field">
                        <label htmlFor="privacy_address">Adresse</label>
                        <input id="privacy_address" value={data.address} onChange={(e) => setData('address', e.target.value)} />
                    </div>
                </div>
                <div className="mpage-field">
                    <label htmlFor="privacy_extra">Informations complémentaires (facultatif)</label>
                    <textarea id="privacy_extra" rows={3} value={data.extra} onChange={(e) => setData('extra', e.target.value)} />
                    {errors.extra && <span className="err">{errors.extra}</span>}
                </div>
                <div className="mpage-form-actions">
                    <button type="submit" className="mdash-btn brand" disabled={processing}>
                        {processing ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </div>
            </form>
        </Panel>
    );
}
