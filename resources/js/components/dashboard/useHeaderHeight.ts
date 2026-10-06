import { useEffect, useState } from 'react';

/** Hauteur de l'en-tête global (collant), pour coller une barre de filtres juste en dessous. */
export function useHeaderHeight(): number {
    const [height, setHeight] = useState(0);
    useEffect(() => {
        const header = document.querySelector('header');
        if (!header) return;
        const update = () => setHeight(header.getBoundingClientRect().height);
        update();
        const observer = new ResizeObserver(update);
        observer.observe(header);
        return () => observer.disconnect();
    }, []);
    return height;
}
