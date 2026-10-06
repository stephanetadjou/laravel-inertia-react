import { useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect, useState } from 'react';

import { Modal } from '../../components/ui/modal';
import Button from '../../components/ui/button/Button';
import TagMultiSelect from '../../components/ui/TagMultiSelect';
import { STAGES, STAGE_ORDER } from '../../components/dashboard/stages';

interface StoreOption {
    id: number;
    name: string;
}

interface GroupOption {
    id: number;
    name: string;
}

interface TemplateOption {
    id: number;
    name: string;
    language: string;
}

interface CreateCampaignModalProps {
    isOpen: boolean;
    onClose: () => void;
    stores: StoreOption[];
    groups: GroupOption[];
    templates: TemplateOption[];
    /** Groupes présélectionnés (ex. « Créer une campagne » depuis la page Groupes). */
    initialGroupIds?: string[];
}

function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function toggleButtonClasses(active: boolean) {
    return `flex-1 rounded-lg border px-4 py-2.5 text-sm font-medium transition ${
        active
            ? 'border-brand-500 bg-brand-500 text-white'
            : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300'
    }`;
}

export default function CreateCampaignModal({ isOpen, onClose, stores, groups, templates, initialGroupIds = [] }: CreateCampaignModalProps) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        description: string;
        contact_source: 'segment' | 'file';
        store_ids: string[];
        group_ids: string[];
        import_store_id: string;
        file: File | null;
        confirm_consent: boolean;
        message_type: 'template' | 'text';
        template_name: string;
        text_body: string;
        gender: string;
        lifecycle_stages: string[];
        vip_only: boolean;
    }>({
        name: '',
        description: '',
        contact_source: 'segment',
        store_ids: stores[0] ? [stores[0].id.toString()] : [],
        group_ids: initialGroupIds,
        import_store_id: stores[0] ? stores[0].id.toString() : '',
        file: null,
        confirm_consent: false,
        message_type: 'template',
        template_name: templates[0]?.name ?? '',
        text_body: '',
        gender: '',
        lifecycle_stages: [],
        vip_only: false,
    });

    const [segmentCount, setSegmentCount] = useState<number | null>(null);
    const [countLoading, setCountLoading] = useState(false);

    useEffect(() => {
        if (!isOpen || data.contact_source !== 'segment' || data.store_ids.length === 0) {
            setSegmentCount(null);
            return;
        }

        setCountLoading(true);
        const params = new URLSearchParams();
        data.store_ids.forEach((id) => params.append('store_ids[]', id));
        data.group_ids.forEach((id) => params.append('group_ids[]', id));
        if (data.gender) params.set('gender', data.gender);
        data.lifecycle_stages.forEach((stage) => params.append('lifecycle_stages[]', stage));
        if (data.vip_only) params.set('vip_only', '1');

        const controller = new AbortController();
        const timeout = setTimeout(() => {
            fetch(`/campaigns/segment-count?${params.toString()}`, { signal: controller.signal })
                .then((res) => res.json())
                .then((json) => setSegmentCount(json.count))
                .catch(() => {})
                .finally(() => setCountLoading(false));
        }, 250);

        return () => {
            clearTimeout(timeout);
            controller.abort();
        };
    }, [isOpen, data.contact_source, data.store_ids, data.group_ids, data.gender, data.lifecycle_stages, data.vip_only]);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post('/campaigns', {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                reset();
                setSegmentCount(null);
                onClose();
            },
        });
    };

    const messageReady =
        data.message_type === 'template' ? templates.length > 0 && !!data.template_name : data.text_body.trim().length > 0;

    const canSubmit =
        !processing &&
        messageReady &&
        (data.contact_source === 'segment'
            ? !!segmentCount
            : !!data.import_store_id && !!data.file && data.confirm_consent);

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-4xl p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">Nouvelle campagne</h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Le message part en arrière-plan vers tous les contacts qui correspondent au segment choisi.
            </p>

            <form onSubmit={submit} className="max-h-[85vh] space-y-5 overflow-y-auto pr-1">
                <div className="grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-2">
                    <div>
                        <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Nom de la campagne
                        </label>
                        <input
                            id="name"
                            type="text"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            placeholder="Ex. Vente privée VIP décembre"
                            className={fieldClasses()}
                        />
                        {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                    </div>

                    <div>
                        <label htmlFor="description" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Description (optionnel)
                        </label>
                        <input
                            id="description"
                            type="text"
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder="Objectif de la campagne, notes internes..."
                            className={fieldClasses()}
                        />
                    </div>

                    <div className="lg:col-span-2">
                        <span className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Destinataires</span>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                className={toggleButtonClasses(data.contact_source === 'segment')}
                                onClick={() => setData('contact_source', 'segment')}
                            >
                                Segment existant
                            </button>
                            <button
                                type="button"
                                className={toggleButtonClasses(data.contact_source === 'file')}
                                onClick={() => setData('contact_source', 'file')}
                            >
                                Importer un fichier
                            </button>
                        </div>
                    </div>

                    {data.contact_source === 'segment' ? (
                        <>
                            <div>
                                <label htmlFor="store_ids" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Boutique(s)
                                </label>
                                <TagMultiSelect
                                    id="store_ids"
                                    options={stores.map((store) => ({ value: store.id.toString(), label: store.name }))}
                                    value={data.store_ids}
                                    onChange={(selected) => setData('store_ids', selected)}
                                    placeholder="Choisir une ou plusieurs boutiques..."
                                />
                                <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    Chaque message part via le numéro WhatsApp de la boutique du contact.
                                </p>
                                {errors.store_ids && <p className="mt-1.5 text-sm text-error-500">{errors.store_ids}</p>}
                            </div>

                            <div>
                                <label htmlFor="group_ids" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Groupe(s) — optionnel
                                </label>
                                {groups.length === 0 ? (
                                    <p className="text-xs text-gray-500 dark:text-gray-400">
                                        Aucun groupe créé. Sans groupe, la campagne cible tous les contacts des boutiques choisies.
                                    </p>
                                ) : (
                                    <>
                                        <TagMultiSelect
                                            id="group_ids"
                                            options={groups.map((group) => ({ value: group.id.toString(), label: group.name }))}
                                            value={data.group_ids}
                                            onChange={(selected) => setData('group_ids', selected)}
                                            placeholder="Tous les contacts des boutiques choisies"
                                        />
                                        <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                            Aucun groupe sélectionné = tous les contacts. Plusieurs groupes = union.
                                        </p>
                                    </>
                                )}
                            </div>

                            <div>
                                <label htmlFor="lifecycle_stages" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Étape(s) du parcours client — optionnel
                                </label>
                                <TagMultiSelect
                                    id="lifecycle_stages"
                                    options={STAGE_ORDER.map((stage) => ({ value: stage, label: STAGES[stage].label }))}
                                    value={data.lifecycle_stages}
                                    onChange={(selected) => setData('lifecycle_stages', selected)}
                                    placeholder="Toutes les étapes"
                                />
                                <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    Ex. « À risque » pour une relance. Seuls les contacts opt-in reçoivent la campagne.
                                </p>
                            </div>

                            <label className="flex cursor-pointer items-start gap-3 text-sm text-gray-700 dark:text-gray-300">
                                <input
                                    type="checkbox"
                                    checked={data.vip_only}
                                    onChange={(e) => setData('vip_only', e.target.checked)}
                                    className="mt-0.5 h-4 w-4 accent-brand-500"
                                />
                                <span>
                                    <span className="font-medium">Uniquement les clients VIP de la boutique</span>
                                    <span className="block text-xs text-gray-500 dark:text-gray-400">
                                        Le statut VIP est propre à chaque boutique : un client VIP chez Mango ne l'est pas forcément chez Adidas.
                                    </span>
                                </span>
                            </label>
                        </>
                    ) : (
                        <>
                            <div>
                                <label htmlFor="import_store_id" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Boutique de destination
                                </label>
                                <select
                                    id="import_store_id"
                                    value={data.import_store_id}
                                    onChange={(e) => setData('import_store_id', e.target.value)}
                                    className={fieldClasses()}
                                >
                                    {stores.map((store) => (
                                        <option key={store.id} value={store.id}>
                                            {store.name}
                                        </option>
                                    ))}
                                </select>
                                <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    Les contacts importés sont rattachés à cette boutique (son numéro WhatsApp sert à l'envoi).
                                </p>
                                {errors.import_store_id && <p className="mt-1.5 text-sm text-error-500">{errors.import_store_id}</p>}
                            </div>

                            <div>
                                <label htmlFor="file" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Fichier CSV/Excel (colonnes : nom, telephone, ...)
                                </label>
                                <input
                                    id="file"
                                    type="file"
                                    accept=".csv,.txt,.xlsx,.xls"
                                    onChange={(e) => setData('file', e.target.files?.[0] ?? null)}
                                    className={fieldClasses()}
                                />
                                <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                                    Mêmes colonnes que la page Import. Les contacts déjà existants (même boutique + téléphone) sont mis à jour, pas
                                    dupliqués.
                                </p>
                                {errors.file && <p className="mt-1.5 text-sm text-error-500">{errors.file}</p>}
                            </div>

                            <div className="lg:col-span-2">
                                <label className="flex cursor-pointer items-start gap-2.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 text-sm text-gray-700 dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-300">
                                    <input
                                        type="checkbox"
                                        checked={data.confirm_consent}
                                        onChange={(e) => setData('confirm_consent', e.target.checked)}
                                        className="mt-0.5"
                                    />
                                    <span>
                                        Je confirme que ces contacts ont donné leur consentement (opt-in) pour recevoir des messages WhatsApp de
                                        cette boutique. Obligatoire : seuls les contacts opt_in sont ciblés, les désinscrits (opt_out) déjà connus
                                        ne sont jamais réactivés par un import.
                                    </span>
                                </label>
                                {errors.confirm_consent && <p className="mt-1.5 text-sm text-error-500">{errors.confirm_consent}</p>}
                            </div>
                        </>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-x-8 gap-y-5 border-t border-gray-100 pt-5 lg:grid-cols-2 dark:border-gray-800">
                    <div className="space-y-5">
                        <div>
                            <span className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">Type de message</span>
                            <div className="flex gap-2">
                                <button
                                    type="button"
                                    className={toggleButtonClasses(data.message_type === 'template')}
                                    onClick={() => setData('message_type', 'template')}
                                >
                                    Utiliser un template
                                </button>
                                <button
                                    type="button"
                                    className={toggleButtonClasses(data.message_type === 'text')}
                                    onClick={() => setData('message_type', 'text')}
                                >
                                    Message manuel
                                </button>
                            </div>
                        </div>

                        {data.message_type === 'template' ? (
                            <div>
                                <label htmlFor="template_name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Template WhatsApp approuvé
                                </label>
                                {templates.length === 0 ? (
                                    <p className="text-sm text-error-500">
                                        Aucun template actif enregistré. Ajoutez-en un depuis la page « Templates » avant de lancer une campagne.
                                    </p>
                                ) : (
                                    <select
                                        id="template_name"
                                        value={data.template_name}
                                        onChange={(e) => setData('template_name', e.target.value)}
                                        className={fieldClasses()}
                                    >
                                        {templates.map((template) => (
                                            <option key={template.id} value={template.name}>
                                                {template.name} ({template.language})
                                            </option>
                                        ))}
                                    </select>
                                )}
                                {errors.template_name && <p className="mt-1.5 text-sm text-error-500">{errors.template_name}</p>}
                            </div>
                        ) : (
                            <div>
                                <label htmlFor="text_body" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    Message
                                </label>
                                <textarea
                                    id="text_body"
                                    value={data.text_body}
                                    onChange={(e) => setData('text_body', e.target.value.slice(0, 4096))}
                                    rows={3}
                                    placeholder="Tapez votre message WhatsApp ici..."
                                    className={fieldClasses()}
                                />
                                <div className="mt-1 flex items-center justify-between">
                                    <span className="text-xs text-gray-400">{data.text_body.length}/4096</span>
                                </div>
                                <div className="mt-2 rounded-lg border border-warning-500/40 bg-warning-50 px-3 py-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                                    ⚠️ Livrable uniquement aux contacts ayant écrit à la boutique dans les dernières 24h (fenêtre de service
                                    WhatsApp, non suivie ici). Préférez un template pour une diffusion large.
                                </div>
                                {errors.text_body && <p className="mt-1.5 text-sm text-error-500">{errors.text_body}</p>}
                            </div>
                        )}
                    </div>

                    <div className="space-y-5">
                        {data.contact_source === 'segment' ? (
                            <>
                                <div>
                                    <label htmlFor="gender" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                                        Segment — sexe
                                    </label>
                                    <select
                                        id="gender"
                                        value={data.gender}
                                        onChange={(e) => setData('gender', e.target.value)}
                                        className={fieldClasses()}
                                    >
                                        <option value="">Tous</option>
                                        <option value="H">Homme</option>
                                        <option value="F">Femme</option>
                                    </select>
                                </div>
                                <p className="-mt-3 text-xs text-gray-500 dark:text-gray-400">
                                    Consentement opt_in obligatoire : les contacts opt_out et unknown sont automatiquement exclus de tout envoi
                                    (règle non désactivable).
                                </p>

                                <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm dark:border-gray-700 dark:bg-white/[0.02]">
                                    {countLoading ? (
                                        <span className="text-gray-500 dark:text-gray-400">Calcul du segment...</span>
                                    ) : segmentCount === null ? (
                                        <span className="text-gray-500 dark:text-gray-400">
                                            Choisissez une boutique pour voir le nombre de destinataires.
                                        </span>
                                    ) : segmentCount === 0 ? (
                                        <span className="text-error-500">Aucun contact ne correspond à ce segment.</span>
                                    ) : (
                                        <span className="font-medium text-gray-800 dark:text-white/90">
                                            {segmentCount} contact{segmentCount > 1 ? 's' : ''} recevront cette campagne
                                        </span>
                                    )}
                                </div>
                            </>
                        ) : (
                            <div className="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-4 py-3 text-sm text-gray-600 dark:border-gray-700 dark:bg-white/[0.02] dark:text-gray-400">
                                Le fichier sera importé, puis la campagne partira vers les contacts qu'il contient (nombre connu après envoi du
                                formulaire). Grâce à la case cochée ci-contre, les nouveaux contacts passent en opt_in ; un contact déjà désinscrit
                                (opt_out) n'est jamais réactivé par un import.
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={!canSubmit}>{processing ? 'Lancement...' : 'Lancer la campagne'}</Button>
                </div>
            </form>
        </Modal>
    );
}
