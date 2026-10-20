import { ReactNode } from 'react';
import { SidebarProvider, useSidebar } from '../context/SidebarContext';
import AppHeader from './AppHeader';
import Backdrop from './Backdrop';
import AppSidebar from './AppSidebar';
import '../pages/Dashboard/dashboard.css';
import '../components/page/page.css';
import '../components/page/simple.css';

const LayoutContent: React.FC<{ children: ReactNode }> = ({ children }) => {
    const { isExpanded, isHovered, isMobileOpen } = useSidebar();

    return (
        <div className="min-h-screen xl:flex">
            <div>
                <AppSidebar />
                <Backdrop />
            </div>
            <div
                className={`flex-1 transition-all duration-300 ease-in-out ${
                    isExpanded || isHovered ? 'lg:ml-[232px]' : 'lg:ml-[72px]'
                } ${isMobileOpen ? 'ml-0' : ''}`}
            >
                <AppHeader />
                <div className="mx-auto max-w-(--breakpoint-2xl) p-4 md:p-6">{children}</div>
            </div>
        </div>
    );
};

const AppLayout: React.FC<{ children: ReactNode }> = ({ children }) => {
    return (
        <SidebarProvider>
            <LayoutContent>{children}</LayoutContent>
        </SidebarProvider>
    );
};

export default AppLayout;
