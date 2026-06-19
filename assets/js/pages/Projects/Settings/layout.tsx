import { Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { NavItem, Project, UserCan } from '@/types/index.d';
import { Settings as SettingsIcon, Globe, Users, Key, Share2, UserCog, FileText, Wand2, Plug, Mail, HardDrive, Clock, Shield, Zap } from 'lucide-react';
import { PropsWithChildren } from 'react';
import { useTranslation } from '@/lib/i18n';

interface ProjectSettingsLayoutProps extends PropsWithChildren {
    project: Project;
}

export default function ProjectSettingsLayout({ project, children }: ProjectSettingsLayoutProps) {
    const t = useTranslation();
    const can = usePage().props.userCan as UserCan;

    // Avoid SSR mismatch; only render on client where window is defined
    if (typeof window === 'undefined') {
        return null;
    }

    const currentPath = window.location.pathname;
    const basePath = `/projects/${project.id}/settings`;

    type SidebarItem = NavItem & { permission: keyof typeof can | string };

    const sidebarNavItems: SidebarItem[] = [
        { title: t('projects.settings.nav_project'), href: `${basePath}/project`, icon: SettingsIcon, permission: 'access_project_settings' },
        { title: t('projects.settings.nav_storage'), href: `${basePath}/storage`, icon: HardDrive, permission: 'access_project_settings' },
        { title: t('projects.settings.nav_jwt_ttl'), href: `${basePath}/jwt-ttl`, icon: Clock, permission: 'access_project_settings' },
        { title: t('projects.settings.nav_localization'), href: `${basePath}/localization`, icon: Globe, permission: 'access_localization_settings' },
        { title: t('projects.settings.nav_user_access'), href: `${basePath}/user-access`, icon: Users, permission: 'access_user_access_settings' },
        { title: t('projects.settings.nav_api_access'), href: `${basePath}/api-access`, icon: Key, permission: 'access_api_access_settings' },
        { title: t('projects.settings.nav_api_docs'), href: `${basePath}/api-docs`, icon: FileText, permission: 'access_api_access_settings' },
        { title: t('projects.settings.nav_mcp_access'), href: `${basePath}/mcp-access`, icon: Plug, permission: 'access_api_access_settings' },
        { title: t('projects.settings.nav_webhooks'), href: `${basePath}/webhooks`, icon: Share2, permission: 'access_webhooks_settings' },
        { title: t('flow.sidebar'), href: `${basePath}/automations`, icon: Zap, permission: 'access_project_settings' },
        { title: t('projects.settings.nav_mailer'), href: `${basePath}/mailer`, icon: Mail, permission: 'access_project_settings' },
        { title: t('projects.settings.nav_security'), href: `${basePath}/security`, icon: Shield, permission: 'access_project_settings' },
        { title: t('projects.settings.nav_end_users'), href: `${basePath}/end-users`, icon: UserCog, permission: 'access_end_users_settings' },
        { title: 'Jambo Studio', href: `${basePath}/studio`, icon: Wand2, permission: 'access_project_settings' },
    ];

    const filteredItems = sidebarNavItems.filter((item) => can[item.permission]);

    return (
        <div>
            <Heading title={t('projects.settings.heading')} description={t('projects.settings.heading_desc')} />

            {/* ── Desktop (lg+) : sidebar verticale ──────────────────── */}
            <div className="hidden lg:flex lg:flex-row lg:space-y-0 lg:space-x-12 rtl:lg:space-x-reverse">
                <aside className="w-48 shrink-0 sticky top-16 self-start max-h-[calc(100vh-5rem)] overflow-y-auto">
                    <nav className="flex flex-col space-y-1 pb-4">
                        {filteredItems.map((item, index) => (
                            <Link
                                key={`${item.href}-${index}`}
                                href={item.href}
                                className={cn(
                                    'flex items-center gap-2 px-3 py-2 text-sm rounded-md hover:bg-accent transition-colors',
                                    currentPath === item.href
                                        ? 'bg-accent text-accent-foreground font-medium'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                <span className="truncate">{item.title}</span>
                            </Link>
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 min-w-0">
                    <section className="w-full space-y-12">{children}</section>
                </div>
            </div>

            {/* ── Tablet / Mobile (< lg) : barre d'onglets horizontale scrollable ── */}
            <div className="lg:hidden space-y-6">
                <div className="relative">
                    <nav className="flex items-center gap-1 overflow-x-auto pb-3 -mx-1 px-1" style={{ scrollbarWidth: 'none' }}>
                        {filteredItems.map((item, index) => (
                            <Link
                                key={`${item.href}-${index}`}
                                href={item.href}
                                className={cn(
                                    'flex items-center gap-1.5 px-2.5 py-1.5 text-xs font-medium rounded-full whitespace-nowrap border transition-colors shrink-0',
                                    currentPath === item.href
                                        ? 'bg-primary text-primary-foreground border-primary'
                                        : 'bg-background text-muted-foreground border-border hover:border-primary/40 hover:text-foreground',
                                )}
                            >
                                {item.icon && <item.icon className="h-3 w-3 shrink-0" />}
                                {item.title}
                            </Link>
                        ))}
                    </nav>
                    {/* Indicateur visuel de défilement à droite */}
                    <div className="pointer-events-none absolute right-0 top-0 bottom-0 w-8 bg-gradient-to-l from-background to-transparent" />
                </div>

                <Separator />

                <section className="w-full space-y-12">{children}</section>
            </div>
        </div>
    );
}
