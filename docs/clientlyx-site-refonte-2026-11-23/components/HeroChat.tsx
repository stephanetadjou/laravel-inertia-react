import { Icon } from './Icon';

// Démo du héros : question du client → prix → créneaux → RDV réservé.
// Animation en CSS seul (classe .hx-msg de globals.css), sans JavaScript.
const messages: { from: 'client' | 'bot'; text: string; buttons?: string[] }[] = [
  { from: 'client', text: 'Bonsoir, c’est combien pour des tresses ?' },
  { from: 'bot', text: 'Bonsoir Mireille ! Les tresses sont à 15 000 FCFA (2 h). Voulez-vous réserver ?' },
  { from: 'bot', text: 'Créneaux libres samedi :', buttons: ['9 h', '11 h', '14 h'] },
  { from: 'client', text: '11 h' },
  { from: 'bot', text: 'C’est réservé : samedi à 11 h ✅ Je vous envoie un rappel la veille.' },
];

export function HeroChat() {
  return (
    <div className="relative mx-auto w-full max-w-[340px]">
      <div aria-hidden className="pointer-events-none absolute -inset-10 rounded-full bg-green/20 blur-3xl" />
      <div className="relative overflow-hidden rounded-[2.2rem] border-[10px] border-navy bg-[#EFEAE2] shadow-float">
        <div className="flex items-center gap-3 bg-[#075E54] px-4 py-3 text-white">
          <span className="grid h-9 w-9 place-items-center rounded-full bg-white/15"><Icon name="bot" className="h-5 w-5" /></span>
          <div className="leading-tight">
            <p className="text-[14px] font-semibold">Salon Kalia</p>
            <p className="text-[11px] text-white/75">Assistant ClientlyX · en ligne 24 h/24</p>
          </div>
        </div>
        <div className="flex min-h-[380px] flex-col gap-2 px-3 py-4 text-left text-[13.5px] leading-snug" aria-label="Exemple de conversation avec l’assistant">
          {messages.map((m, i) => (
            <div key={i} className={`hx-msg flex flex-col gap-1.5 ${m.from === 'client' ? 'items-end' : 'items-start'}`} style={{ animationDelay: `${0.4 + i * 1.1}s` }}>
              <p className={`max-w-[85%] rounded-2xl px-3 py-2 shadow-sm ${m.from === 'client' ? 'rounded-tr-sm bg-[#D9FDD3] text-ink' : 'rounded-tl-sm bg-white text-ink'}`}>{m.text}</p>
              {m.buttons && (
                <div className="flex gap-1.5">
                  {m.buttons.map((b) => (
                    <span key={b} className={`rounded-full border px-3 py-1 text-[12.5px] font-semibold ${b === '11 h' ? 'border-[#075E54] bg-[#075E54] text-white' : 'border-[#075E54]/30 bg-white text-[#075E54]'}`}>{b}</span>
                  ))}
                </div>
              )}
            </div>
          ))}
          <div className="hx-msg mt-auto self-center rounded-full bg-white/80 px-3 py-1 text-[11.5px] font-semibold text-navy" style={{ animationDelay: `${0.4 + messages.length * 1.1}s` }}>
            Fiche client créée · RDV dans l’agenda
          </div>
        </div>
      </div>
    </div>
  );
}
