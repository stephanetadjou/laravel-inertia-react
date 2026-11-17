import { ReactNode } from 'react';
import { SidebarProvider } from '../context/SidebarContext';
import { Link, usePage } from '@inertiajs/react';
import AppHeader from './AppHeader';
import { isConfigPath } from './navigation';
import useIsMobile from './useIsMobile';
import '../pages/Dashboard/dashboard.css';
import '../components/page/page.css';
import '../components/page/simple.css';
import '../../css/nentix.css';

/**
 * Mise en page ClientlyX : rail d'hexagones à gauche (barre du bas sur
 * téléphone), en-tête clair de l'application en cours, pouls client.
 */
/** Sur téléphone, le paramétrage n'est pas proposé : il se fait sur ordinateur. */
function DesktopOnly() {
    return (
        <div className="mx-auto mt-10 flex max-w-sm flex-col items-center gap-3 rounded-3xl border border-gray-200 bg-white p-6 text-center dark:border-gray-800 dark:bg-gray-900">
            <span className="nx-hex flex h-16 w-14 items-center justify-center bg-gradient-to-br from-slate-500 to-slate-700 text-white" aria-hidden="true">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
                    <rect x="3" y="4" width="18" height="12" rx="2" />
                    <path d="M8 20h8M12 16v4" />
                </svg>
            </span>
            <h1 className="text-lg font-bold text-[#0B1F3A] dark:text-white">À faire sur ordinateur</h1>
            <p className="text-[14px] text-gray-500">Le paramétrage de ClientlyX se fait depuis un ordinateur. Sur téléphone, gardez l’essentiel : vos clients, vos rendez-vous, vos encaissements.</p>
            <Link href="/apps" className="mt-1 rounded-full bg-gradient-to-r from-blue-700 to-cyan-500 px-5 py-2.5 text-sm font-semibold text-white">
                Retour à Ma journée
            </Link>
        </div>
    );
}

const AppLayout: React.FC<{ children: ReactNode }> = ({ children }) => {
    const { url } = usePage();
    const mobile = useIsMobile();
    const blocked = mobile && isConfigPath(url);

    return (
        <SidebarProvider>
            <div className="nx-app min-h-screen pb-20 md:pb-0 md:pl-[84px]">
                <AppHeader />
                <main className="mx-auto max-w-(--breakpoint-2xl) p-4 md:p-6">{blocked ? <DesktopOnly /> : children}</main>
            </div>
        </SidebarProvider>
    );
};

export default AppLayout;
