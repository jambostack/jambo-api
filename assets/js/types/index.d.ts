import { LucideIcon } from 'lucide-react';
import type { Config } from 'ziggy';
import type { User, Role, Permission } from './user';
import type { Project, Asset, Field, Collection, EndUser, ProjectMember, ProjectMemberRole, ProjectMemberUser } from './project';
import type { ContentEntry, ColumnDef } from './content';

export type { User, Role, Permission, Project, Asset, Field, Collection, EndUser, ProjectMember, ProjectMemberRole, ProjectMemberUser, ContentEntry, ColumnDef };

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
    permission?: string;
}

export interface AiProviderStatus {
    enabled: boolean;
    configured: boolean;
    model?: string | null;
    url?: string | null;
}

export interface DeployIntegrationStatus {
    client_id: string;
    configured: boolean;
}

export interface AppSettings {
    appName: string;
    logoUrl: string | null;
    logoDarkUrl: string | null;
    logoLightUrl: string | null;
    iconDarkUrl: string | null;
    iconLightUrl: string | null;
    faviconUrl: string | null;
    aiProviders?: {
        openai:    AiProviderStatus;
        anthropic: AiProviderStatus;
        deepseek:  AiProviderStatus;
        ollama:    AiProviderStatus;
    };
    deployIntegrations?: {
        vercel:  DeployIntegrationStatus;
        netlify: DeployIntegrationStatus;
        railway: DeployIntegrationStatus;
    };
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: Config & { location: string };
    sidebarOpen: boolean;
    locale: string;
    translations: Record<string, string>;
    appSettings: AppSettings;
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
