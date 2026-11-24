import { links } from '@/lib/site';

// Bouton flottant « Tester sur WhatsApp », en bas à gauche (l’assistant du site est à droite).
export function WhatsAppFloat() {
  return (
    <a
      href={links.whatsapp}
      target="_blank"
      rel="noopener"
      data-source="site_whatsapp"
      aria-label="Tester ClientlyX sur WhatsApp"
      className="fixed bottom-[calc(20px+env(safe-area-inset-bottom,0px))] left-[calc(20px+env(safe-area-inset-left,0px))] z-[59] inline-flex items-center gap-2 rounded-full bg-[#25D366] py-2.5 pl-2.5 pr-4 font-semibold text-white shadow-[0_18px_40px_-12px_rgba(0,0,0,.45)] transition-transform hover:-translate-y-0.5"
    >
      <svg viewBox="0 0 32 32" className="h-7 w-7" fill="currentColor" aria-hidden="true">
        <path d="M16 3a13 13 0 0 0-11.2 19.6L3 29l6.6-1.7A13 13 0 1 0 16 3zm0 23.6c-2 0-4-.5-5.7-1.6l-.4-.2-3.9 1 1-3.8-.3-.4A10.6 10.6 0 1 1 16 26.6zm5.8-7.9c-.3-.2-1.9-.9-2.2-1-.3-.1-.5-.2-.7.2l-1 1.2c-.2.2-.4.2-.7.1a8.7 8.7 0 0 1-4.3-3.8c-.3-.6.3-.5.9-1.7.1-.2 0-.4 0-.6l-1-2.4c-.3-.6-.5-.5-.7-.5h-.6c-.2 0-.6.1-.9.4-.3.3-1.2 1.1-1.2 2.8s1.2 3.2 1.4 3.4c.2.2 2.4 3.6 5.8 5 2.1.9 3 1 4 .8.7-.1 1.9-.8 2.2-1.5.3-.8.3-1.4.2-1.5-.1-.2-.3-.3-.6-.4z" />
      </svg>
      <span className="text-[15px] max-[520px]:hidden">Tester sur WhatsApp</span>
    </a>
  );
}
