import { Link, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { NavItem, Project, UserCan } from '@/types/index.d';
import { Settings as SettingsIcon, Globe, Users, Key, Share2, UserCog } from 'lucide-react';
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
        { title: t('projects.settings.nav_localization'), href: `${basePath}/localization`, icon: Globe, permission: 'access_localization_settings' },
        { title: t('projects.settings.nav_user_access'), href: `${basePath}/user-access`, icon: Users, permission: 'access_user_access_settings' },
        { title: t('projects.settings.nav_api_access'), href: `${basePath}/api-access`, icon: Key, permission: 'access_api_access_settings' },
        { title: t('projects.settings.nav_webhooks'), href: `${basePath}/webhooks`, icon: Share2, permission: 'access_webhooks_settings' },
        { title: t('projects.settings.nav_end_users'), href: `${basePath}/end-users`, icon: UserCog, permission: 'access_end_users_settings' },
    ];

    return (
        <div>
            <Heading title={t('projects.settings.heading')} description={t('projects.settings.heading_desc')} />

            <div className="flex flex-col lg:flex-row lg:space-y-0 lg:space-x-12 rtl:lg:space-x-reverse">
                <aside className="w-full lg:w-48">
                    <nav className="flex flex-col space-y-1">
                        {sidebarNavItems
                            .filter((item) => can[item.permission])
                            .map((item, index) => (
                                <Button
                                    key={`${item.href}-${index}`}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', {
                                        'bg-muted': currentPath === item.href,
                                    })}
                                >
                                    <Link href={item.href}>
                                        {item.icon && <item.icon className="mr-2 h-4 w-4 rtl:ml-2 rtl:mr-0" />}
                                        {item.title}
                                    </Link>
                                </Button>
                            ))}
                    </nav>
                </aside>

                <Separator className="my-6 md:hidden" />

                <div className="flex-1 w-full">
                    <section className="w-full space-y-12">{children}</section>
                </div>
            </div>
        </div>
    );
}
