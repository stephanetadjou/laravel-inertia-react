'use client';

import { useEffect, useRef, useState } from 'react';
import { Icon } from './Icon';

// Menu déroulant de l'en-tête : il s'ouvre et se ferme au clic sur le bouton,
// et se ferme au clic à l'extérieur, sur Échap ou en suivant un lien.
export function NavDropdown({ label, children }: { label: string; children: React.ReactNode }) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const onClick = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('mousedown', onClick);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onClick);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    <div ref={ref}>
      <button
        type="button"
        aria-expanded={open}
        onClick={() => setOpen((o) => !o)}
        className={`inline-flex h-[72px] items-center gap-1 whitespace-nowrap px-4 text-[15px] font-medium hover:text-white ${open ? 'text-white' : 'text-white/90'}`}
      >
        {label} <Icon name="chevron" className={`h-4 w-4 transition ${open ? 'rotate-180' : ''}`} />
      </button>
      {/* Panneau toujours dans le HTML (référencement, aperçu statique), masqué tant qu'il est fermé. */}
      <div hidden={!open} className="absolute inset-x-0 top-full" onClick={(e) => (e.target as HTMLElement).closest('a') && setOpen(false)}>
        <div className="mx-auto w-full max-w-[1320px] px-5 sm:px-8">
          <div className="rounded-b-2xl border border-t-0 border-white/10 bg-[#0B2540] p-6 shadow-float">{children}</div>
        </div>
      </div>
    </div>
  );
}
