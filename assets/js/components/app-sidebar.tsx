import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem, SidebarGroup, SidebarGroupLabel } from '@/components/ui/sidebar';
import { type NavItem, SharedData, Project, UserCan } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LayoutGrid, Settings, Webhook, Image, Users, Folder, Key, Globe } from 'lucide-react';
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
    
    const can = usePage().props.userCan as UserCan;

    // Generate project menu items if we're on a project page
    const projectMenuItems: (NavItem & { permission?: string })[] = currentProject ? [
        {
            title: 'Collections',
            href: route('projects.show', currentProject.id),
            icon: Folder,
        },
        {
            title: 'Asset Management',
            href: route('assets.index', currentProject.id),
            icon: Image,
            permission: 'access_assets',
        },
        {
            title: 'Settings',
            href: route('projects.settings.project', currentProject.id),
            icon: Settings,
            permission: 'access_project_settings',
        },
        {
            title: 'Localization',
            href: route('projects.settings.localization', currentProject.id),
            icon: Globe,
            permission: 'access_localization_settings',
        },
        {
            title: 'User Access',
            href: route('projects.settings.user-access', currentProject.id),
            icon: Users,
            permission: 'access_user_access_settings',
        },
        {
            title: 'API Access',
            href: route('projects.settings.api-access', currentProject.id),
            icon: Key,
            permission: 'access_api_access_settings',
        },
        {
            title: 'Webhooks',
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
                {(can.access_users || can.access_roles || can.access_permissions) && (
                    <SidebarGroup className="px-2 py-0 mt-6">
                        <SidebarMenu>
                            <SidebarMenuItem>
                            <SidebarMenuButton  
                                asChild
                                isActive={page.url.includes('/user-management/users') || page.url.includes('/user-management/roles') || page.url.includes('/user-management/permissions')}
                                tooltip={{ children: 'Users & Roles' }}
                            >
                                <Link href={'/user-management/' + (can.access_users ? 'users' : can.access_roles ? 'roles' : 'permissions')} >
                                    <Users />
                                    <span>Users & Roles</span>
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
