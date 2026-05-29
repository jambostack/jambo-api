import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    Copy,
    Plug,
    ShieldCheck,
    Search,
    FileText,
    Database,
    Image as ImageIcon,
    Users as UsersIcon,
    Sparkles,
    ExternalLink,
    Terminal,
    Code2,
    Zap,
} from 'lucide-react';

import type { Project, BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import ProjectSettingsLayout from './layout';
import HeadingSmall from '@/components/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/lib/i18n';

interface Props {
    project: Project;
    tokens: Array<{ id: number; name: string; abilities: string[]; created_at: string }>;
}

export default function McpAccessSettings({ project, tokens }: Props) {
    const t = useTranslation();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
        { title: t('projects.settings.nav_mcp_access'), href: route('projects.settings.mcp-access', project.id) },
    ];

    const mcpProjectUrl = `${window.location.origin}/api/projects/${project.uuid}/mcp`;
    const mcpGlobalUrl = `${window.location.origin}/mcp`;
    const mcpInfoUrl = `${window.location.origin}/mcp`;

    const [copiedKey, setCopiedKey] = useState<string | null>(null);

    const copy = async (value: string, key: string) => {
        let success = false;
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                success = true;
            } catch { /* fallthrough */ }
        }
        if (!success) {
            const el = document.createElement('textarea');
            el.value = value;
            el.setAttribute('readonly', '');
            el.style.cssText = 'position:absolute;left:-9999px;top:-9999px';
            document.body.appendChild(el);
            el.select();
            el.setSelectionRange(0, el.value.length);
            success = document.execCommand('copy');
            document.body.removeChild(el);
        }
        if (success) {
            toast.success(t('projects.settings.mcp.copied'));
            setCopiedKey(key);
            setTimeout(() => setCopiedKey(null), 1500);
        } else {
            toast.error(t('projects.settings.mcp.copy_failed'));
        }
    };

    const toolGroups: Array<{
        key: string;
        icon: typeof Search;
        color: string;
        count: number;
    }> = [
        { key: 'exploration', icon: Search, color: 'from-blue-500/15 to-cyan-500/10 text-blue-600 dark:text-blue-400', count: 3 },
        { key: 'content', icon: FileText, color: 'from-emerald-500/15 to-teal-500/10 text-emerald-600 dark:text-emerald-400', count: 7 },
        { key: 'schema', icon: Database, color: 'from-violet-500/15 to-fuchsia-500/10 text-violet-600 dark:text-violet-400', count: 5 },
        { key: 'media', icon: ImageIcon, color: 'from-orange-500/15 to-amber-500/10 text-orange-600 dark:text-orange-400', count: 3 },
        { key: 'users', icon: UsersIcon, color: 'from-rose-500/15 to-pink-500/10 text-rose-600 dark:text-rose-400', count: 2 },
        { key: 'ai', icon: Sparkles, color: 'from-purple-500/15 to-indigo-500/10 text-purple-600 dark:text-purple-400', count: 6 },
    ];

    const claudeDesktopConfig = `{
  "mcpServers": {
    "${project.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}": {
      "url": "${mcpProjectUrl}",
      "transport": "http",
      "headers": {
        "Authorization": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}`;

    const cursorConfig = `{
  "mcp": {
    "servers": {
      "${project.name.toLowerCase().replace(/[^a-z0-9]+/g, '-')}": {
        "url": "${mcpProjectUrl}",
        "auth": "Bearer YOUR_API_TOKEN"
      }
    }
  }
}`;

    const curlExample = `curl -X POST ${mcpProjectUrl} \\
  -H "Content-Type: application/json" \\
  -H "Authorization: Bearer YOUR_API_TOKEN" \\
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list"
  }'`;

    const Field = ({ label, value, copyKey }: { label: string; value: string; copyKey: string }) => (
        <div className="space-y-2">
            <HeadingSmall title={label} />
            <div className="flex gap-2">
                <Input readOnly value={value} className="font-mono text-xs" />
                <Button variant="outline" size="icon" onClick={() => copy(value, copyKey)}>
                    <Copy className={`w-4 h-4 transition ${copiedKey === copyKey ? 'text-emerald-500' : ''}`} />
                </Button>
            </div>
        </div>
    );

    const CodeBlock = ({ code, copyKey }: { code: string; copyKey: string }) => (
        <div className="relative group">
            <pre className="rounded-lg border bg-muted/40 p-4 pr-12 overflow-x-auto text-xs font-mono leading-relaxed">
                <code>{code}</code>
            </pre>
            <Button
                variant="ghost"
                size="icon"
                className="absolute top-2 right-2 h-7 w-7 opacity-0 group-hover:opacity-100 transition"
                onClick={() => copy(code, copyKey)}
            >
                <Copy className={`w-3.5 h-3.5 ${copiedKey === copyKey ? 'text-emerald-500' : ''}`} />
            </Button>
        </div>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('projects.settings.mcp.title')} />

            <ProjectSettingsLayout project={project}>
                <div className="max-w-3xl">
                    {/* Hero */}
                    <div className="relative overflow-hidden rounded-xl border bg-gradient-to-br from-violet-500/5 via-fuchsia-500/5 to-cyan-500/5 p-6 mb-8">
                        <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,theme(colors.violet.500)/0.08,transparent_60%)]" />
                        <div className="relative flex items-start gap-4">
                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-500 text-white shadow-lg shadow-violet-500/20">
                                <Plug className="h-6 w-6" />
                            </div>
                            <div className="flex-1">
                                <h2 className="text-lg font-semibold tracking-tight">{t('projects.settings.mcp.hero_title')}</h2>
                                <p className="mt-1 text-sm text-muted-foreground">{t('projects.settings.mcp.hero_desc')}</p>
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Badge variant="secondary" className="gap-1"><Zap className="w-3 h-3" />26 tools</Badge>
                                    <Badge variant="secondary" className="gap-1"><ShieldCheck className="w-3 h-3" />Bearer auth</Badge>
                                    <Badge variant="secondary">JSON-RPC 2.0</Badge>
                                </div>
                            </div>
                        </div>
                    </div>

                    <Tabs defaultValue="endpoint">
                        <TabsList className="mb-6">
                            <TabsTrigger value="endpoint">{t('projects.settings.mcp.tab_endpoint')}</TabsTrigger>
                            <TabsTrigger value="tools">{t('projects.settings.mcp.tab_tools')}</TabsTrigger>
                            <TabsTrigger value="clients">{t('projects.settings.mcp.tab_clients')}</TabsTrigger>
                        </TabsList>

                        {/* ── Tab Endpoint ── */}
                        <TabsContent value="endpoint" className="space-y-6">
                            <Field
                                label={t('projects.settings.mcp.project_endpoint')}
                                value={mcpProjectUrl}
                                copyKey="project-endpoint"
                            />
                            <p className="text-xs text-muted-foreground -mt-3">{t('projects.settings.mcp.project_endpoint_hint')}</p>

                            <Separator />

                            <Field
                                label={t('projects.settings.mcp.global_endpoint')}
                                value={mcpGlobalUrl}
                                copyKey="global-endpoint"
                            />
                            <p className="text-xs text-muted-foreground -mt-3">{t('projects.settings.mcp.global_endpoint_hint')}</p>

                            <Separator />

                            <div>
                                <HeadingSmall title={t('projects.settings.mcp.auth_title')} />
                                <p className="text-sm text-muted-foreground mb-4">{t('projects.settings.mcp.auth_desc')}</p>

                                <div className="rounded-lg border bg-muted/30 p-4 flex items-start gap-3">
                                    <ShieldCheck className="w-5 h-5 text-emerald-600 dark:text-emerald-400 shrink-0 mt-0.5" />
                                    <div className="flex-1">
                                        <p className="text-sm font-medium">
                                            {tokens.length === 0
                                                ? t('projects.settings.mcp.no_tokens')
                                                : t('projects.settings.mcp.tokens_count', { count: String(tokens.length) })}
                                        </p>
                                        <p className="text-xs text-muted-foreground mt-1">
                                            {t('projects.settings.mcp.token_hint')}
                                        </p>
                                        <Button asChild variant="link" size="sm" className="px-0 h-auto mt-2">
                                            <Link href={route('projects.settings.api-access', project.id)}>
                                                {t('projects.settings.mcp.manage_tokens')}
                                                <ExternalLink className="w-3 h-3 ms-1" />
                                            </Link>
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            <Separator />

                            <div>
                                <HeadingSmall title={t('projects.settings.mcp.test_title')} />
                                <p className="text-sm text-muted-foreground mb-3">{t('projects.settings.mcp.test_desc')}</p>
                                <CodeBlock code={curlExample} copyKey="curl" />
                            </div>
                        </TabsContent>

                        {/* ── Tab Tools ── */}
                        <TabsContent value="tools" className="space-y-4">
                            <p className="text-sm text-muted-foreground">{t('projects.settings.mcp.tools_desc')}</p>
                            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                {toolGroups.map(({ key, icon: Icon, color, count }) => (
                                    <div
                                        key={key}
                                        className={`relative overflow-hidden rounded-xl border bg-gradient-to-br ${color} p-4 transition hover:shadow-md`}
                                    >
                                        <div className="flex items-start justify-between">
                                            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-background/60 backdrop-blur">
                                                <Icon className="w-4 h-4" />
                                            </div>
                                            <Badge variant="secondary" className="text-[10px]">{count}</Badge>
                                        </div>
                                        <h4 className="mt-3 font-semibold text-sm">
                                            {t(`projects.settings.mcp.tool_group.${key}.title`)}
                                        </h4>
                                        <p className="mt-1 text-xs text-foreground/70 leading-relaxed">
                                            {t(`projects.settings.mcp.tool_group.${key}.desc`)}
                                        </p>
                                    </div>
                                ))}
                            </div>

                            <div className="rounded-lg border bg-muted/20 p-4 mt-4">
                                <p className="text-xs text-muted-foreground">
                                    {t('projects.settings.mcp.tools_discover')}{' '}
                                    <code className="text-xs px-1.5 py-0.5 rounded bg-muted">tools/list</code>
                                </p>
                            </div>
                        </TabsContent>

                        {/* ── Tab Clients ── */}
                        <TabsContent value="clients" className="space-y-6">
                            <div>
                                <div className="flex items-center gap-2 mb-3">
                                    <Terminal className="w-4 h-4" />
                                    <h3 className="font-semibold text-sm">Claude Desktop</h3>
                                </div>
                                <p className="text-xs text-muted-foreground mb-3">
                                    {t('projects.settings.mcp.claude_desktop_hint')}{' '}
                                    <code className="text-xs px-1.5 py-0.5 rounded bg-muted">~/Library/Application Support/Claude/claude_desktop_config.json</code>
                                </p>
                                <CodeBlock code={claudeDesktopConfig} copyKey="claude-desktop" />
                            </div>

                            <Separator />

                            <div>
                                <div className="flex items-center gap-2 mb-3">
                                    <Code2 className="w-4 h-4" />
                                    <h3 className="font-semibold text-sm">Cursor / VS Code</h3>
                                </div>
                                <p className="text-xs text-muted-foreground mb-3">
                                    {t('projects.settings.mcp.cursor_hint')}
                                </p>
                                <CodeBlock code={cursorConfig} copyKey="cursor" />
                            </div>

                            <Separator />

                            <div className="rounded-lg border bg-muted/20 p-4">
                                <h4 className="text-sm font-semibold mb-2 flex items-center gap-2">
                                    <ExternalLink className="w-3.5 h-3.5" />
                                    {t('projects.settings.mcp.info_endpoint_title')}
                                </h4>
                                <p className="text-xs text-muted-foreground mb-2">
                                    {t('projects.settings.mcp.info_endpoint_desc')}
                                </p>
                                <code className="text-xs px-2 py-1 rounded bg-background border block">
                                    GET {mcpInfoUrl}
                                </code>
                            </div>
                        </TabsContent>
                    </Tabs>
                </div>
            </ProjectSettingsLayout>
        </AppLayout>
    );
}
