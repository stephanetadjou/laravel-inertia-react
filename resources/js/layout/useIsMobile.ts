import { useEffect, useState } from 'react';

const QUERY = '(max-width: 767px)';

/** Vrai sur téléphone (même seuil que les styles mobiles de nentix.css). */
export default function useIsMobile(): boolean {
    const [mobile, setMobile] = useState(() => typeof window !== 'undefined' && window.matchMedia(QUERY).matches);
    useEffect(() => {
        const mq = window.matchMedia(QUERY);
        const update = () => setMobile(mq.matches);
        update();
        mq.addEventListener('change', update);
        return () => mq.removeEventListener('change', update);
    }, []);
    return mobile;
}
