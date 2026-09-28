import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import PageBreadcrumb from '../../components/common/PageBreadCrumb';
import ComponentCard from '../../components/common/ComponentCard';
import Alert from '../../components/ui/alert/Alert';
import Badge from '../../components/ui/badge/Badge';
import Button from '../../components/ui/button/Button';
import { Modal } from '../../components/ui/modal';
import { Table, TableBody, TableCell, TableHeader, TableRow } from '../../components/ui/table';

interface TemplateRow {
    id: number;
    name: string;
    language: string;
    category: string;
    description: string | null;
    is_active: boolean;
}

interface PageProps {
    templates: TemplateRow[];
    flash?: {
        success: string | null;
        error: string | null;
    };
    [key: string]: unknown;
}

const categoryColor: Record<string, 'info' | 'warning' | 'light' | 'success'> = {
    utility: 'info',
    marketing: 'warning',
    authentication: 'light',
    service: 'success',
};

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
                {isEditing ? 'Modifier le template' : 'Nouveau template'}
            </h3>
            <p className="mb-6 text-sm text-gray-500 dark:text-gray-400">
                Le nom et la langue doivent correspondre exactement à un template déjà approuvé dans Meta Business Manager.
            </p>

            <form onSubmit={submit} className="space-y-5">
                <div>
                    <label htmlFor="name" className="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300">
                        Nom du template (exact, tel qu'approuvé sur Meta)
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
                            <option value="utility">Utility</option>
                            <option value="marketing">Marketing</option>
                            <option value="authentication">Authentication</option>
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
                        placeholder="Contenu ou usage prévu du template..."
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
                    <Button disabled={processing}>{processing ? 'Enregistrement...' : isEditing ? 'Mettre à jour' : 'Créer le template'}</Button>
                </div>
            </form>
        </Modal>
    );
}

function TemplatesIndex() {
    const { templates, flash } = usePage<PageProps>().props;
    const success = flash?.success ?? null;
    const error = flash?.error ?? null;

    const [modalTemplate, setModalTemplate] = useState<TemplateRow | null | undefined>(undefined);

    function destroy(template: TemplateRow) {
        if (confirm(`Supprimer le template « ${template.name} » ? Les campagnes déjà envoyées ne sont pas affectées.`)) {
            router.delete(`/templates/${template.id}`, { preserveScroll: true });
        }
    }

    return (
        <>
            <Head title="Templates" />

            <PageBreadcrumb pageTitle="Templates" />

            <div className="space-y-4">
                {success && <Alert variant="success" title="Succès" message={success} />}
                {error && <Alert variant="error" title="Erreur" message={error} />}

                <div className="flex justify-end">
                    <Button type="button" onClick={() => setModalTemplate(null)}>
                        Nouveau template
                    </Button>
                </div>

                <ComponentCard
                    title="Templates WhatsApp"
                    desc="Registre local des templates déjà approuvés sur Meta Business Manager. Enregistrer un template ici ne le fait pas approuver — il doit déjà l'être côté Meta."
                >
                    <div className="overflow-x-auto">
                        <Table>
                            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
                                <TableRow>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Nom
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Langue
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Catégorie
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase dark:text-gray-400">
                                        Statut
                                    </TableCell>
                                    <TableCell isHeader className="px-4 py-3" />
                                </TableRow>
                            </TableHeader>
                            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
                                {templates.length === 0 && (
                                    <TableRow>
                                        <TableCell className="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400" colSpan={5}>
                                            Aucun template enregistré. Ajoutez au moins un template approuvé pour pouvoir lancer une campagne.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {templates.map((template) => (
                                    <TableRow key={template.id}>
                                        <TableCell className="px-4 py-3 text-sm font-medium text-gray-800 dark:text-white/90">
                                            {template.name}
                                            {template.description && (
                                                <p className="mt-0.5 text-xs font-normal text-gray-400">{template.description}</p>
                                            )}
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">{template.language}</TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            <Badge size="sm" color={categoryColor[template.category] ?? 'light'}>
                                                {template.category}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-sm">
                                            <Badge size="sm" color={template.is_active ? 'success' : 'light'}>
                                                {template.is_active ? 'actif' : 'inactif'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="px-4 py-3 text-right whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() => setModalTemplate(template)}
                                                className="mr-3 text-sm font-medium text-brand-500 hover:text-brand-600"
                                            >
                                                Modifier
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => destroy(template)}
                                                className="text-sm font-medium text-error-500 hover:text-error-600"
                                            >
                                                Supprimer
                                            </button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </ComponentCard>
            </div>

            {modalTemplate !== undefined && (
                <TemplateFormModal isOpen={true} onClose={() => setModalTemplate(undefined)} template={modalTemplate} />
            )}
        </>
    );
}

TemplatesIndex.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default TemplatesIndex;
