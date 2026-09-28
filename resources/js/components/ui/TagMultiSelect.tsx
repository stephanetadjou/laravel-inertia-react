import { useEffect, useRef, useState } from 'react';

import { AngleDownIcon, AngleUpIcon, CloseIcon } from '../../icons';

export interface TagMultiSelectOption {
    value: string;
    label: string;
}

interface TagMultiSelectProps {
    id?: string;
    options: TagMultiSelectOption[];
    value: string[];
    onChange: (value: string[]) => void;
    placeholder?: string;
}

export default function TagMultiSelect({ id, options, value, onChange, placeholder = 'Sélectionner...' }: TagMultiSelectProps) {
    const [isOpen, setIsOpen] = useState(false);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (containerRef.current && !containerRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const selectedOptions = value.map((v) => options.find((o) => o.value === v)).filter((o): o is TagMultiSelectOption => !!o);
    const availableOptions = options.filter((o) => !value.includes(o.value));

    function remove(optionValue: string) {
        onChange(value.filter((v) => v !== optionValue));
    }

    function add(optionValue: string) {
        onChange([...value, optionValue]);
    }

    return (
        <div ref={containerRef} className="relative">
            <div
                id={id}
                role="button"
                tabIndex={0}
                onClick={() => setIsOpen((prev) => !prev)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        setIsOpen((prev) => !prev);
                    }
                }}
                className="flex min-h-11.5 w-full cursor-pointer flex-wrap items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 focus:border-brand-300 focus:outline-hidden dark:border-gray-700 dark:bg-white/[0.03]"
            >
                {selectedOptions.length === 0 && <span className="text-sm text-gray-400 dark:text-gray-500">{placeholder}</span>}
                {selectedOptions.map((option) => (
                    <span
                        key={option.value}
                        className="inline-flex items-center gap-1.5 rounded-full bg-orange-50 px-3 py-1 text-sm font-medium text-orange-600 dark:bg-orange-500/15 dark:text-orange-400"
                    >
                        {option.label}
                        <button
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                remove(option.value);
                            }}
                            className="text-orange-500 hover:text-orange-700 dark:text-orange-400 dark:hover:text-orange-300"
                        >
                            <CloseIcon className="h-3.5 w-3.5" />
                        </button>
                    </span>
                ))}
                <span className="ml-auto flex flex-col text-gray-400">
                    <AngleUpIcon className="h-2.5 w-2.5" />
                    <AngleDownIcon className="h-2.5 w-2.5" />
                </span>
            </div>

            {isOpen && (
                <div className="absolute z-20 mt-1 max-h-56 w-full overflow-y-auto rounded-lg border border-gray-200 bg-white py-1 shadow-lg dark:border-gray-700 dark:bg-gray-900">
                    {availableOptions.length === 0 ? (
                        <p className="px-3 py-2 text-sm text-gray-400 dark:text-gray-500">Aucune autre option.</p>
                    ) : (
                        availableOptions.map((option) => (
                            <button
                                key={option.value}
                                type="button"
                                onClick={() => add(option.value)}
                                className="block w-full px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/5"
                            >
                                {option.label}
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}
