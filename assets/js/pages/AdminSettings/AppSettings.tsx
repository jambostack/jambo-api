import { Head, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { CheckCircle2, Circle, Loader2 } from 'lucide-react';

import type { BreadcrumbItem, SharedData, AiProviderStatus } from '@/types/index.d';
import AppLayout from '@/layouts/app-layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

type FileField = 'logo' | 'logo_dark' | 'logo_light' | 'icon_dark' | 'icon_light' | 'favicon';
type ProviderName = 'openai' | 'anthropic' | 'deepseek' | 'ollama' | 'gemini' | 'openrouter' | 'mistral' | 'groq' | 'xai' | 'perplexity' | 'qwen';

interface ProviderState {
    enabled:    boolean;
    configured: boolean;
    editing:    boolean; // true = champ clé visible pour saisie
    key:   string;
    model: string;
    url:   string;
    saving: boolean;
    saved:  boolean;
    error:  string | null;
}

function initProvider(status: AiProviderStatus | undefined, defaultModel: string): ProviderState {
    const configured = status?.configured ?? false;
    return {
        enabled:    status?.enabled ?? false,
        configured,
        editing:    !configured, // si pas encore de clé, on ouvre directement le champ
        key:   '',
        model: status?.model ?? defaultModel,
        url:   status?.url   ?? '',
        saving: false,
        saved:  false,
        error:  null,
    };
}

export default function AppSettingsPage() {
    const t = useTranslation();
    const { appSettings } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('app_settings.breadcrumb'), href: '/admin/app-settings' },
    ];

    // ── Général ──────────────────────────────────────────────────────────
    const [appName, setAppName] = useState(appSettings.appName ?? '');
    const [saving, setSaving]   = useState(false);
    const [saved,  setSaved]    = useState(false);
    const [error,  setError]    = useState<string | null>(null);

    // ── Apparence ─────────────────────────────────────────────────────────
    const [previews, setPreviews] = useState<Record<FileField, string | null>>({
        logo:       appSettings.logoUrl,
        logo_dark:  appSettings.logoDarkUrl,
        logo_light: appSettings.logoLightUrl,
        icon_dark:  appSettings.iconDarkUrl,
        icon_light: appSettings.iconLightUrl,
        favicon:    appSettings.faviconUrl,
    });
    const fileRefs: Record<FileField, React.RefObject<HTMLInputElement>> = {
        logo:       useRef<HTMLInputElement>(null),
        logo_dark:  useRef<HTMLInputElement>(null),
        logo_light: useRef<HTMLInputElement>(null),
        icon_dark:  useRef<HTMLInputElement>(null),
        icon_light: useRef<HTMLInputElement>(null),
        favicon:    useRef<HTMLInputElement>(null),
    };

    // ── Fournisseurs IA ───────────────────────────────────────────────────
    const ai = appSettings.aiProviders;
    const [providers, setProviders] = useState<Record<ProviderName, ProviderState>>({
        openai:    initProvider(ai?.openai,    'gpt-4o'),
        anthropic: initProvider(ai?.anthropic, 'claude-sonnet-4-6'),
        deepseek:  initProvider(ai?.deepseek,  'deepseek-chat'),
        ollama:    initProvider(ai?.ollama,    'llama3.2'),
        gemini:    initProvider(ai?.gemini,    'gemini-2.0-flash'),
        openrouter: initProvider(ai?.openrouter, 'openai/gpt-4o'),
        mistral:   initProvider(ai?.mistral,   'mistral-large-latest'),
        groq:      initProvider(ai?.groq,      'llama-3.3-70b-versatile'),
        xai:       initProvider(ai?.xai,       'grok-2-latest'),
        perplexity: initProvider(ai?.perplexity, 'sonar-pro'),
        qwen:      initProvider(ai?.qwen,      'qwen-max'),
    });

    // ─── Helpers ──────────────────────────────────────────────────────────
    const post = async (body: BodyInit | null, isJson = false) => {
        const headers: Record<string, string> = { 'X-Requested-With': 'XMLHttpRequest' };
        if (isJson) headers['Content-Type'] = 'application/json';
        const res = await fetch('/admin/api/app-settings', { method: 'POST', headers, body });
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            throw new Error(Object.values(data?.errors ?? {}).join(', ') || t('common.error'));
        }
        return res.json();
    };

    const patchProvider = (name: ProviderName, patch: Partial<ProviderState>) =>
        setProviders(prev => ({ ...prev, [name]: { ...prev[name], ...patch } }));

    // ─── Général : save app name ──────────────────────────────────────────
    const handleNameSave = async () => {
        if (!appName.trim()) return;
        setSaving(true); setError(null);
        try {
            await post(JSON.stringify({ appName: appName.trim() }), true);
            setSaved(true); setTimeout(() => setSaved(false), 2000);
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : t('common.error'));
        } finally { setSaving(false); }
    };

    // ─── Apparence : file upload ──────────────────────────────────────────
    const handleFileUpload = async (field: FileField, file: File) => {
        setSaving(true); setError(null);
        const form = new FormData();
        form.append(field, file);
        try {
            const data = await post(form);
            const urlMap: Record<FileField, string | null> = {
                logo:       data.logoUrl,
                logo_dark:  data.logoDarkUrl,
                logo_light: data.logoLightUrl,
                icon_dark:  data.iconDarkUrl,
                icon_light: data.iconLightUrl,
                favicon:    data.faviconUrl,
            };
            setPreviews(prev => ({ ...prev, [field]: urlMap[field] }));
            if (fileRefs[field].current) fileRefs[field].current.value = '';
            setSaved(true); setTimeout(() => setSaved(false), 2000);
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : t('common.error'));
        } finally { setSaving(false); }
    };

    // ─── IA : toggle enabled (sauvegarde immédiate) ───────────────────────
    const handleToggle = async (name: ProviderName, enabled: boolean) => {
        patchProvider(name, { enabled, saving: true, error: null });
        try {
            const data = await post(JSON.stringify({ aiProviders: { [name]: { enabled } } }), true);
            const updated = data.aiProviders?.[name];
            patchProvider(name, {
                enabled:    updated?.enabled    ?? enabled,
                configured: updated?.configured ?? providers[name].configured,
                model:      updated?.model      ?? providers[name].model,
                url:        updated?.url        ?? providers[name].url,
                // Si on active et qu'il n'y a pas encore de clé → ouvrir le champ
                editing: enabled && !(updated?.configured ?? providers[name].configured),
                saving: false,
            });
        } catch (e: unknown) {
            patchProvider(name, { enabled: !enabled, saving: false, error: e instanceof Error ? e.message : t('common.error') });
        }
    };

    // ─── IA : enregistrer clé + modèle d'un provider ─────────────────────
    const handleProviderSave = async (name: ProviderName) => {
        const p = providers[name];
        patchProvider(name, { saving: true, error: null, saved: false });
        try {
            const payload: Record<string, any> = { enabled: p.enabled };
            if (p.key.trim())   payload.key   = p.key.trim();
            if (p.model.trim()) payload.model = p.model.trim();
            if (name === 'ollama' && p.url.trim()) payload.url = p.url.trim();

            const data = await post(JSON.stringify({ aiProviders: { [name]: payload } }), true);
            const updated = data.aiProviders?.[name];
            patchProvider(name, {
                configured: updated?.configured ?? p.configured,
                model:      updated?.model      ?? p.model,
                url:        updated?.url        ?? p.url,
                key:    '',      // vider le champ — la clé masquée s'affiche via l'UI
                editing: false,  // repasser en mode "clé masquée"
                saving: false,
                saved:  true,
            });
            setTimeout(() => patchProvider(name, { saved: false }), 2000);
        } catch (e: unknown) {
            patchProvider(name, { saving: false, error: e instanceof Error ? e.message : t('common.error') });
        }
    };

    // ─── Composant carte provider ─────────────────────────────────────────
    const ProviderCard = ({
        name, label, keyPlaceholder, showModel = true, showUrl = false,
    }: {
        name: ProviderName;
        label: string;
        keyPlaceholder: string;
        showModel?: boolean;
        showUrl?: boolean;
    }) => {
        const p = providers[name];

        return (
            <div className={cn(
                'rounded-lg border p-4 transition-colors',
                p.enabled ? 'border-primary/40 bg-primary/5' : 'border-border opacity-60',
            )}>
                {/* En-tête */}
                <div className="flex items-center justify-between mb-1">
                    <div className="flex items-center gap-2">
                        <span className="font-semibold text-sm">{label}</span>
                        {p.configured && p.enabled && (
                            <span className="inline-flex items-center gap-1 text-xs text-green-600 font-medium">
                                <CheckCircle2 className="h-3.5 w-3.5" />
                                {t('app_settings.ai.key_set')}
                            </span>
                        )}
                        {!p.configured && p.enabled && (
                            <span className="inline-flex items-center gap-1 text-xs text-amber-500 font-medium">
                                <Circle className="h-3.5 w-3.5" />
                                {t('app_settings.ai.key_missing')}
                            </span>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        {p.saving && <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />}
                        <Switch
                            checked={p.enabled}
                            onCheckedChange={v => handleToggle(name, v)}
                            disabled={p.saving}
                            aria-label={`Activer ${label}`}
                        />
                    </div>
                </div>
                <p className="text-xs text-muted-foreground mb-4">
                    {p.enabled ? t('app_settings.ai.provider_active') : t('app_settings.ai.provider_inactive')}
                </p>

                {/* Formulaire — visible uniquement quand activé */}
                {p.enabled && (
                    <div className="space-y-3 pt-3 border-t">

                        {/* ── Clé API (ou URL Ollama) ─────────────────── */}
                        {!showUrl && (
                            <div className="space-y-1.5">
                                <Label className="text-xs">{t('app_settings.ai.api_key')}</Label>

                                {/* Clé enregistrée → affichage masqué + bouton Modifier */}
                                {p.configured && !p.editing ? (
                                    <div className="flex items-center gap-2">
                                        <div className="flex-1 flex items-center gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                            <CheckCircle2 className="h-3.5 w-3.5 text-green-600 shrink-0" />
                                            <span className="tracking-widest">••••••••••••••••</span>
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => patchProvider(name, { editing: true, key: '' })}
                                        >
                                            {t('app_settings.ai.edit_key')}
                                        </Button>
                                    </div>
                                ) : (
                                    /* Pas encore de clé, ou mode édition */
                                    <div className="flex items-center gap-2">
                                        <Input
                                            type="password"
                                            placeholder={keyPlaceholder}
                                            value={p.key}
                                            onChange={e => patchProvider(name, { key: e.target.value })}
                                            autoComplete="off"
                                            autoFocus={p.editing && p.configured}
                                            className="flex-1"
                                        />
                                        {p.configured && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => patchProvider(name, { editing: false, key: '' })}
                                            >
                                                {t('common.cancel')}
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}

                        {showUrl && (
                            <div className="space-y-1.5">
                                <Label className="text-xs">{t('app_settings.ai.ollama_url')}</Label>
                                <Input
                                    placeholder="http://localhost:11434"
                                    value={p.url}
                                    onChange={e => patchProvider(name, { url: e.target.value })}
                                />
                            </div>
                        )}

                        {/* ── Modèle par défaut ───────────────────────── */}
                        {showModel && (
                            <div className="space-y-1.5">
                                <Label className="text-xs">{t('app_settings.ai.default_model')}</Label>
                                <Input
                                    value={p.model}
                                    onChange={e => patchProvider(name, { model: e.target.value })}
                                />
                            </div>
                        )}

                        {/* ── Bouton Enregistrer ──────────────────────── */}
                        {/* Masqué si la clé est déjà configurée et qu'on n'est pas en mode édition */}
                        {(!p.configured || p.editing || showUrl) && (
                            <div className="flex items-center gap-3 pt-1">
                                <Button
                                    size="sm"
                                    onClick={() => handleProviderSave(name)}
                                    disabled={p.saving || (!showUrl && !p.key.trim() && !p.configured)}
                                >
                                    {p.saving
                                        ? <><Loader2 className="h-3.5 w-3.5 mr-1 animate-spin" />{t('common.loading')}</>
                                        : p.saved
                                        ? t('common.saved')
                                        : t('app_settings.ai.save_provider')}
                                </Button>
                                {p.error && <p className="text-destructive text-xs">{p.error}</p>}
                            </div>
                        )}

                        {/* Feedback "Enregistré" même hors mode édition (pour le modèle) */}
                        {p.saved && !p.editing && (
                            <p className="text-xs text-green-600">{t('common.saved')}</p>
                        )}
                    </div>
                )}
            </div>
        );
    };

    // ─── Rendu ────────────────────────────────────────────────────────────
    const logoSection = (field: FileField, labelKey: string, hintKey: string, acceptIco = false) => (
        <section className="space-y-3">
            <Label>{t(labelKey)}</Label>
            {previews[field] && (
                <img src={previews[field]!} alt={t(labelKey)} className="h-16 object-contain border rounded p-1 bg-white dark:bg-zinc-800" />
            )}
            <Input
                type="file"
                accept={acceptIco ? 'image/*,.ico' : 'image/*'}
                ref={fileRefs[field]}
                onChange={e => e.target.files?.[0] && handleFileUpload(field, e.target.files[0])}
            />
            <p className="text-sm text-muted-foreground">{t(hintKey)}</p>
        </section>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('app_settings.breadcrumb')} />

            <div className="px-4 py-6 max-w-2xl">
                <HeadingSmall
                    title={t('app_settings.heading')}
                    description={t('app_settings.heading_desc')}
                />

                <Tabs defaultValue="general" className="mt-6">
                    <TabsList className="mb-6">
                        <TabsTrigger value="general">{t('app_settings.tab_general')}</TabsTrigger>
                        <TabsTrigger value="branding">{t('app_settings.tab_branding')}</TabsTrigger>
                        <TabsTrigger value="ai">
                            {t('app_settings.tab_ai')}
                            {Object.values(providers).filter(p => p.enabled).length > 0 && (
                                <span className="ml-1.5 inline-flex h-4 w-4 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">
                                    {Object.values(providers).filter(p => p.enabled).length}
                                </span>
                            )}
                        </TabsTrigger>
                    </TabsList>

                    {/* ── Onglet Général ───────────────────────────────── */}
                    <TabsContent value="general" className="space-y-6">
                        <section className="space-y-3">
                            <Label htmlFor="appName">{t('app_settings.app_name')}</Label>
                            <div className="flex gap-2">
                                <Input
                                    id="appName"
                                    value={appName}
                                    onChange={e => setAppName(e.target.value)}
                                    placeholder={t('app_settings.app_name_placeholder')}
                                    maxLength={100}
                                />
                                <Button onClick={handleNameSave} disabled={saving || !appName.trim()}>
                                    {saving ? t('common.loading') : saved ? t('common.saved') : t('common.save')}
                                </Button>
                            </div>
                            {error && <p className="text-destructive text-sm">{error}</p>}
                        </section>
                    </TabsContent>

                    {/* ── Onglet Apparence ─────────────────────────────── */}
                    <TabsContent value="branding" className="space-y-8">
                        <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide">{t('app_settings.section_logos')}</p>
                        {logoSection('logo_dark',  'app_settings.logo_dark',  'app_settings.logo_dark_hint')}
                        {logoSection('logo_light', 'app_settings.logo_light', 'app_settings.logo_light_hint')}
                        <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide pt-2">{t('app_settings.section_icons')}</p>
                        {logoSection('icon_dark',  'app_settings.icon_dark',  'app_settings.icon_dark_hint')}
                        {logoSection('icon_light', 'app_settings.icon_light', 'app_settings.icon_light_hint')}
                        <p className="text-xs font-semibold text-muted-foreground uppercase tracking-wide pt-2">{t('app_settings.section_favicon')}</p>
                        {logoSection('favicon', 'app_settings.favicon', 'app_settings.favicon_hint', true)}
                        {error && <p className="text-destructive text-sm">{error}</p>}
                    </TabsContent>

                    {/* ── Onglet Fournisseurs IA ───────────────────────── */}
                    <TabsContent value="ai" className="space-y-4">
                        <p className="text-sm text-muted-foreground pb-2">{t('app_settings.ai.description')}</p>

                        <ProviderCard
                            name="openai"
                            label="OpenAI"
                            keyPlaceholder="sk-..."
                        />
                        <ProviderCard
                            name="anthropic"
                            label="Anthropic (Claude)"
                            keyPlaceholder="sk-ant-..."
                        />
                        <ProviderCard
                            name="deepseek"
                            label="DeepSeek"
                            keyPlaceholder="sk-..."
                            showModel={false}
                        />
                        <ProviderCard
                            name="ollama"
                            label="Ollama (local)"
                            keyPlaceholder=""
                            showUrl={true}
                        />
                        <ProviderCard
                            name="gemini"
                            label="Google Gemini"
                            keyPlaceholder="AIza..."
                        />
                        <ProviderCard
                            name="openrouter"
                            label="OpenRouter"
                            keyPlaceholder="sk-or-v1-..."
                        />
                        <ProviderCard
                            name="mistral"
                            label="Mistral AI"
                            keyPlaceholder="..."
                        />
                        <ProviderCard
                            name="groq"
                            label="Groq"
                            keyPlaceholder="gsk_..."
                        />
                        <ProviderCard
                            name="xai"
                            label="xAI (Grok)"
                            keyPlaceholder="xai-..."
                        />
                        <ProviderCard
                            name="perplexity"
                            label="Perplexity"
                            keyPlaceholder="pplx-..."
                        />
                        <ProviderCard
                            name="qwen"
                            label="Qwen (Alibaba)"
                            keyPlaceholder="sk-..."
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
