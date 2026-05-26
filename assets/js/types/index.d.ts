import { LucideIcon } from 'lucide-react';
import type { Config } from 'ziggy';
import type { User, Role, Permission } from './user';
import type { Project, Asset, Field, Collection, ProjectMember, ProjectMemberRole, ProjectMemberUser } from './project';
import type { ContentEntry, ColumnDef } from './content';

export type { User, Role, Permission, Project, Asset, Field, Collection, ProjectMember, ProjectMemberRole, ProjectMemberUser, ContentEntry, ColumnDef };

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: Config & { location: string };
    sidebarOpen: boolean;
    locale: string;
    translations: Record<string, string>;
    [key: string]: unknown;
}

export interface PageProps {
    auth: {
        user: {
            id: number;
            name: string;
            email: string;
        };
    };
    errors: Record<string, string>;
    flash: {
        message?: string;
        success?: string;
        error?: string;
    };
}

export interface UserCan {
    [key: string]: boolean;
}
