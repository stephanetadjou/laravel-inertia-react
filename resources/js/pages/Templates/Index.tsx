import { router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useMemo, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { Empty, Page, Panel, PlusIcon, SearchFilter, Seg, SelectFilter, StatStrip, formatDay } from '../../components/page/Page';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';

interface TemplateRow {
    id: number;
    name: string;
    language: string;
    category: string;
    description: string | null;
    is_active: boolean;
    campaigns_count: number;
    last_used_at: string | null;
}

interface PageProps {
    templates: TemplateRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}


function fieldClasses() {
    return 'w-full rounded-lg border border-gray-300 px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
}

function TemplateFormModal({ isOpen, onClose, template }: { isOpen: boolean; onClose: () => void; template: TemplateRow | null }) {
    const isEditing = template !== null;

    const { data, setData, post, put, processing, errors, reset } = useForm({
        name: template?.name ?? '',
        language: template?.language ?? 'fr',
        category: template?.category ?? 'utility',
        description: template?.description ?? '',
        is_active: template?.is_active ?? true,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (isEditing) {
            put(`/templates/${template.id}`, options);
        } else {
            post('/templates', options);
        }
    };

    return (
        <Modal isOpen={isOpen} onClose={onClose} className="max-w-lg p-6">
            <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
                {isEditing ? 'Modifier le modèle' : 'Nouveau modèle'}
            </h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Le nom et la langue doivent correspondre exactement à un modèle déjà approuvé dans Meta Business Manager.
            </p>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom du modèle (exact, tel qu'approuvé sur Meta)
                    </label>
                    <input
                        id="name"
                        type="text"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        placeholder="ex. hello_world"
                        className={fieldClasses()}
                    />
                    {errors.name && <p className="mt-1.5 text-sm text-error-500">{errors.name}</p>}
                </div>

                <div className="grid grid-cols-2 gap-4">
                    <div>
                        <label htmlFor="language" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Langue
                        </label>
                        <input
                            id="language"
                            type="text"
                            value={data.language}
                            onChange={(e) => setData('language', e.target.value)}
                            placeholder="fr, en_US..."
                            className={fieldClasses()}
                        />
                        {errors.language && <p className="mt-1.5 text-sm text-error-500">{errors.language}</p>}
                    </div>

                    <div>
                        <label htmlFor="category" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                            Catégorie Meta
                        </label>
                        <select id="category" value={data.category} onChange={(e) => setData('category', e.target.value)} className={fieldClasses()}>
                            <option value="utility">Utilitaire</option>
                            <option value="marketing">Marketing</option>
                            <option value="authentication">Authentification</option>
                            <option value="service">Service</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label htmlFor="description" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Description (optionnel)
                    </label>
                    <textarea
                        id="description"
                        value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={2}
                        placeholder="Contenu ou usage prévu du modèle..."
                        className={fieldClasses()}
                    />
                </div>

                <label className="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" checked={data.is_active} onChange={(e) => setData('is_active', e.target.checked)} />
                    Actif (proposé lors de la création d'une campagne)
                </label>

                <div className="flex justify-end gap-2 border-t border-gray-100 pt-4 dark:border-gray-800">
                    <Button type="button" variant="outline" onClick={onClose}>
                        Annuler
                    </Button>
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Mettre à jour' : 'Créer le modèle'}</Button>
                </div>
            </form>
        </Modal>
    );
}

const CATEGORY: Record<string, { label: string; pill: string; hint: string }> = {
    utility: { label: 'Utilitaire', pill: 'info', hint: 'Bienvenue, confirmations : seul type envoyable à un contact au consentement inconnu' },
    marketing: { label: 'Marketing', pill: 'accent', hint: 'Promotions : réservé aux contacts opt-in' },
    authentication: { label: 'Authentification', pill: 'idle', hint: 'Codes de vérification' },
    service: { label: 'Service', pill: 'ok', hint: 'Réponses au service client' },
};

function TemplatesIndex() {
    const { templates } = usePage<PageProps>().props;
    const [modalTemplate, setModalTemplate] = useState<TemplateRow | null | undefined>(undefined);
    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<string | null>(null);
    const [language, setLanguage] = useState<string | null>(null);
    const [state, setState] = useState<'all' | 'active' | 'inactive'>('all');

    const languages = useMemo(() => Array.from(new Set(templates.map((t) => t.language))).sort(), [templates]);
    const visible = templates.filter((t) => {
        const q = search.trim().toLowerCase();
        return (
            (!q || t.name.toLowerCase().includes(q) || (t.description ?? '').toLowerCase().includes(q)) &&
            (!category || t.category === category) &&
            (!language || t.language === language) &&
            (state === 'all' || (state === 'active') === t.is_active)
        );
    });
    const filtered = Boolean(search || category || language || state !== 'all');

    function destroy(template: TemplateRow) {
        if (confirm(`Supprimer le modèle « ${template.name} » ? Les campagnes déjà envoyées ne sont pas affectées.`)) {
            router.delete(`/templates/${template.id}`, { preserveScroll: true });
        }
    }

    return (
        <Page
            title="Modèles de messages"
            subtitle="Modèles approuvés dans Meta Business Manager, utilisés pour les campagnes et le message de bienvenue"
            actions={
                <button type="button" className="mdash-btn brand" onClick={() => setModalTemplate(null)}>
                    <PlusIcon />
                    Nouveau modèle
                </button>
            }
            filters={
                <>
                    <SearchFilter value={search} placeholder="Nom ou description" onSearch={setSearch} />
                    <SelectFilter
                        label="Catégorie"
                        value={category}
                        allLabel="Toutes"
                        options={Object.entries(CATEGORY).map(([value, c]) => ({ value, label: c.label }))}
                        onChange={setCategory}
                    />
                    {languages.length > 1 && (
                        <SelectFilter label="Langue" value={language} allLabel="Toutes" options={languages.map((l) => ({ value: l, label: l }))} onChange={setLanguage} />
                    )}
                    <Seg
                        label="État"
                        value={state}
                        options={[
                            { key: 'all', label: 'Tous' },
                            { key: 'active', label: 'Actifs' },
                            { key: 'inactive', label: 'Inactifs' },
                        ]}
                        onChange={setState}
                    />
                </>
            }
        >
            <StatStrip
                items={[
                    { label: 'Modèles', value: templates.length },
                    { label: 'Actifs', value: templates.filter((t) => t.is_active).length, tone: 'ok' },
                    { label: 'Marketing', value: templates.filter((t) => t.category === 'marketing').length },
                    { label: 'Utilitaires', value: templates.filter((t) => t.category === 'utility').length, hint: 'pour la bienvenue' },
                ]}
            />
            <div className="mpage-note">
                Le nom et la langue doivent correspondre <b>exactement</b> au modèle approuvé chez Meta. Les modèles <b>Marketing</b> ne partent
                qu'aux contacts opt-in ; un modèle <b>Utilitaire</b> actif est nécessaire pour envoyer le message de bienvenue.
            </div>

            <Panel>
                {visible.length === 0 ? (
                    <Empty title={filtered ? 'Aucun modèle ne correspond aux filtres' : 'Aucun modèle pour l’instant'}>
                        {filtered ? 'Modifiez les filtres.' : 'Ajoutez un modèle déjà approuvé dans Meta Business Manager (ex. hello_world).'}
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Modèle</th>
                                    <th>Langue</th>
                                    <th>Catégorie</th>
                                    <th>État</th>
                                    <th className="r">Campagnes</th>
                                    <th className="r">Dernière utilisation</th>
                                    <th className="r">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((t) => (
                                    <tr key={t.id}>
                                        <td className="cell-main wrap">
                                            <b className="mono">{t.name}</b>
                                            {t.description && <span>{t.description}</span>}
                                        </td>
                                        <td className="mono">{t.language}</td>
                                        <td>
                                            <span className={`mdash-pill ${CATEGORY[t.category]?.pill ?? 'idle'}`} title={CATEGORY[t.category]?.hint}>
                                                {CATEGORY[t.category]?.label ?? t.category}
                                            </span>
                                        </td>
                                        <td>{t.is_active ? <span className="mdash-pill ok">Actif</span> : <span className="mdash-pill idle">Inactif</span>}</td>
                                        <td className="r num">{t.campaigns_count}</td>
                                        <td className="r mono">{formatDay(t.last_used_at)}</td>
                                        <td className="r">
                                            <span className="mpage-rowlinks">
                                                <button type="button" onClick={() => setModalTemplate(t)}>
                                                    Modifier
                                                </button>
                                                <button type="button" className="danger" onClick={() => destroy(t)}>
                                                    Supprimer
                                                </button>
                                            </span>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>

            {modalTemplate !== undefined && (
                <TemplateFormModal key={modalTemplate?.id ?? 'new'} isOpen={true} onClose={() => setModalTemplate(undefined)} template={modalTemplate} />
            )}
        </Page>
    );
}

TemplatesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default TemplatesIndex;
