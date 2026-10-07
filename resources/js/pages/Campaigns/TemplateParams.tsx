/**
 * Paramètres d'un modèle WhatsApp dans une campagne : une valeur par variable
 * {{n}} du corps (texte fixe ou {prenom}, {nom}, {boutique} remplacés pour
 * chaque contact) et l'image de l'en-tête pour un modèle à en-tête IMAGE.
 * Partagé par la création de campagne et la campagne depuis les fiches clients.
 */
export interface TemplateWithParams {
    id: number;
    name: string;
    language: string;
    body_text?: string | null;
    body_variables?: number;
    header_format?: string | null;
}

interface TemplateParamsProps {
    template: TemplateWithParams | undefined;
    variables: string[];
    onVariablesChange: (variables: string[]) => void;
    image: File | null;
    onImageChange: (file: File | null) => void;
    imageUrl: string;
    onImageUrlChange: (url: string) => void;
    error?: string;
}

const field =
    'w-full rounded-lg border border-gray-300 bg-transparent px-3 py-2.5 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90';
const label = 'mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-300';
const TOKENS = ['{prenom}', '{nom}', '{boutique}'];

/** Les paramètres du modèle sont-ils tous renseignés ? */
export function templateParamsReady(template: TemplateWithParams | undefined, variables: string[], image: File | null, imageUrl: string): boolean {
    if (!template) return false;
    const count = template.body_variables ?? 0;
    const varsOk = Array.from({ length: count }, (_, i) => (variables[i] ?? '').trim() !== '').every(Boolean);
    const imageOk = template.header_format !== 'IMAGE' || image !== null || imageUrl.startsWith('https://');
    return varsOk && imageOk;
}

/** Aperçu du corps avec les valeurs saisies à la place des {{n}}. */
function preview(body: string, variables: string[]): string {
    return body.replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n) => variables[Number(n) - 1]?.trim() || `{{${n}}}`);
}

export default function TemplateParams({ template, variables, onVariablesChange, image, onImageChange, imageUrl, onImageUrlChange, error }: TemplateParamsProps) {
    if (!template) return null;

    const count = template.body_variables ?? 0;
    const setVariable = (index: number, value: string) => {
        const next = Array.from({ length: count }, (_, i) => variables[i] ?? '');
        next[index] = value;
        onVariablesChange(next);
    };

    if (count === 0 && template.header_format !== 'IMAGE' && !template.body_text) return null;

    return (
        <div className="space-y-4 rounded-xl border border-gray-200 p-4 dark:border-gray-800">
            {template.body_text && (
                <div>
                    <span className={label}>Aperçu</span>
                    <p className="rounded-lg bg-gray-50 px-3 py-2 text-sm whitespace-pre-line text-gray-700 dark:bg-white/[0.04] dark:text-gray-300">
                        {preview(template.body_text, variables)}
                    </p>
                </div>
            )}

            {Array.from({ length: count }, (_, i) => (
                <div key={i}>
                    <label htmlFor={`tpl_var_${i}`} className={label}>
                        Variable {`{{${i + 1}}}`}
                    </label>
                    <input id={`tpl_var_${i}`} className={field} value={variables[i] ?? ''} onChange={(e) => setVariable(i, e.target.value)} />
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                        {TOKENS.map((token) => (
                            <button
                                key={token}
                                type="button"
                                className="rounded-md border border-gray-200 px-2 py-0.5 font-mono text-xs text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-white/5"
                                onClick={() => setVariable(i, `${variables[i] ?? ''}${token}`)}
                            >
                                {token}
                            </button>
                        ))}
                    </div>
                </div>
            ))}
            {count > 0 && (
                <p className="text-xs text-gray-500 dark:text-gray-400">{'{prenom}'}, {'{nom}'} et {'{boutique}'} sont remplacés pour chaque client.</p>
            )}

            {template.header_format === 'IMAGE' && (
                <div>
                    <span className={label}>Image de l'en-tête</span>
                    <input
                        type="file"
                        accept="image/jpeg,image/png"
                        aria-label="Image de l'en-tête (JPG ou PNG, 5 Mo max.)"
                        onChange={(e) => onImageChange(e.target.files?.[0] ?? null)}
                        className="block w-full text-sm text-gray-600 file:mr-3 file:rounded-lg file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm dark:text-gray-400 dark:file:bg-white/10"
                    />
                    {!image && (
                        <input
                            type="url"
                            aria-label="Ou adresse https de l'image"
                            placeholder="ou https://… (image publique)"
                            className={`${field} mt-2`}
                            value={imageUrl}
                            onChange={(e) => onImageUrlChange(e.target.value)}
                        />
                    )}
                    <p className="mt-1.5 text-xs text-gray-500 dark:text-gray-400">JPG ou PNG, 5 Mo maximum.</p>
                </div>
            )}

            {error && <p className="text-sm text-error-500">{error}</p>}
        </div>
    );
}
