import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarGroup, SidebarGroupLabel } from '@/components/ui/sidebar';
import { type NavItem, SharedData, Project, UserCan } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, Settings, Webhook, Image, Users, Folder, Key, Globe, UserCog, Sliders } from 'lucide-react';
import AppLogo from './app-logo';
import { Separator } from '@radix-ui/react-separator';
import { useTranslation } from '@/lib/i18n';

export function AppSidebar() {
    const t = useTranslation();
    const page = usePage<SharedData>();

    const mainNavItems: NavItem[] = [
        {
            title: t('dashboard.page_title'),
            href: '/',
            icon: LayoutGrid,
        }
    ];
    const currentProject = page.props.project as Project | undefined;
    
    const can = (usePage().props.userCan ?? {}) as UserCan;

    // Generate project menu items if we're on a project page
    const projectMenuItems: (NavItem & { permission?: string })[] = currentProject ? [
        {
            title: t('nav.collections'),
            href: route('projects.show', currentProject.id),
            icon: Folder,
        },
        {
            title: t('nav.assets'),
            href: route('assets.index', currentProject.id),
            icon: Image,
            permission: 'access_assets',
        },
        {
            title: t('projects.settings.nav_end_users'),
            href: route('projects.settings.end-users', currentProject.id),
            icon: UserCog,
            permission: 'access_end_users_settings',
        },
        {
            title: t('projects.settings.title'),
            href: route('projects.settings.project', currentProject.id),
            icon: Settings,
            permission: 'access_project_settings',
        },
        {
            title: t('projects.settings.nav_localization'),
            href: route('projects.settings.localization', currentProject.id),
            icon: Globe,
            permission: 'access_localization_settings',
        },
        {
            title: t('projects.settings.nav_user_access'),
            href: route('projects.settings.user-access', currentProject.id),
            icon: Users,
            permission: 'access_user_access_settings',
        },
        {
            title: t('projects.settings.nav_api_access'),
            href: route('projects.settings.api-access', currentProject.id),
            icon: Key,
            permission: 'access_api_access_settings',
        },
        {
            title: t('projects.settings.nav_webhooks'),
            href: route('projects.settings.webhooks', currentProject.id),
            icon: Webhook,
            permission: 'access_webhooks_settings',
        },
    ] : [];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/">
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                
                {currentProject && (
                    <SidebarGroup className="px-2 py-0 mt-6">
                        <SidebarGroupLabel>{currentProject.name}</SidebarGroupLabel>
                        <SidebarMenu>
                            {projectMenuItems
                                .filter(item => !item.permission || can[item.permission])
                                .map((item) => (
                                <SidebarMenuItem key={item.title}>
                                    <SidebarMenuButton  
                                        asChild
                                        isActive={page.url.includes(item.href)}
                                        tooltip={{ children: item.title }}
                                    >
                                        <Link href={item.href} >
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </Link>
                                    </SidebarMenuButton>
                                </SidebarMenuItem>
                            ))}
                        </SidebarMenu>
                    </SidebarGroup>
                )}
            </SidebarContent>

            <SidebarFooter>
                {can.access_app_settings && (
                    <SidebarGroup className="px-2 py-0">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={page.url.startsWith('/admin/app-settings')}
                                    tooltip={{ children: t('nav.app_settings') }}
                                >
                                    <Link href="/admin/app-settings">
                                        <Sliders />
                                        <span>{t('nav.app_settings')}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}

                {(can.manage_users || can.manage_roles) && (
                    <SidebarGroup className="px-2 py-0 mt-6">
                        <SidebarMenu>
                            <SidebarMenuItem>
                            <SidebarMenuButton
                                asChild
                                isActive={page.url.includes('/user-management/users') || page.url.includes('/user-management/roles') || page.url.includes('/user-management/permissions')}
                                tooltip={{ children: t('nav.users_roles') }}
                            >
                                <Link href={'/user-management/' + (can.manage_users ? 'users' : 'roles')} >
                                    <Users />
                                    <span>{t('nav.users_roles')}</span>
                                </Link>
                            </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarGroup>
                )}

                <Separator className="my-2 border-t" />

                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
