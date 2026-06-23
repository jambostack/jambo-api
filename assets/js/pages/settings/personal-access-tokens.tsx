import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types/index.d';
import { Head } from '@inertiajs/react';
import { Suspense, lazy, useEffect, useState } from 'react';
import { Copy, KeyRound, Loader2, Plus, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import { useTranslation } from '@/lib/i18n';

const SwaggerUI = lazy(() => import('@/pages/Projects/Settings/SwaggerLoader'));
const ADMIN_API_SPEC_URL = '/api/settings/admin-api/openapi.json';

interface Pat {
    id: number;
    name: string;
    scopes: string[];
    lastUsedAt: string | null;
    expiresAt: string | null;
    createdAt: string;
}

const ALL_SCOPES: { value: string; labelKey: string }[] = [
    { value: 'schema:write',   labelKey: 'settings.tokens.scope_schema' },
    { value: 'projects:write', labelKey: 'settings.tokens.scope_projects' },
];

const ENDPOINT = '/api/settings/personal-access-tokens';

/** Opérations exposées par l'Admin API (base /admin-api), authentifiées par Bearer PAT.
 *  `scope` = permission requise sur le jeton (null = aucun scope, jeton valide suffit). */
const ADMIN_API_OPERATIONS: { method: string; path: string; scope: string | null }[] = [
    { method: 'GET',    path: '/projects',                                                 scope: null },
    { method: 'POST',   path: '/projects',                                                 scope: 'projects:write' },
    { method: 'GET',    path: '/projects/{uuid}',                                           scope: null },
    { method: 'PATCH',  path: '/projects/{uuid}',                                           scope: 'projects:write' },
    { method: 'DELETE', path: '/projects/{uuid}',                                           scope: 'projects:write' },
    { method: 'GET',    path: '/projects/{uuid}/collections',                               scope: null },
    { method: 'POST',   path: '/projects/{uuid}/collections',                               scope: 'schema:write' },
    { method: 'GET',    path: '/projects/{uuid}/collections/{slug}',                        scope: null },
    { method: 'PATCH',  path: '/projects/{uuid}/collections/{slug}',                        scope: 'schema:write' },
    { method: 'DELETE', path: '/projects/{uuid}/collections/{slug}',                        scope: 'schema:write' },
    { method: 'GET',    path: '/projects/{uuid}/collections/{slug}/fields',                 scope: null },
    { method: 'POST',   path: '/projects/{uuid}/collections/{slug}/fields',                 scope: 'schema:write' },
    { method: 'PATCH',  path: '/projects/{uuid}/collections/{slug}/fields/{fieldSlug}',     scope: 'schema:write' },
    { method: 'DELETE', path: '/projects/{uuid}/collections/{slug}/fields/{fieldSlug}',     scope: 'schema:write' },
    { method: 'GET',    path: '/tokens',                                                    scope: null },
    { method: 'POST',   path: '/tokens',                                                    scope: null },
    { method: 'PATCH',  path: '/tokens/{id}',                                               scope: null },
    { method: 'DELETE', path: '/tokens/{id}',                                               scope: null },
];

const METHOD_COLORS: Record<string, string> = {
    GET:    'bg-emerald-500/15 text-emerald-500',
    POST:   'bg-blue-500/15 text-blue-500',
    PATCH:  'bg-amber-500/15 text-amber-500',
    DELETE: 'bg-red-500/15 text-red-500',
};

export default function PersonalAccessTokens() {
    const t = useTranslation();

    const adminApiEndpoint = `${typeof window !== 'undefined' ? window.location.origin : ''}/admin-api`;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('settings.tokens.breadcrumb'), href: '/settings/personal-access-tokens' },
    ];

    const [tokens, setTokens] = useState<Pat[]>([]);
    const [loading, setLoading] = useState(true);
    const [name, setName] = useState('');
    const [scopes, setScopes] = useState<string[]>(['schema:write']);
    const [expiresAt, setExpiresAt] = useState('');
    const [creating, setCreating] = useState(false);
    const [plainToken, setPlainToken] = useState<string | null>(null);

    // Documentation interactive (Swagger) — chargée à la demande.
    const [showDocs, setShowDocs] = useState(false);
    const [spec, setSpec] = useState<unknown>(null);
    const [specError, setSpecError] = useState(false);

    useEffect(() => {
        if (!showDocs || spec) return;
        fetch(ADMIN_API_SPEC_URL, { headers: { Accept: 'application/json' } })
            .then(res => { if (!res.ok) throw new Error(); return res.json(); })
            .then(setSpec)
            .catch(() => setSpecError(true));
    }, [showDocs, spec]);

    async function load() {
        setLoading(true);
        try {
            const res = await fetch(ENDPOINT, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error();
            const data = await res.json();
            setTokens(Array.isArray(data.data) ? data.data : []);
        } catch {
            toast.error(t('settings.tokens.load_failed'));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => { load(); }, []);

    const toggleScope = (value: string) =>
        setScopes(prev => (prev.includes(value) ? prev.filter(s => s !== value) : [...prev, value]));

    async function create(e: React.FormEvent) {
        e.preventDefault();
        if (!name.trim()) return;
        setCreating(true);
        try {
            const res = await fetch(ENDPOINT, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({ name: name.trim(), scopes, expires_at: expiresAt || undefined }),
            });
            if (!res.ok) throw new Error();
            const data = await res.json();
            setPlainToken(data.data.token);
            setName('');
            setScopes(['schema:write']);
            setExpiresAt('');
            toast.success(t('settings.tokens.created'));
            load();
        } catch {
            toast.error(t('settings.tokens.create_failed'));
        } finally {
            setCreating(false);
        }
    }

    async function revoke(id: number) {
        if (!window.confirm(t('settings.tokens.revoke_confirm'))) return;
        try {
            const res = await fetch(`${ENDPOINT}/${id}`, { method: 'DELETE', headers: { Accept: 'application/json' } });
            if (!res.ok && res.status !== 204) throw new Error();
            toast.success(t('settings.tokens.revoked'));
            setTokens(prev => prev.filter(tk => tk.id !== id));
        } catch {
            toast.error(t('settings.tokens.revoke_failed'));
        }
    }

    async function copy(value: string) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
            } else {
                const ta = document.createElement('textarea');
                ta.value = value;
                ta.style.position = 'fixed';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
            toast.success(t('settings.tokens.copied'));
        } catch {
            toast.error(t('settings.tokens.copy_failed'));
        }
    }

    const fmtDate = (iso: string | null) =>
        iso ? new Date(iso).toLocaleString() : t('settings.tokens.never_used');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('settings.tokens.breadcrumb')} />

            <SettingsLayout>
                <div className="space-y-8">
                    <HeadingSmall title={t('settings.tokens.heading')} description={t('settings.tokens.description')} />

                    {/* Endpoint de l'Admin API — à copier dans le client / MCP */}
                    <div className="grid gap-2">
                        <Label>{t('settings.tokens.endpoint')}</Label>
                        <div className="flex items-center gap-2">
                            <Input readOnly value={adminApiEndpoint} className="font-mono text-xs" />
                            <Button type="button" variant="outline" size="icon" onClick={() => copy(adminApiEndpoint)} title={t('settings.tokens.copied')}>
                                <Copy className="h-4 w-4" />
                            </Button>
                        </div>
                        <p className="text-xs text-muted-foreground">{t('settings.tokens.endpoint_hint')}</p>
                    </div>

                    {/* Opérations de l'Admin API */}
                    <details className="rounded-lg border">
                        <summary className="cursor-pointer select-none px-4 py-3 text-sm font-medium">
                            {t('settings.tokens.operations_title')}
                        </summary>
                        <ul className="divide-y border-t">
                            {ADMIN_API_OPERATIONS.map((op, i) => (
                                <li key={i} className="flex items-center gap-3 px-4 py-2">
                                    <span className={`inline-block w-16 shrink-0 rounded px-1.5 py-0.5 text-center font-mono text-[10px] font-semibold ${METHOD_COLORS[op.method] ?? 'bg-muted text-muted-foreground'}`}>
                                        {op.method}
                                    </span>
                                    <code className="flex-1 truncate font-mono text-xs">/admin-api{op.path}</code>
                                    {op.scope && (
                                        <code className="shrink-0 rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] text-muted-foreground" title={t('settings.tokens.scope_required')}>
                                            {op.scope}
                                        </code>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <p className="border-t px-4 py-3 text-xs text-muted-foreground">
                            ⚠️ {t('settings.tokens.operations_note')}
                        </p>
                    </details>

                    {/* Documentation interactive (Swagger) avec « Try it out » */}
                    <div className="space-y-3">
                        <div className="flex items-center justify-between gap-2">
                            <div>
                                <h3 className="text-sm font-medium">{t('settings.tokens.docs_title')}</h3>
                                <p className="text-xs text-muted-foreground">{t('settings.tokens.docs_hint')}</p>
                            </div>
                            <Button type="button" variant="outline" size="sm" onClick={() => setShowDocs(v => !v)}>
                                {showDocs ? t('settings.tokens.docs_hide') : t('settings.tokens.docs_open')}
                            </Button>
                        </div>

                        {showDocs && (
                            <div className="rounded-lg border bg-white">
                                {specError ? (
                                    <p className="p-4 text-sm text-destructive">{t('settings.tokens.docs_error')}</p>
                                ) : !spec ? (
                                    <div className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
                                        <Loader2 className="h-4 w-4 animate-spin" /> {t('common.loading')}
                                    </div>
                                ) : (
                                    <Suspense fallback={<div className="p-4 text-sm text-muted-foreground">{t('common.loading')}</div>}>
                                        <SwaggerUI spec={spec} tryItOutEnabled />
                                    </Suspense>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Jeton fraîchement créé — affiché une seule fois */}
                    {plainToken && (
                        <div className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 space-y-3">
                            <p className="text-sm font-medium">{t('settings.tokens.new_token_notice')}</p>
                            <div className="flex items-center gap-2">
                                <code className="flex-1 truncate rounded bg-background px-3 py-2 font-mono text-xs">{plainToken}</code>
                                <Button type="button" variant="outline" size="sm" onClick={() => copy(plainToken)}>
                                    <Copy className="h-4 w-4" />
                                </Button>
                            </div>
                            <Button type="button" variant="ghost" size="sm" onClick={() => setPlainToken(null)}>
                                {t('common.ok')}
                            </Button>
                        </div>
                    )}

                    {/* Formulaire de création */}
                    <form onSubmit={create} className="space-y-4">
                        <h3 className="text-sm font-medium">{t('settings.tokens.create_title')}</h3>

                        <div className="grid gap-2">
                            <Label htmlFor="pat-name">{t('settings.tokens.name')}</Label>
                            <Input
                                id="pat-name"
                                value={name}
                                onChange={e => setName(e.target.value)}
                                placeholder={t('settings.tokens.name_placeholder')}
                                maxLength={120}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label>{t('settings.tokens.scopes')}</Label>
                            <div className="space-y-2">
                                {ALL_SCOPES.map(s => (
                                    <label key={s.value} className="flex items-center gap-2 text-sm cursor-pointer">
                                        <Checkbox
                                            checked={scopes.includes(s.value)}
                                            onCheckedChange={() => toggleScope(s.value)}
                                        />
                                        <span>{t(s.labelKey)}</span>
                                        <code className="font-mono text-xs text-muted-foreground">{s.value}</code>
                                    </label>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="pat-expires">{t('settings.tokens.expires')}</Label>
                            <Input
                                id="pat-expires"
                                type="date"
                                value={expiresAt}
                                onChange={e => setExpiresAt(e.target.value)}
                                className="w-auto"
                            />
                        </div>

                        <Button type="submit" disabled={creating || !name.trim()}>
                            {creating ? <Loader2 className="h-4 w-4 animate-spin" /> : <Plus className="h-4 w-4" />}
                            {t('settings.tokens.create')}
                        </Button>
                    </form>

                    {/* Liste des jetons existants */}
                    <div className="space-y-3">
                        <h3 className="text-sm font-medium">{t('settings.tokens.list_title')}</h3>

                        {loading ? (
                            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                <Loader2 className="h-4 w-4 animate-spin" /> {t('common.loading')}
                            </div>
                        ) : tokens.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{t('settings.tokens.empty')}</p>
                        ) : (
                            <ul className="divide-y rounded-lg border">
                                {tokens.map(tk => (
                                    <li key={tk.id} className="flex items-start justify-between gap-4 p-4">
                                        <div className="min-w-0 space-y-1">
                                            <div className="flex items-center gap-2">
                                                <KeyRound className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                <span className="font-medium truncate">{tk.name}</span>
                                            </div>
                                            <div className="flex flex-wrap gap-1">
                                                {tk.scopes.map(s => (
                                                    <code key={s} className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px]">{s}</code>
                                                ))}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {t('settings.tokens.created_at')}: {fmtDate(tk.createdAt)}
                                                {' · '}
                                                {t('settings.tokens.last_used')}: {fmtDate(tk.lastUsedAt)}
                                                {tk.expiresAt && (<> {' · '}{t('settings.tokens.expires')}: {fmtDate(tk.expiresAt)}</>)}
                                            </p>
                                        </div>
                                        <Button type="button" variant="ghost" size="sm" onClick={() => revoke(tk.id)} title={t('settings.tokens.revoke')}>
                                            <Trash2 className="h-4 w-4 text-destructive" />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
