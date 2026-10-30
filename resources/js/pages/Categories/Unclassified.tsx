import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import AppLayout from '../../layout/AppLayout';
import { formatNumber } from '../../components/dashboard/Charts';
import { Empty, Page, Panel, SearchFilter, StatStrip } from '../../components/page/Page';
import { capitalize, money } from '../../components/families/families';
import './families.css';

interface Label {
    key: string;
    label: string;
    count: number;
    amount: number;
    examples: string[];
}

interface PageProps {
    labels: Label[];
    categories: { id: number; name: string }[];
    familyWord: string;
    [key: string]: unknown;
}

function Unclassified() {
    const { labels, categories, familyWord } = usePage<PageProps>().props;
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [target, setTarget] = useState('');
    const [newName, setNewName] = useState('');
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const plural = familyWord === 'famille' ? 'familles' : 'catégories';

    const q = search.trim().toLowerCase();
    const visible = labels.filter((l) => !q || l.label.toLowerCase().includes(q) || l.examples.some((e) => e.toLowerCase().includes(q)));
    const allVisibleSelected = visible.length > 0 && visible.every((l) => selected.has(l.label));
    const total = labels.reduce((n, l) => n + l.amount, 0);

    function toggle(label: string) {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(label)) next.delete(label);
            else next.add(label);
            return next;
        });
    }

    function toggleVisible() {
        setSelected((prev) => {
            const next = new Set(prev);
            visible.forEach((l) => (allVisibleSelected ? next.delete(l.label) : next.add(l.label)));
            return next;
        });
    }

    function submit(mode: 'existing' | 'new' | 'each') {
        const payload = { labels: [...selected], mode, category_id: mode === 'existing' ? Number(target) : null, name: mode === 'new' ? newName.trim() : null };
        setBusy(true);
        router.post('/categories/a-classer', payload, {
            preserveScroll: true,
            onSuccess: () => {
                setSelected(new Set());
                setTarget('');
                setNewName('');
            },
            onFinish: () => setBusy(false),
        });
    }

    return (
        <Page
            title="À classer"
            subtitle={
                <>
                    <Link href="/categories" className="mdash-link">
                        {capitalize(plural)}
                    </Link>{' '}
                    › libellés importés qui ne correspondent à aucune {familyWord}
                </>
            }
            filters={labels.length > 10 ? <SearchFilter value={search} placeholder="Libellé ou article" onSearch={setSearch} /> : undefined}
        >
            <StatStrip
                items={[
                    { label: 'Libellés à classer', value: labels.length },
                    { label: 'Ventes concernées', value: labels.reduce((n, l) => n + l.count, 0) },
                    { label: "Chiffre d'affaires", value: money(total) },
                ]}
            />
            <div className="mpage-note">
                Cochez des libellés puis rangez-les dans une {familyWord}. Menentia retient la correspondance : les prochains imports les classeront tout seuls. Pour un
                classement encore plus automatique, ajoutez des mots-clés à vos {plural} (« coca, fanta » pour Boissons).
            </div>

            <Panel title="Libellés" sub="Les plus gros chiffres d’affaires d’abord">
                {selected.size > 0 && (
                    <div className="mpage-selbar">
                        <b>{formatNumber(selected.size)} sélectionné(s)</b>
                        {categories.length > 0 && (
                            <>
                                <select aria-label={`Ranger dans une ${familyWord}`} value={target} onChange={(e) => setTarget(e.target.value)}>
                                    <option value="">Ranger dans…</option>
                                    {categories.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                                <button type="button" className="mdash-btn brand" disabled={!target || busy} onClick={() => submit('existing')}>
                                    Ranger
                                </button>
                            </>
                        )}
                        <input type="text" aria-label={`Nouvelle ${familyWord}`} placeholder={`ou nouvelle ${familyWord}…`} value={newName} onChange={(e) => setNewName(e.target.value)} />
                        <button type="button" className="mdash-btn ghost" disabled={!newName.trim() || busy} onClick={() => submit('new')}>
                            Créer et ranger
                        </button>
                        <button
                            type="button"
                            className="mdash-btn ghost"
                            disabled={busy}
                            title={`Chaque libellé devient sa propre ${familyWord} (fichier déjà bien rangé)`}
                            onClick={() => submit('each')}
                        >
                            Une {familyWord} par libellé
                        </button>
                    </div>
                )}

                {visible.length === 0 ? (
                    <Empty title={labels.length === 0 ? 'Tout est classé' : 'Aucun libellé ne correspond à la recherche'}>
                        {labels.length === 0 && `Toutes vos ventes importées sont rangées dans une ${familyWord}.`}
                    </Empty>
                ) : (
                    <div className="mdash-tbl mpage-table">
                        <table>
                            <thead>
                                <tr>
                                    <th className="mpage-check">
                                        <input type="checkbox" aria-label="Tout cocher" checked={allVisibleSelected} onChange={toggleVisible} />
                                    </th>
                                    <th>Libellé du fichier</th>
                                    <th>Exemples d’articles</th>
                                    <th className="r">Ventes</th>
                                    <th className="r">CA</th>
                                </tr>
                            </thead>
                            <tbody>
                                {visible.map((l) => (
                                    <tr key={l.key}>
                                        <td className="mpage-check">
                                            <input type="checkbox" aria-label={`Choisir ${l.label}`} checked={selected.has(l.label)} onChange={() => toggle(l.label)} />
                                        </td>
                                        <td className="cell-main">
                                            <b>{l.label}</b>
                                        </td>
                                        <td className="wrap">
                                            <span className="mpage-muted">{l.examples.filter((e) => e !== l.label).join(', ') || '—'}</span>
                                        </td>
                                        <td className="r num">{formatNumber(l.count)}</td>
                                        <td className="r num">{money(l.amount)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </Panel>
        </Page>
    );
}

Unclassified.layout = (page: React.ReactNode) => <AppLayout>{page}</AppLayout>;

export default Unclassified;
