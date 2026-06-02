import { AppContent } from '@/components/app-content';
import { AppMobileNav } from '@/components/app-mobile-nav';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Toaster as SonnerToaster } from 'sonner';
import { type BreadcrumbItem } from '@/types/index.d';
import { type PropsWithChildren } from 'react';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="p-4 pb-24 md:p-6 md:pb-6">{children}</div>
            </AppContent>
            <AppMobileNav />
            <SonnerToaster position="top-center" closeButton />
        </AppShell>
    );
}
