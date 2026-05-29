import { Link, usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { type NavItem, type UserCan } from '@/types';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Users, Shield, Key } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface UserManagementLayoutProps { children: React.ReactNode; can: UserCan; }

export default function UserManagementLayout({ children, can }: UserManagementLayoutProps) {
  const t = useTranslation();

  const sidebarNavItems: NavItem[] = [
    { title: t('users.management.nav_users'), href: '/user-management/users', icon: Users, permission: 'manage_users' as keyof UserCan },
    { title: t('users.management.nav_roles'), href: '/user-management/roles', icon: Shield, permission: 'manage_roles' as keyof UserCan },
    { title: t('users.management.nav_permissions'), href: '/user-management/permissions', icon: Key, permission: 'manage_users' as keyof UserCan },
  ];

  if (typeof window === 'undefined') return null;
  const currentPath = window.location.pathname.replace(/\/$/, '');

  return (
    <div className="space-y-6">
      <Heading title={t('users.management.title')} description={t('users.management.description')} />
      <div className="flex flex-col lg:flex-row lg:gap-8">
        <aside className="w-full lg:w-48 shrink-0">
          <nav className="flex flex-row lg:flex-col gap-1 overflow-x-auto pb-1 lg:pb-0">
            {sidebarNavItems.map((item, i) => (
              can[item.permission] ? (
                <Button key={i} size="sm" variant="ghost" asChild className={cn('justify-start shrink-0', currentPath === item.href && 'bg-muted')}>
                  <Link href={item.href}>{item.icon && <item.icon className="mr-2 h-4 w-4" />}{item.title}</Link>
                </Button>
              ) : null
            ))}
          </nav>
        </aside>
        <Separator className="my-4 lg:hidden" />
        <div className="flex-1 min-w-0">{children}</div>
      </div>
    </div>
  );
}
