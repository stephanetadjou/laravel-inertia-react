import { Head, usePage } from '@inertiajs/react';

import { MenentiaSymbol } from '../../components/common/MenentiaLogo';
import CardQr from './CardQr';
import SocialIcon, { BRAND_COLORS, SocialLink } from './SocialIcon';

/*
 * Carte de visite à imprimer au format 85 × 55 mm : recto (identité) et
 * verso (QR code). « Imprimer » du navigateur, ou « Enregistrer en PDF ».
 */

interface PageProps {
    card: { name: string; job_title: string | null; company: string | null; phone: string | null; whatsapp: string | null; email: string | null; color: string; qr_url: string; url: string; photo_url: string | null; social_links: SocialLink[] };
    branding: boolean;
    [key: string]: unknown;
}

export default function CardPrint() {
    const { card, branding } = usePage<PageProps>().props;

    return (
        <>
            <Head title={`Carte à imprimer · ${card.name}`} />
            <style>{`
                @page { size: 85mm 55mm; margin: 0; }
                .bc { width: 85mm; height: 55mm; box-sizing: border-box; overflow: hidden; break-after: page; page-break-after: always; }
                @media print { .no-print { display: none !important; } body { background: #fff !important; } .bc-wrap { gap: 0 !important; padding: 0 !important; } .bc { box-shadow: none !important; border-radius: 0 !important; } }
            `}</style>
            <div className="min-h-screen bg-gray-100 print:bg-white">
                <div className="no-print mx-auto flex max-w-xl flex-col items-center gap-3 px-4 pt-8 text-center">
                    <p className="text-sm text-gray-600">Format carte de visite 85 × 55 mm, recto et verso. Dans la fenêtre d’impression, choisissez « Enregistrer en PDF » pour l’envoyer à votre imprimeur.</p>
                    <button type="button" onClick={() => window.print()} className="rounded-full bg-[#E8590C] px-6 py-2.5 font-semibold text-white">
                        Imprimer ou enregistrer en PDF
                    </button>
                </div>
                <div className="bc-wrap flex flex-col items-center gap-6 p-8">
                    <section className="bc relative flex flex-col justify-between bg-white p-[5mm] shadow-lg" style={{ borderLeft: `3mm solid ${card.color}` }}>
                        <div className="flex items-center gap-[3mm]">
                            {card.photo_url && <img src={card.photo_url} alt="" className="h-[14mm] w-[14mm] rounded-full object-cover" />}
                            <div>
                                <p className="text-[13pt] font-bold leading-tight text-[#2B1A3D]">{card.name}</p>
                                {card.job_title && <p className="text-[8pt] text-gray-600">{card.job_title}</p>}
                                {card.company && (
                                    <p className="text-[8.5pt] font-semibold" style={{ color: card.color }}>
                                        {card.company}
                                    </p>
                                )}
                            </div>
                        </div>
                        <div className="space-y-[0.6mm] text-[7.5pt] text-gray-800">
                            {card.phone && <p>Tél. {card.phone}</p>}
                            {card.whatsapp && card.whatsapp !== card.phone && <p>WhatsApp {card.whatsapp}</p>}
                            {card.email && <p>{card.email}</p>}
                        </div>
                    </section>
                    <section className="bc flex items-center gap-[4mm] p-[5mm] text-white shadow-lg" style={{ background: card.color }}>
                        <div className="rounded-[2mm] bg-white p-[1.5mm]">
                            <CardQr value={card.qr_url} className="h-[32mm] w-[32mm]" />
                        </div>
                        <div className="flex h-full flex-col justify-between">
                            <p className="text-[10pt] font-bold leading-snug">Scannez pour m’écrire sur WhatsApp, m’appeler ou prendre rendez-vous</p>
                            {card.social_links.length > 0 && (
                                <div className="flex flex-wrap gap-[1.5mm]">
                                    {card.social_links.slice(0, 6).map((s) => (
                                        <span key={s.key} className={`grid h-[4.5mm] w-[4.5mm] place-items-center rounded-full ${s.key === 'snapchat' ? 'text-black' : 'text-white'}`} style={{ background: BRAND_COLORS[s.key] ?? card.color }} title={s.label}>
                                            <SocialIcon name={s.key} className="h-[3mm] w-[3mm]" />
                                        </span>
                                    ))}
                                </div>
                            )}
                            <p className="break-all text-[6.5pt] opacity-90">{card.url.replace(/^https?:\/\//, '')}</p>
                            {branding && (
                                <p className="flex items-center gap-[1mm] text-[6pt] opacity-90">
                                    <MenentiaSymbol className="h-[3mm] w-[3mm]" onDark /> Créé avec Cliently<b>X</b>
                                </p>
                            )}
                        </div>
                    </section>
                </div>
            </div>
        </>
    );
}
