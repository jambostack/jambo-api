export interface Project {
    id: number;
    uuid: string;
    name: string;
    description: string | null;
    disk?: string;
    storage_strategy?: 'default_only' | 'mirror_all' | 'rules';
    default_locale: string;
    locales?: string[];
    settings: Record<string, any> | null;
    public_api: boolean;
    created_at: string;
    updated_at: string;
    collections?: Collection[];
    collections_count?: number;
    assets_count?: number;
    content_count?: number;
}

export interface Collection {
    id: number;
    uuid: string;
    project_id: number;
    name: string;
    slug: string;
    order: number | null;
    description?: string;
    is_singleton: boolean;
    settings?: {
        workflow?: {
            statuses: Array<{ slug: string; label: string; color: string; published: boolean }>;
            defaultStatus: string;
        };
    } | null;
    created_at: string;
    updated_at: string;
    fields?: Field[];
}

export interface Field {
    id: number;
    project_id: number;
    project_uuid: string;
    collection_id: number;
    name: string;
    slug: string;
    label: string;
    type: 'text' | 'longtext' | 'richtext' | 'slug' | 'email' | 'password' |
          'number' | 'enumeration' | 'boolean' | 'color' | 'date' | 'time' |
          'media' | 'relation' | 'json' | string;
    required: boolean;
    order?: number;
    description?: string;
    placeholder?: string;
    validations?: Record<string, any>;
    options?: {
        repeatable?: boolean;
        hideInContentList?: boolean;
        hiddenInAPI?: boolean;
        includeTime?: boolean;
        mode?: 'single' | 'range';
        editor?: {
            type: number;
        };
        enumeration?: {
            list: string[];
        };
        multiple?: boolean;
        relation?: {
            collection: number | null;
            collection_slug?: string;
            type: number;
        };
        slug?: {
            field: string | null;
            readonly: boolean;
        };
        media?: {
            type: number;
        };
        includeDraft?: boolean;
    };
    /** Champ verrouillé par le système (EndUser schema) — non éditable/supprimable */
    is_system?: boolean;
    created_at?: string;
    updated_at?: string;
}

export interface AssetMetadata {
    width?: number;
    height?: number;
    alt_text?: string;
    title?: string;
    caption?: string;
    description?: string;
    author?: string;
    copyright?: string;
  }
  
  export interface Asset {
    id: number;
    uuid: string;
    filename: string | null;
    original_filename: string | null;
    mime_type: string | null;
    extension: string;
    size: number;
    disk: string;
    path: string | null;
    url: string | null;
    full_url?: string | null;
    thumbnail_url: string | null;
    formatted_size: string;
    metadata: AssetMetadata | null;
    created_at: string;
    updated_at: string;
  }

export interface ProjectMemberRole {
    id: number;
    name: string;
    label: string;
}

export interface ProjectMemberUser {
    id: number;
    name: string;
    email: string;
}

export interface ProjectMember {
    id: number;
    user: ProjectMemberUser | null;
    role: ProjectMemberRole | null;
    email: string;
    status: 'active' | 'pending';
    joined_at: string | null;
    created_at: string;
}

export interface EndUser {
    uuid: string;
    email: string;
    name: string | null;
    status: 'active' | 'banned' | 'pending';
    avatar_url: string | null;
    custom_fields: Record<string, any> | null;
    created_at: string;
    updated_at: string;
    token_version: number;
}