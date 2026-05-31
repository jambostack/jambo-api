import { useState, useMemo } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Copy, Check, Download, KeyRound, Database, Link2, Sparkles, Terminal, ListTree } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project, Collection } from '@/types/index.d';

// Forme réelle renvoyée par l'API /studio/collections (camelCase), distincte du
// type Collection déclaré côté front. On l'aligne ici pour un typage correct.
type AiField = { slug: string; type: string; isRequired?: boolean };
type AiCol = { name: string; slug: string; description?: string; isSingleton?: boolean; fields?: AiField[] };

export default function AiGuide({ project, collections: rawCollections }: { project: Project; collections: Collection[] }) {
  const t = useTranslation();
  const collections = rawCollections as unknown as AiCol[];
  const [copied, setCopied] = useState(false);

  const origin = typeof window !== 'undefined' ? window.location.origin : '';
  const apiBase = `${origin}/api/${project.uuid}`;
  const locale = (project as unknown as { defaultLocale?: string }).defaultLocale ?? 'en';

  const totalFields = useMemo(
    () => collections.reduce((n, c) => n + (c.fields?.length ?? 0), 0),
    [collections],
  );

  const doc = useMemo(
    () => buildContextDoc(project, collections, apiBase, locale, t),
    [project, collections, apiBase, locale, t],
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
              <div className="space-y-1.5">
                <p className="text-xs font-semibold">{t('studio.aiguide.endpoints_title')}</p>
                <EndpointRow method="GET" path={`/{collection}?locale=${locale}`} desc={t('studio.aiguide.ep_list')} />
                <EndpointRow method="GET" path="/{collection}/{uuid}" desc={t('studio.aiguide.ep_get')} />
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

      {/* Schema overview (dynamic) */}
      <Card>
        <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><ListTree className="w-4 h-4" />{t('studio.aiguide.schema_title')}</CardTitle></CardHeader>
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
    </div>
  );
}

function EndpointRow({ method, path, desc }: { method: string; path: string; desc: string }) {
  return (
    <div className="flex items-start gap-2 text-xs">
      <span className="aig-chip text-[10px] font-bold px-1.5 py-0.5 rounded shrink-0" style={{ background: 'rgba(47,207,143,.12)', color: '#2fcf8f' }}>{method}</span>
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
  locale: string,
  t: (k: string) => string,
): string {
  const L: string[] = [];
  L.push(`# Jambo Project Context — ${project.name}`, '');
  L.push(t('studio.aiguide.doc_intro'), '');
  L.push('## Project');
  L.push(`- Name: ${project.name}`);
  L.push(`- Project ID (UUID): ${project.uuid}`);
  L.push(`- API base URL: ${apiBase}`);
  L.push(`- Default locale: ${locale}`, '');
  L.push('## Authentication');
  L.push('All requests require a Bearer token (create one in Project Settings → API Access):');
  L.push('```', 'Authorization: Bearer <API_TOKEN>', '```', '');
  L.push('## Content API');
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
  L.push('3. Render them in any framework (React, Vue, Astro, Next, server-side Twig…).');
  L.push('4. Filter by status="published" for live content and respect locales.');
  return L.join('\n');
}
