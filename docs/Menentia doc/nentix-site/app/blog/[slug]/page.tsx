import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { Icon } from '@/components/Icon';
import { CtaBand } from '@/components/sections';
import { Button, Container } from '@/components/ui';
import { formatDate, postBySlug, posts, readingMinutes, type Block } from '@/lib/blog';
import { links } from '@/lib/site';

export function generateStaticParams() {
  return posts.map((p) => ({ slug: p.slug }));
}

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const p = postBySlug(slug);
  if (!p) return {};
  return {
    title: p.title,
    description: p.excerpt,
    alternates: { canonical: `/blog/${p.slug}` },
    openGraph: { type: 'article', title: p.title, description: p.excerpt, publishedTime: p.date },
  };
}

export default async function Article({ params }: Props) {
  const { slug } = await params;
  const post = postBySlug(slug);
  if (!post) notFound();
  const others = posts.filter((p) => p.slug !== post.slug).slice(0, 3);
  const jsonLd = {
    '@context': 'https://schema.org',
    '@type': 'BlogPosting',
    headline: post.title,
    description: post.excerpt,
    datePublished: post.date,
    inLanguage: 'fr',
    author: { '@type': 'Organization', name: 'NENTIX TECH' },
    publisher: { '@type': 'Organization', name: 'NENTIX TECH' },
  };

  return (
    <>
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: JSON.stringify(jsonLd) }} />
      <section className="relative overflow-hidden bg-navy-deep">
        <div aria-hidden className="pointer-events-none absolute inset-0 bg-[radial-gradient(50%_70%_at_85%_0%,rgba(0,200,150,.22),transparent),radial-gradient(45%_70%_at_5%_100%,rgba(14,123,214,.28),transparent)]" />
        <Container className="relative py-14 sm:py-16"><div className="mx-auto flex max-w-3xl flex-col gap-5">
          <a href="/blog" className="inline-flex items-center gap-2 text-[14px] font-semibold text-green">← Tous les articles</a>
          <p className="text-[13px] font-semibold uppercase tracking-[0.16em] text-[#8FA6BC]">{post.category}</p>
          <h1 className="font-display text-[2rem] font-bold leading-[1.1] text-white sm:text-[2.6rem]">{post.title}</h1>
          <p className="text-[1.1rem] leading-relaxed text-[#C9D9E8]">{post.excerpt}</p>
          <p className="text-[14px] text-[#8FA6BC]">Par l’équipe NENTIX · {formatDate(post.date)} · {readingMinutes(post)} min de lecture</p>
          <div className="flex flex-wrap gap-2">
            {post.sectors.map((s) => <span key={s} className="rounded-full bg-white/10 px-3 py-1 text-[13px] font-medium text-white">{s}</span>)}
          </div>
        </div></Container>
      </section>

      <article className="py-14">
        <Container><div className="mx-auto flex max-w-3xl flex-col gap-5 text-[17px] leading-[1.75] text-ink">
          {post.body.map((b, i) => <BlockView key={i} b={b} />)}
          <div className="mt-6 flex flex-col gap-4 rounded-2xl border border-line bg-soft p-6 sm:flex-row sm:items-center sm:justify-between">
            <p className="font-display text-[1.15rem] font-bold text-navy">Essayez NENTIX CRM 14 jours, sans carte bancaire.</p>
            <Button href={links.signup} variant="brand">Commencer l’essai</Button>
          </div>
        </div></Container>
      </article>

      <section className="bg-soft py-16">
        <Container className="flex flex-col gap-6">
          <h2 className="font-display text-[1.5rem] font-bold text-navy">À lire aussi</h2>
          <div className="grid gap-6 md:grid-cols-3">
            {others.map((p) => (
              <a key={p.slug} href={`/blog/${p.slug}`} className="group flex flex-col gap-3 rounded-2xl border border-line bg-white p-5 hover:shadow-float">
                <Icon name={p.icon} className="h-6 w-6 text-blue-text" />
                <h3 className="font-display text-[1.05rem] font-bold leading-snug text-navy group-hover:text-blue-text">{p.title}</h3>
                <p className="text-[14px] text-muted">{readingMinutes(p)} min de lecture</p>
              </a>
            ))}
          </div>
        </Container>
      </section>
      <CtaBand />
    </>
  );
}

function BlockView({ b }: { b: Block }) {
  if ('h' in b) return <h2 className="mt-6 font-display text-[1.45rem] font-bold leading-snug text-navy">{b.h}</h2>;
  if ('p' in b) return <p>{b.p}</p>;
  if ('tip' in b)
    return (
      <aside className="flex gap-4 rounded-2xl border-l-4 border-green bg-green-soft p-5 text-[16px]">
        <Icon name="sparkles" className="mt-1 h-5 w-5 shrink-0 text-green-text" />
        <p>{b.tip}</p>
      </aside>
    );
  return (
    <ul className="flex flex-col gap-2.5">
      {b.ul.map((li) => (
        <li key={li} className="flex gap-3">
          <Icon name="check" className="mt-[7px] h-4 w-4 shrink-0 text-green" />
          <span>{li}</span>
        </li>
      ))}
    </ul>
  );
}
