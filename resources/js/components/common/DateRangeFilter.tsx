import { useEffect, useRef, useState } from 'react';

interface DateRangeFilterProps {
    from?: string;
    to?: string;
    onApply: (from: string, to: string) => void;
    onClear: () => void;
}

function toIsoDate(date: Date): string {
    return date.toISOString().slice(0, 10);
}

function startOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth(), 1);
}

function endOfMonth(date: Date): Date {
    return new Date(date.getFullYear(), date.getMonth() + 1, 0);
}

function addDays(date: Date, days: number): Date {
    const copy = new Date(date);
    copy.setDate(copy.getDate() + days);

    return copy;
}

function addMonths(date: Date, months: number): Date {
    const copy = new Date(date);
    copy.setMonth(copy.getMonth() + months);

    return copy;
}

function formatLabel(iso: string): string {
    const [year, month, day] = iso.split('-');

    return `${day}/${month}/${year}`;
}

const presets: { label: string; range: () => [Date, Date] }[] = [
    { label: 'Hier', range: () => [addDays(new Date(), -1), addDays(new Date(), -1)] },
    { label: '7 derniers jours', range: () => [addDays(new Date(), -6), new Date()] },
    { label: '30 derniers jours', range: () => [addDays(new Date(), -29), new Date()] },
    { label: 'Le mois en cours', range: () => [startOfMonth(new Date()), new Date()] },
    { label: 'Le mois dernier', range: () => [startOfMonth(addMonths(new Date(), -1)), endOfMonth(addMonths(new Date(), -1))] },
    { label: '3 derniers mois', range: () => [addMonths(new Date(), -3), new Date()] },
    { label: '12 derniers mois', range: () => [addMonths(new Date(), -12), new Date()] },
];

export default function DateRangeFilter({ from, to, onApply, onClear }: DateRangeFilterProps) {
    const [isOpen, setIsOpen] = useState(false);
    const [draftFrom, setDraftFrom] = useState(from ?? '');
    const [draftTo, setDraftTo] = useState(to ?? '');
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        setDraftFrom(from ?? '');
        setDraftTo(to ?? '');
    }, [from, to]);

    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);

        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    function applyPreset(range: () => [Date, Date]) {
        const [start, end] = range();
        setDraftFrom(toIsoDate(start));
        setDraftTo(toIsoDate(end));
    }

    function confirm() {
        if (draftFrom && draftTo) {
            onApply(draftFrom, draftTo);
            setIsOpen(false);
        }
    }

    function clear() {
        setDraftFrom('');
        setDraftTo('');
        onClear();
        setIsOpen(false);
    }

    const label = from && to ? `${formatLabel(from)} - ${formatLabel(to)}` : 'Toutes les dates';

    return (
        <div ref={containerRef} className="relative">
            <button
                type="button"
                onClick={() => setIsOpen((prev) => !prev)}
                className="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:bg-white/[0.03] dark:text-gray-300"
            >
                <svg className="h-4 w-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                    <path strokeLinecap="round" strokeLinejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                </svg>
                {label}
                {from && to && (
                    <span
                        role="button"
                        tabIndex={-1}
                        onClick={(e) => {
                            e.stopPropagation();
                            clear();
                        }}
                        className="ml-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
                    >
                        ×
                    </span>
                )}
            </button>

            {isOpen && (
                <div className="absolute z-30 mt-2 flex w-[420px] overflow-hidden rounded-xl border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    <div className="w-44 shrink-0 border-r border-gray-100 py-2 dark:border-gray-800">
                        {presets.map((preset) => (
                            <button
                                key={preset.label}
                                type="button"
                                onClick={() => applyPreset(preset.range)}
                                className="block w-full px-4 py-2 text-left text-sm text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                            >
                                {preset.label}
                            </button>
                        ))}
                    </div>
                    <div className="flex-1 p-4">
                        <p className="mb-2 text-xs font-medium text-gray-500 dark:text-gray-400">Plage personnalisée</p>
                        <div className="space-y-2">
                            <div>
                                <label className="mb-1 block text-xs text-gray-500 dark:text-gray-400">Du</label>
                                <input
                                    type="date"
                                    value={draftFrom}
                                    onChange={(e) => setDraftFrom(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                                />
                            </div>
                            <div>
                                <label className="mb-1 block text-xs text-gray-500 dark:text-gray-400">Au</label>
                                <input
                                    type="date"
                                    value={draftTo}
                                    onChange={(e) => setDraftTo(e.target.value)}
                                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-800 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03] dark:text-white/90"
                                />
                            </div>
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={clear}
                                className="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
                            >
                                Réinitialiser
                            </button>
                            <button
                                type="button"
                                onClick={confirm}
                                disabled={!draftFrom || !draftTo}
                                className="rounded-lg bg-brand-500 px-3 py-2 text-sm text-white hover:bg-brand-600 disabled:opacity-50"
                            >
                                Filtrer
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
