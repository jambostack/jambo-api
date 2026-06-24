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

export interface OidcProviderStatus {
    id: string;
    name: string;
    issuer: string;
    clientId: string;
    enabled: boolean;
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
    oauthProviders?: Record<string, { enabled: boolean; configured: boolean; redirectUri?: string | null }>;
    oidcProviders?: OidcProviderStatus[];
    aiProviders?: {
        openai:    AiProviderStatus;
        anthropic: AiProviderStatus;
        deepseek:  AiProviderStatus;
        ollama:    AiProviderStatus;
        gemini:    AiProviderStatus;
        openrouter: AiProviderStatus;
        mistral:   AiProviderStatus;
        groq:      AiProviderStatus;
        xai:       AiProviderStatus;
        perplexity: AiProviderStatus;
        qwen:      AiProviderStatus;
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
