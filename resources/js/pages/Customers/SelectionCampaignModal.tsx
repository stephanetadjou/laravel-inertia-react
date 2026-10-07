import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';
import TemplateParams, { CostEstimate, TemplateWithParams, templateParamsReady } from '../Campaigns/TemplateParams';

export interface CampaignTemplate extends TemplateWithParams {
    category: string | null;
}

interface Audience {
    customers: number;
    reachable_customers: number;
    recipients: number;
    stores: string[];
}

interface SelectionCampaignModalProps {
    isOpen: boolean;
    onClose: () => void;
    /** null = toutes les fiches du filtre. */
    customerIds: number[] | null;
    /** Filtres de la page (étape, VIP, consentement, recherche, marque, boutique). */
    filters: Record<string, string | number>;
    defaultName: string;
    templates: CampaignTemplate[];
}

const field =
    'w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const label = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';

function plural(n: number, word: string, pluralWord = word + 's') {
    return `${new Intl.NumberFormat('fr-FR').format(n)} ${n > 1 ? pluralWord : word}`;
}

/**
 * Campagne lancée depuis la page Fiches clients. Les destinataires sont les
 * contacts des clients sélectionnés qui correspondent au filtre (boutique,
 * VIP…) et sont opt-in : l'aperçu les compte avant l'envoi.
 */
export default function SelectionCampaignModal({ isOpen, onClose, customerIds, filters, defaultName, templates }: SelectionCampaignModalProps) {
    const [audience, setAudience] = useState<Audience | null>(null);
    const { data, setData, post, processing, errors, reset, transform } = useForm({
        name: defaultName,
        message_type: 'template' as 'template' | 'text',
        template_name: templates[0]?.name ?? '',
        template_id: templates[0] ? String(templates[0].id) : '',
        template_variables: [] as string[],
        header_image: null as File | null,
        header_image_url: '',
        text_body: '',
    });

    useEffect(() => {
        if (!isOpen) return;
        setData('name', defaultName);
        setAudience(null);

        const params = new URLSearchParams();
        Object.entries(filters).forEach(([k, v]) => params.set(k, String(v)));
        if (customerIds === null) {
            params.set('all', '1');
        } else {
            customerIds.forEach((id) => params.append('customer_ids[]', String(id)));
        }

        const controller = new AbortController();
        fetch(`/customers/audience?${params.toString()}`, { signal: controller.signal, headers: { Accept: 'application/json' } })
            .then((res) => res.json())
            .then(setAudience)
            .catch(() => {});

        return () => controller.abort();
    }, [isOpen]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        transform((d) => ({
            ...d,
            contact_source: 'customers',
            filters,
            ...(customerIds === null ? { all_customers: 1 } : { customer_ids: customerIds }),
        }));
        post('/campaigns', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        });
    };

    const excluded = audience ? audience.customers - audience.reachable_customers : 0;
    const selectedTemplate = templates.find((t) => String(t.id) === data.template_id);

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-xl p-6 sm:p-8">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Nouvelle campagne</h3>
            <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">Envoyée aux clients sélectionnés, depuis le numéro WhatsApp de leur boutique.</p>

            <div className="mb-5 rounded-xl border border-gray-200 p-4 text-sm dark:border-gray-800">
                {audience === null ? (
                    <p className="text-gray-500 dark:text-gray-400">Calcul de l'audience…</p>
                ) : (
                    <>
                        <p className="text-gray-800 dark:text-white/90">
                            <b>{plural(audience.recipients, 'message')}</b> {audience.recipients > 1 ? 'seront envoyés' : 'sera envoyé'} à{' '}
                            <b>{plural(audience.reachable_customers, 'client')}</b>
                            {audience.stores.length > 0 && <> depuis {audience.stores.join(', ')}</>}.
                        </p>
                        {excluded > 0 && (
                            <p className="mt-2 rounded-lg bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:bg-warning-500/15 dark:text-warning-400">
                                {plural(excluded, 'client sélectionné', 'clients sélectionnés')} sans opt-in dans ces boutiques{' '}
                                {excluded > 1 ? 'sont exclus' : 'est exclu'} automatiquement (consentement WhatsApp obligatoire).
                            </p>
                        )}
                    </>
                )}
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div>
                    <label htmlFor="sc_name" className={label}>
                        Nom de la campagne
                    </label>
                    <input id="sc_name" className={field} value={data.name} onChange={(e) => setData('name', e.target.value)} />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="flex gap-2">
                    {(
                        [
                            ['template', 'Modèle WhatsApp'],
                            ['text', 'Message libre'],
                        ] as const
                    ).map(([value, text]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setData('message_type', value)}
                            className={`flex-1 rounded-lg border px-4 py-2.5 text-sm font-medium transition ${
                                data.message_type === value
                                    ? 'border-brand-500 bg-brand-500 text-white'
                                    : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5'
                            }`}
                        >
                            {text}
                        </button>
                    ))}
                </div>

                {data.message_type === 'template' ? (
                    <div>
                        <label htmlFor="sc_template" className={label}>
                            Modèle approuvé
                        </label>
                        {templates.length === 0 ? (
                            <p className="text-sm text-gray-500 dark:text-gray-400">Aucun modèle actif : créez-en un dans « Modèles de messages ».</p>
                        ) : (
                            <select
                                id="sc_template"
                                className={field}
                                value={data.template_id}
                                onChange={(e) => {
                                    const template = templates.find((t) => String(t.id) === e.target.value);
                                    setData((d) => ({
                                        ...d,
                                        template_id: e.target.value,
                                        template_name: template?.name ?? '',
                                        template_variables: [],
                                        header_image: null,
                                        header_image_url: '',
                                    }));
                                }}
                            >
                                {templates.map((t) => (
                                    <option key={t.id} value={t.id}>
                                        {t.name} ({t.language}
                                        {t.category ? ` · ${t.category}` : ''})
                                    </option>
                                ))}
                            </select>
                        )}
                        {selectedTemplate?.category === 'marketing' && (
                            <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">Modèle marketing : envoyé uniquement aux clients opt-in.</p>
                        )}
                        {errors.template_name && <p className="mt-1.5 text-sm text-error-500">{errors.template_name}</p>}
                        <div className="mt-4">
                            <TemplateParams
                                template={selectedTemplate}
                                variables={data.template_variables}
                                onVariablesChange={(v) => setData('template_variables', v)}
                                image={data.header_image}
                                onImageChange={(file) => setData('header_image', file)}
                                imageUrl={data.header_image_url}
                                onImageUrlChange={(url) => setData('header_image_url', url)}
                                error={errors.template_variables ?? errors.header_image ?? errors.header_image_url}
                            />
                        </div>
                    </div>
                ) : (
                    <div>
                        <label htmlFor="sc_text" className={label}>
                            Message
                        </label>
                        <textarea id="sc_text" rows={4} className={field} value={data.text_body} onChange={(e) => setData('text_body', e.target.value)} />
                        <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                            WhatsApp n'accepte un message libre que si le client a écrit à la boutique dans les dernières 24 h ; sinon, utilisez un modèle.
                        </p>
                        {errors.text_body && <p className="mt-1.5 text-sm text-error-500">{errors.text_body}</p>}
                    </div>
                )}

                {audience && audience.recipients > 0 && (
                    <CostEstimate count={audience.recipients} category={selectedTemplate?.category} isText={data.message_type === 'text'} />
                )}

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button
                        disabled={
                            processing ||
                            !data.name ||
                            !audience ||
                            audience.recipients === 0 ||
                            (data.message_type === 'template'
                                ? !templateParamsReady(selectedTemplate, data.template_variables, data.header_image, data.header_image_url)
                                : !data.text_body.trim())
                        }
                    >
                        {processing ? 'Lancement…' : audience ? `Lancer l'envoi (${plural(audience.recipients, 'message')})` : "Lancer l'envoi"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
