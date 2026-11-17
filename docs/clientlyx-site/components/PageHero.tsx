import type { ReactNode } from 'react';
import { Container, Eyebrow } from './ui';

// En-tête de page clair et sobre (style ClientlyX du 14/11/2026).
export function PageHero({ eyebrow, title, lead, children }: { eyebrow: string; title: ReactNode; lead: ReactNode; children?: ReactNode }) {
  return (
    <section className="border-b border-line bg-soft">
      <Container className="flex flex-col gap-6 py-16 sm:py-20">
        <Eyebrow>{eyebrow}</Eyebrow>
        <h1 className="max-w-4xl font-display text-[2.4rem] font-bold leading-[1.06] tracking-[-0.02em] text-navy sm:text-[3.2rem]">{title}</h1>
        <p className="max-w-2xl text-[1.15rem] leading-relaxed text-muted">{lead}</p>
        {children}
      </Container>
    </section>
  );
}
