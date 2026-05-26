import { cn } from '@/lib/utils';
import { type Project, type SharedData, type UserCan } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Folder, Image, LayoutGrid, Settings, Users } from 'lucide-react';

interface MobileNavItem {
    title: string;
    href: string;
    icon: React.ElementType;
}

export function AppMobileNav() {
    const page = usePage<SharedData>();
    const currentProject = page.props.project as Project | undefined;
    const can = page.props.userCan as UserCan;

    const items: MobileNavItem[] = currentProject
        ? [
              { title: 'Projects', href: '/', icon: LayoutGrid },
              { title: 'Content', href: route('projects.show', currentProject.id), icon: Folder },
              ...(can?.access_assets
                  ? [{ title: 'Assets', href: route('assets.index', currentProject.id), icon: Image }]
                  : []),
              ...(can?.access_project_settings
                  ? [{ title: 'Settings', href: route('projects.settings.project', currentProject.id), icon: Settings }]
                  : []),
          ]
        : [
              { title: 'Dashboard', href: '/', icon: LayoutGrid },
              ...(can?.access_users || can?.access_roles
                  ? [{ title: 'Users', href: '/user-management/users', icon: Users }]
                  : []),
          ];

    return (
        <nav className="fixed bottom-0 left-0 right-0 z-40 md:hidden">
            <div className="bg-card/95 backdrop-blur-xl border-t border-border shadow-[0_-4px_24px_oklch(0_0_0/0.08)]">
                <div className="flex items-stretch justify-around safe-bottom" style={{ minHeight: '64px' }}>
                    {items.map((item) => {
                        const isActive =
                            item.href === '/'
                                ? page.url === '/'
                                : page.url.startsWith(item.href.replace(/\?.*$/, ''));
                        return (
                            <Link
                                key={item.title}
                                href={item.href}
                                className={cn(
                                    'flex flex-col items-center justify-center gap-1 flex-1 px-1 py-3 transition-all duration-200',
                                    isActive
                                        ? 'text-primary'
                                        : 'text-muted-foreground active:text-foreground',
                                )}
                            >
                                <div
                                    className={cn(
                                        'flex items-center justify-center w-10 h-6 rounded-full transition-all duration-200',
                                        isActive && 'bg-primary/12',
                                    )}
                                >
                                    <item.icon
                                        className={cn(
                                            'transition-all duration-200',
                                            isActive ? 'h-[22px] w-[22px] stroke-[2.2]' : 'h-5 w-5 stroke-[1.7]',
                                        )}
                                    />
                                </div>
                                <span
                                    className={cn(
                                        'text-[10px] leading-none transition-all duration-200',
                                        isActive ? 'font-semibold' : 'font-medium',
                                    )}
                                >
                                    {item.title}
                                </span>
                            </Link>
                        );
                    })}
                </div>
            </div>
        </nav>
    );
}
