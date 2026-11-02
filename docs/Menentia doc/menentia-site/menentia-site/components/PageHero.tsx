import type { ReactNode } from 'react';
import { Container, Eyebrow } from './ui';

export function PageHero({ eyebrow, title, lead, children }: { eyebrow: string; title: ReactNode; lead: ReactNode; children?: ReactNode }) {
  return (
    <section className="relative overflow-hidden border-b border-line bg-soft">
      <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(50%_60%_at_90%_0%,rgba(0,200,150,.14),transparent),radial-gradient(40%_50%_at_0%_100%,rgba(14,123,214,.10),transparent)]" />
      <Container className="relative flex flex-col gap-6 py-16 sm:py-20">
        <Eyebrow>{eyebrow}</Eyebrow>
        <h1 className="max-w-4xl font-display text-[2.4rem] font-bold leading-[1.04] tracking-[-0.018em] text-navy sm:text-[3.3rem]">{title}</h1>
        <p className="max-w-2xl text-[1.15rem] leading-relaxed text-muted">{lead}</p>
        {children}
      </Container>
    </section>
  );
}
