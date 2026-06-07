import { useState, useMemo, useEffect } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Copy, Check, Download, KeyRound, Database, Link2, Sparkles, Terminal, ListTree, UserCog, ShieldCheck } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project, Collection } from '@/types/index.d';

type AiField = { slug: string; type: string; isRequired?: boolean };
type AiCol = { name: string; slug: string; description?: string; isSingleton?: boolean; fields?: AiField[] };

interface EndUserField {
  slug: string; name: string; type: string; isRequired?: boolean;
}

interface EndUserInfo {
  fields: EndUserField[];
  total?: number;
}

export default function AiGuide({ project, collections: rawCollections }: { project: Project; collections: Collection[] }) {
  const t = useTranslation();
  const collections = rawCollections as unknown as AiCol[];
  const [copied, setCopied] = useState(false);

  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const apiBase = `${origin}/api/${project.uuid}`;
  const authBase = `${origin}/api/${project.uuid}/auth`;
  const adminEndUsersBase = `${origin}/api/projects/${project.uuid}/end-users`;
  const locale = (project as unknown as { defaultLocale?: string }).defaultLocale ?? 'en';

  const [endUsers, setEndUsers] = useState<EndUserInfo>({ fields: [] });

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        // Fetch end-user schema (custom fields)
        const schemaRes = await fetch(`/api/projects/${project.uuid}/end-users/fields`);
        if (schemaRes.ok) {
          const schemaData = await schemaRes.json() as { data?: EndUserField[] };
          if (!cancelled && Array.isArray(schemaData.data)) {
            setEndUsers({ fields: schemaData.data });
          }
        }
        // Count end-users
        const listRes = await fetch(`${adminEndUsersBase}?per_page=1`);
        if (listRes.ok) {
          const listData = await listRes.json() as { meta?: { total: number } };
          if (!cancelled && listData.meta?.total) {
            setEndUsers(prev => ({ ...prev, total: listData.meta.total }));
          }
        }
      } catch { /* garde les valeurs par défaut */ }
    })();
    return () => { cancelled = true; };
  }, [project.uuid, adminEndUsersBase]);

  const totalFields = useMemo(
    () => collections.reduce((n, c) => n + (c.fields?.length ?? 0), 0),
    [collections],
  );

  const euSystemFields: EndUserField[] = [
    { slug: 'email', name: 'Email', type: 'email', isRequired: true },
    { slug: 'password', name: 'Password', type: 'password', isRequired: true },
    { slug: 'name', name: 'Name', type: 'text', isRequired: false },
    { slug: 'status', name: 'Status', type: 'enumeration', isRequired: true },
  ];

  const allEuFields = [...euSystemFields, ...(endUsers.fields ?? [])];

  const doc = useMemo(
    () => buildContextDoc(project, collections, apiBase, authBase, adminEndUsersBase, locale, t, allEuFields, endUsers.total ?? 0),
    [project, collections, apiBase, authBase, adminEndUsersBase, locale, t, allEuFields, endUsers],
  );

  async function copy() {
    try { await navigator.clipboard.writeText(doc); setCopied(true); setTimeout(() => setCopied(false), 2000); } catch { /* ignore */ }
  }
  function download() {
    const blob = new Blob([doc], { type: 'text/markdown' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = `jambo-${project.uuid}-context.md`; a.click();
    URL.revokeObjectURL(url);
  }

  const facts: Array<{ icon: typeof Database; label: string; value: string; mono?: boolean }> = [
    { icon: Database, label: t('studio.aiguide.fact_name'), value: project.name },
    { icon: Link2, label: t('studio.aiguide.fact_uuid'), value: project.uuid, mono: true },
    { icon: Terminal, label: t('studio.aiguide.fact_api'), value: apiBase, mono: true },
    { icon: ListTree, label: t('studio.aiguide.fact_collections'), value: `${collections.length} · ${totalFields} ${t('studio.aiguide.fields')}` },
    { icon: UserCog, label: 'End Users', value: `${endUsers.total ?? '?'} users · ${endUsers.fields?.length ?? 0} custom fields` },
  ];

  return (
    <div className="space-y-6">
      <style>{`
        .aig-glow { position:relative; }
        .aig-glow::before{ content:''; position:absolute; inset:-1px; border-radius:14px;
          background:linear-gradient(135deg, rgba(47,207,143,.5), transparent 55%); opacity:.5; z-index:0; filter:blur(8px); }
        .aig-doc { background:#0c1110; border:1px solid rgba(47,207,143,.22); border-radius:12px; position:relative; z-index:1; }
        .aig-doc pre{ margin:0; padding:16px 18px; max-height:560px; overflow:auto; font-size:11.5px; line-height:1.65;
          font-family:'JetBrains Mono',ui-monospace,SFMono-Regular,Menlo,monospace; color:#c7d6cf; white-space:pre; }
        .aig-step{ display:flex; gap:12px; align-items:flex-start; }
        .aig-step .n{ flex:0 0 auto; width:26px;height:26px;border-radius:8px;display:grid;place-items:center;
          background:rgba(47,207,143,.12); color:#2fcf8f; font-size:12px; font-weight:700; font-variant-numeric:tabular-nums; }
        .aig-chip{ font-family:'JetBrains Mono',monospace; }
        .aig-endpoint-group { margin-bottom: 14px; }
        .aig-endpoint-group h4 { font-size: 11px; font-weight: 700; color: #2fcf8f; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .06em; }
      `}</style>

      {/* Header */}
      <div className="flex items-start gap-3">
        <div className="shrink-0 mt-0.5 w-10 h-10 rounded-xl grid place-items-center" style={{ background: 'rgba(47,207,143,.12)', color: '#2fcf8f' }}>
          <Sparkles className="w-5 h-5" />
        </div>
        <div>
          <h2 className="text-2xl font-bold tracking-tight">{t('studio.aiguide.title')}</h2>
          <p className="text-muted-foreground max-w-2xl">{t('studio.aiguide.subtitle')}</p>
        </div>
      </div>

      <div className="grid grid-cols-12 gap-6">
        {/* Left: facts + connection + how-to */}
        <div className="col-span-12 lg:col-span-5 space-y-4">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><Database className="w-4 h-4" />{t('studio.aiguide.facts_title')}</CardTitle></CardHeader>
            <CardContent className="space-y-2.5">
              {facts.map((f, i) => (
                <div key={i} className="flex items-center gap-3 text-sm">
                  <f.icon className="w-3.5 h-3.5 text-muted-foreground shrink-0" />
                  <span className="text-muted-foreground w-28 shrink-0">{f.label}</span>
                  <span className={`truncate ${f.mono ? 'aig-chip text-[11px]' : 'font-medium'}`} title={f.value}>{f.value}</span>
                </div>
              ))}
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><KeyRound className="w-4 h-4" />{t('studio.aiguide.connect_title')}</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              <div>
                <p className="text-xs font-semibold mb-1">{t('studio.aiguide.auth_title')}</p>
                <p className="text-xs text-muted-foreground mb-1.5">{t('studio.aiguide.auth_desc')}</p>
                <code className="block aig-chip text-[11px] bg-muted rounded-md px-2 py-1.5">Authorization: Bearer &lt;API_TOKEN&gt;</code>
              </div>

              {/* Content API */}
              <div className="space-y-1.5">
                <p className="text-xs font-semibold">{t('studio.aiguide.endpoints_title')} — Content</p>
                <EndpointRow method="GET" path={`/{collection}?locale=${locale}`} desc={t('studio.aiguide.ep_list')} />
                <EndpointRow method="GET" path="/{collection}/{uuid}" desc={t('studio.aiguide.ep_get')} />
              </div>

              {/* End-User Auth API */}
              <div className="space-y-1.5 aig-endpoint-group">
                <p className="text-xs font-semibold">End-User Auth — JWT</p>
                <EndpointRow method="POST" path="/auth/register" desc="Register new end-user (email, password, name)" />
                <EndpointRow method="POST" path="/auth/login" desc="Login → returns access_token + refresh_token + user" />
                <EndpointRow method="GET" path="/auth/me" desc="Get current user profile (JWT required)" />
                <EndpointRow method="PATCH" path="/auth/me" desc="Update profile (name, custom_fields, password)" />
                <EndpointRow method="POST" path="/auth/refresh" desc="Refresh token pair" />
                <EndpointRow method="POST" path="/auth/logout" desc="Invalidate all tokens" />
                <EndpointRow method="POST" path="/auth/forgot-password" desc="Request password reset link" />
                <EndpointRow method="POST" path="/auth/reset-password" desc="Reset password with token" />
              </div>

              {/* End-User Admin CRUD */}
              <div className="space-y-1.5 aig-endpoint-group">
                <p className="text-xs font-semibold">End-User Admin — CRUD</p>
                <span className="aig-chip text-[10px] text-muted-foreground">(requires API token with write ability)</span>
                <EndpointRow method="GET" path="/end-users" desc="List users (pagination, search, status filter)" />
                <EndpointRow method="GET" path="/end-users/{uuid}" desc="Get one user" />
                <EndpointRow method="POST" path="/end-users" desc="Create user (email, password, name, status, custom_fields)" />
                <EndpointRow method="PATCH" path="/end-users/{uuid}" desc="Update user (email, name, status, custom_fields, password)" />
                <EndpointRow method="PATCH" path="/end-users/{uuid}/status" desc="Change status (active | banned | pending)" />
                <EndpointRow method="DELETE" path="/end-users/{uuid}" desc="Delete user" />
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><Sparkles className="w-4 h-4" />{t('studio.aiguide.how_title')}</CardTitle></CardHeader>
            <CardContent className="space-y-3">
              {[t('studio.aiguide.how_step1'), t('studio.aiguide.how_step2'), t('studio.aiguide.how_step3'), t('studio.aiguide.how_step4')].map((s, i) => (
                <div key={i} className="aig-step"><span className="n">{i + 1}</span><span className="text-sm text-muted-foreground">{s}</span></div>
              ))}
            </CardContent>
          </Card>
        </div>

        {/* Right: the AI context capsule (hero) */}
        <div className="col-span-12 lg:col-span-7">
          <Card className="overflow-hidden">
            <CardHeader className="pb-2">
              <CardTitle className="text-sm flex items-center justify-between gap-2">
                <span className="flex items-center gap-2"><Terminal className="w-4 h-4" style={{ color: '#2fcf8f' }} />{t('studio.aiguide.context_title')}</span>
                <span className="flex gap-1">
                  <Button size="sm" variant="outline" className="h-7 gap-1.5 text-xs" onClick={copy}>
                    {copied ? <Check className="w-3 h-3 text-emerald-500" /> : <Copy className="w-3 h-3" />}
                    {copied ? t('studio.aiguide.copied') : t('studio.aiguide.copy')}
                  </Button>
                  <Button size="sm" variant="ghost" className="h-7 w-7 p-0" onClick={download} title={t('studio.aiguide.download')}>
                    <Download className="w-3.5 h-3.5" />
                  </Button>
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <p className="text-xs text-muted-foreground mb-3 flex items-center gap-1.5">
                <Sparkles className="w-3 h-3" style={{ color: '#2fcf8f' }} />
                {t('studio.aiguide.context_hint')}
              </p>
              <div className="aig-glow">
                <div className="aig-doc"><pre>{doc}</pre></div>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>

      {/* ═══ Collections Overview ═══ */}
      <Card>
        <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><ListTree className="w-4 h-4" />{t('studio.aiguide.schema_title')} — Collections</CardTitle></CardHeader>
        <CardContent>
          {collections.length === 0 ? (
            <p className="text-sm text-muted-foreground py-4 text-center">{t('studio.aiguide.empty')}</p>
          ) : (
            <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
              {collections.map(c => (
                <div key={c.slug} className="rounded-lg border border-border bg-muted/20 p-3">
                  <div className="flex items-center justify-between gap-2 mb-1.5">
                    <span className="font-medium text-sm truncate">{c.name}</span>
                    <Badge variant="secondary" className="text-[10px] shrink-0">{c.isSingleton ? t('studio.aiguide.singleton') : `${(c.fields || []).length} ${t('studio.aiguide.fields')}`}</Badge>
                  </div>
                  <code className="aig-chip text-[10px] text-muted-foreground block mb-2">{c.slug}</code>
                  <div className="flex flex-wrap gap-1">
                    {(c.fields || []).slice(0, 8).map(f => (
                      <span key={f.slug} className="text-[10px] aig-chip px-1.5 py-0.5 rounded bg-background border border-border text-muted-foreground" title={`${f.type}${f.isRequired ? ' · ' + t('studio.aiguide.required') : ''}`}>
                        {f.slug}<span className="opacity-40">:{f.type}</span>
                      </span>
                    ))}
                    {(c.fields || []).length > 8 && <span className="text-[10px] text-muted-foreground self-center">+{(c.fields || []).length - 8}</span>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      {/* ═══ End Users Schema ═══ */}
      <Card>
        <CardHeader className="pb-2">
          <CardTitle className="text-sm flex items-center gap-2">
            <ShieldCheck className="w-4 h-4" />
            End-User Schema — JWT Authentication
          </CardTitle>
        </CardHeader>
        <CardContent>
          {allEuFields.length === 0 ? (
            <p className="text-sm text-muted-foreground py-4 text-center">No end-user fields configured.</p>
          ) : (
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
              {allEuFields.map(f => (
                <div key={f.slug} className="rounded-lg border border-border bg-muted/20 p-2.5 flex items-center gap-2.5">
                  <span className="text-[10px] aig-chip px-1.5 py-0.5 rounded bg-background border border-border text-muted-foreground" title={`${f.type}${f.isRequired ? ' · required' : ''}`}>
                    {f.slug}<span className="opacity-40">:{f.type}</span>
                  </span>
                  <span className="text-[11px] text-muted-foreground truncate">{f.name || f.slug}</span>
                  {f.isRequired && (
                    <Badge variant="secondary" className="text-[9px] shrink-0 ml-auto">required</Badge>
                  )}
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function EndpointRow({ method, path, desc }: { method: string; path: string; desc: string }) {
  const methodColors: Record<string, string> = {
    GET: 'rgba(47,207,143,.12)',
    POST: 'rgba(97,175,239,.15)',
    PATCH: 'rgba(247,185,85,.18)',
    PUT: 'rgba(247,185,85,.18)',
    DELETE: 'rgba(239,68,68,.15)',
  };
  const methodTextColors: Record<string, string> = {
    GET: '#2fcf8f',
    POST: '#61afef',
    PATCH: '#f7b955',
    PUT: '#f7b955',
    DELETE: '#ef4444',
  };
  const bg = methodColors[method] || 'rgba(47,207,143,.12)';
  const color = methodTextColors[method] || '#2fcf8f';
  return (
    <div className="flex items-start gap-2 text-xs">
      <span className="aig-chip text-[10px] font-bold px-1.5 py-0.5 rounded shrink-0" style={{ background: bg, color }}>{method}</span>
      <div className="min-w-0">
        <code className="aig-chip text-[11px] break-all">{path}</code>
        <p className="text-muted-foreground">{desc}</p>
      </div>
    </div>
  );
}

function buildContextDoc(
  project: Project,
  collections: AiCol[],
  apiBase: string,
  authBase: string,
  adminEndUsersBase: string,
  locale: string,
  t: (k: string) => string,
  euFields: EndUserField[],
  euTotal: number,
): string {
  const L: string[] = [];
  L.push(`# Jambo Project Context — ${project.name}`, '');
  L.push(t('studio.aiguide.doc_intro'), '');
  L.push('## Project');
  L.push(`- Name: ${project.name}`);
  L.push(`- Project ID (UUID): ${project.uuid}`);
  L.push(`- API base URL: ${apiBase}`);
  L.push(`- Default locale: ${locale}`);
  L.push(`- End Users: ${euTotal} registered`, '');

  L.push('## Authentication');
  L.push('### Admin / API Token');
  L.push('All content API requests require a Bearer token (create one in Project Settings → API Access):');
  L.push('```', 'Authorization: Bearer <API_TOKEN>', '```');
  L.push('');
  L.push('### End-User JWT');
  L.push(`End-users authenticate via POST ${authBase}/login with email + password.`);
  L.push('The response contains an access_token (valid 15 min by default, configurable per project) and a refresh_token (30 days).');
  L.push('Include the JWT in the Authorization header for user-specific endpoints:', '');
  L.push('```', 'Authorization: Bearer <JWT_ACCESS_TOKEN>', '```', '');

  L.push('## End-User API');
  L.push('');
  L.push('### Auth Endpoints');
  L.push('| Method | Path | Description |');
  L.push('|---|---|---|');
  L.push('| POST | /auth/register | Register (email, password, name) |');
  L.push('| POST | /auth/login | Login → access_token + refresh_token |');
  L.push('| GET  | /auth/me | Get current user profile |');
  L.push('| PATCH | /auth/me | Update profile (name, custom_fields, password) |');
  L.push('| POST | /auth/refresh | Refresh token pair |');
  L.push('| POST | /auth/logout | Invalidate all tokens |');
  L.push('| POST | /auth/forgot-password | Request password reset |');
  L.push('| POST | /auth/reset-password | Reset password with token |');

  L.push('', '### Admin CRUD Endpoints');
  L.push('Requires API token with write/create ability:', '');
  L.push('| Method | Path | Description |');
  L.push('|---|---|---|');
  L.push('| GET    | /end-users | List users (pagination, search, status filter) |');
  L.push('| GET    | /end-users/{uuid} | Get one user |');
  L.push('| POST   | /end-users | Create user |');
  L.push('| PATCH  | /end-users/{uuid} | Update user |');
  L.push('| PATCH  | /end-users/{uuid}/status | Change status |');
  L.push('| DELETE | /end-users/{uuid} | Delete user |');

  L.push('', '### JWT Token TTL');
  L.push('Access and refresh token expiration can be configured per project:');
  L.push(`- GET  ${apiBase.replace(`/${project.uuid}`, '')}/projects/${project.uuid}/settings/jwt-ttl`);
  L.push(`- PATCH  ${apiBase.replace(`/${project.uuid}`, '')}/projects/${project.uuid}/settings/jwt-ttl`);
  L.push('Set `jwt_access_ttl` and `jwt_refresh_ttl` in seconds. Minimum: 60s. Set to 0 to reset to defaults.', '');

  L.push('### End-User Schema');
  if (euFields.length === 0) {
    L.push('_No custom fields configured._');
  } else {
    L.push('', '| field | type | required |', '|---|---|---|');
    for (const f of euFields) {
      L.push(`| \`${f.slug}\` | ${f.type} | ${f.isRequired ? 'yes' : 'no'} |`);
    }
  }

  L.push('', '## Content API');
  L.push(`- List entries:   GET ${apiBase}/{collection}?locale=${locale}&limit=20&offset=0`);
  L.push(`- Get one entry:  GET ${apiBase}/{collection}/{uuid}`);
  L.push('- Add ?locale=xx for localization. Responses are JSON.');
  L.push('- Every entry includes: uuid, locale, status ("draft" | "published"), created_at, updated_at, plus the fields below.', '');

  L.push('## Collections');
  if (collections.length === 0) {
    L.push('_No collections defined yet._');
  } else {
    for (const c of collections) {
      const kind = c.isSingleton ? ' · singleton' : '';
      L.push('', `### ${c.name}  (slug: \`${c.slug}\`${kind})`);
      if (c.description) L.push(c.description);
      L.push('', '| field | type | required |', '|---|---|---|');
      for (const f of (c.fields || [])) {
        L.push(`| \`${f.slug}\` | ${f.type} | ${f.isRequired ? 'yes' : 'no'} |`);
      }
    }
  }
  L.push('', '## How to build');
  L.push('1. Get a Bearer token from API Access.');
  L.push('2. Fetch the collections you need from the Content API.');
  L.push('3. Authenticate end-users via the Auth API (JWT).');
  L.push('4. Render them in any framework (React, Vue, Astro, Next, server-side Twig…).');
  L.push('5. Filter by status="published" for live content and respect locales.');
  return L.join('\n');
}
