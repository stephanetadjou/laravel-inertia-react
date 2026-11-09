import type { ReactNode } from 'react';
import { Container, Eyebrow } from './ui';

// En-tête de page sombre, dans la continuité de l'en-tête du site.
export function PageHero({ eyebrow, title, lead, children }: { eyebrow: string; title: ReactNode; lead: ReactNode; children?: ReactNode }) {
  return (
    <section className="relative overflow-hidden bg-navy-deep">
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(50%_70%_at_85%_0%,rgba(0,200,150,.22),transparent),radial-gradient(45%_70%_at_5%_100%,rgba(14,123,214,.28),transparent)]" />
      <Container className="relative flex flex-col gap-6 py-16 sm:py-20">
        <Eyebrow tone="light">{eyebrow}</Eyebrow>
        <h1 className="max-w-4xl font-display text-[2.4rem] font-bold leading-[1.04] tracking-[-0.018em] text-white sm:text-[3.3rem]">{title}</h1>
        <p className="max-w-2xl text-[1.15rem] leading-relaxed text-[#C9D9E8]">{lead}</p>
        {children}
      </Container>
    </section>
  );
}
