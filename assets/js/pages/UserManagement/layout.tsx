import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

import { type NavItem, type UserCan } from '@/types';

import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Users, Shield, Key } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface UserManagementLayoutProps {
    children: React.ReactNode;
    can: UserCan;
}

export default function UserManagementLayout({ children, can }: UserManagementLayoutProps) {
    const t = useTranslation();

    const sidebarNavItems: NavItem[] = [
        {
            title: t('users.management.nav_users'),
            href: '/user-management/users',
            icon: Users,
        },
        {
            title: t('users.management.nav_roles'),
            href: '/user-management/roles',
            icon: Shield,
        },
        {
            title: t('users.management.nav_permissions'),
            href: '/user-management/permissions',
            icon: Key,
        },
    ];

    // When server-side rendering, we only render the layout on the client
    if (typeof window === 'undefined') {
        return null;
    }

    const currentPath = window.location.pathname;

    return (
        <div>
            <Heading title={t('users.management.title')} description={t('users.management.description')} />

            <div className="flex flex-col lg:flex-row lg:space-y-0 lg:space-x-12 rtl:lg:space-x-reverse">
                <aside className="w-full lg:w-48">
                    <nav className="flex flex-col space-y-1">
                        {sidebarNavItems.map((item, index) => (
                            can['access_' + item.href.split('/').pop() as keyof typeof can] && (
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
                            )
                        ))}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" orientation="horizontal" />

                <div className="flex-1 max-w-full md:w-2xl lg:w-xl xl:w-3xl">
                    {children}
                </div>
            </div>
        </div>
    );
}
