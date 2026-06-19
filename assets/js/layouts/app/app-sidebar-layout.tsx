import { AppContent } from '@/components/app-content';
import { AppMobileNav } from '@/components/app-mobile-nav';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { Toaster as SonnerToaster } from 'sonner';
import { type BreadcrumbItem, type SharedData } from '@/types/index.d';
import { type PropsWithChildren } from 'react';
import { usePage } from '@inertiajs/react';
import RealtimeNotifier from '@/components/realtime-notifier';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: PropsWithChildren<{ breadcrumbs?: BreadcrumbItem[] }>) {
    // Active le temps réel automatiquement pour toute page ayant un projet
    const { project } = usePage<SharedData>().props;

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                <div className="p-4 pb-24 md:p-6 md:pb-6">{children}</div>
            </AppContent>
            <AppMobileNav />
            <SonnerToaster position="top-center" closeButton />
            <RealtimeNotifier projectUuid={(project as any)?.uuid} />
        </AppShell>
    );
}
