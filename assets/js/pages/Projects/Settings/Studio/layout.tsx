import { useState, useEffect } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { router, Head } from '@inertiajs/react';
import type { Project, Collection } from '@/types/index.d';
import {
  Database, Download, Search, ScrollText, Braces,
  ArrowRight, Box, Layers, ArrowLeft, X, Sparkles
} from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import SchemaBuilder from './SchemaBuilder';
import CodeExporter from './CodeExporter';
import SearchPage from './SearchPage';
import AuditLogsPage from './AuditLogsPage';
import AiGuide from './AiGuide';

interface StudioLayoutProps { project: Project; collections: Collection[]; }

function goBack(projectId: number) { router.get(`/projects/${projectId}/settings`); }

type Panel = 'schema' | 'export' | 'search' | 'audit' | 'graphql' | 'aiguide';

interface NavItem {
  id: Panel;
  num: string;
  icon: React.ElementType;
  label: string;
  desc: string;
}

export default function StudioLayout({ project, collections: initialCollections }: StudioLayoutProps) {
  const t = useTranslation();
  const [active, setActive] = useState<Panel>('schema');
  // La prop Inertia est figée au chargement de la page. On la garde comme valeur
  // initiale, mais on recharge les collections depuis le serveur à l'ouverture des
  // onglets qui en dépendent (SDK/export, recherche), afin de refléter les
  // collections fraîchement créées dans le Schema Builder.
  const [collections, setCollections] = useState<Collection[]>(initialCollections);

  useEffect(() => {
    if (active !== 'export' && active !== 'search' && active !== 'aiguide') return;
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`/api/projects/${project.uuid}/studio/collections`);
        if (!res.ok) return;
        const data = await res.json() as { data?: Collection[] };
        if (!cancelled && Array.isArray(data.data)) setCollections(data.data);
      } catch { /* garde la liste actuelle */ }
    })();
    return () => { cancelled = true; };
  }, [active, project.uuid]);

  const nav: NavItem[] = [
    { id: 'schema',  num: '01', icon: Database,   label: t('studio.tab_schema'),  desc: t('studio.schema.desc') },
    { id: 'export',  num: '02', icon: Download,   label: t('studio.tab_export'),  desc: t('studio.export.desc') },
    { id: 'search',  num: '03', icon: Search,     label: t('studio.tab_search'),  desc: t('studio.search.desc') },
    { id: 'audit',   num: '04', icon: ScrollText, label: t('studio.tab_audit'),   desc: t('studio.audit.desc') },
    { id: 'graphql', num: '05', icon: Braces,     label: t('studio.tab_graphql'), desc: t('studio.graphql.desc') },
    { id: 'aiguide', num: '06', icon: Sparkles,   label: t('studio.tab_aiguide'), desc: t('studio.aiguide.desc') },
  ];

  const stats = {
    schema: collections.length,
  };

  return (
    <div className="studio-root">
      <Head title={`${t('studio.title')} — ${project.name}`} />

      <style>{`
        .studio-root {
          --studio-bg: var(--background);
          --studio-surface: var(--card);
          --studio-raised: var(--muted);
          --studio-border: var(--border);
          --studio-border-active: color-mix(in oklch, var(--primary) 25%, transparent);
          --studio-text: var(--foreground);
          --studio-text-dim: color-mix(in oklch, var(--foreground) 60%, transparent);
          --studio-text-muted: color-mix(in oklch, var(--foreground) 40%, transparent);
          --studio-accent: var(--primary);
          --studio-accent-dim: color-mix(in oklch, var(--primary) 15%, transparent);
          --studio-red: var(--destructive);
          --studio-amber: #f7b955;
          --studio-mono: 'JetBrains Mono', ui-monospace, monospace;
          --studio-serif: 'Newsreader', 'Cormorant Garamond', Georgia, serif;
        }
        .studio-root .font-serif { font-family: var(--studio-serif); }
        .studio-root .font-mono { font-family: var(--studio-mono); }

        /* ── SIDEBAR ── */
        .studio-sidebar {
          display: flex; flex-direction: column; gap: 4px;
          width: 100%;
        }
        .studio-sidebar .sb-nav-item {
          display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 4px;
          width: 100%; text-align: center; cursor: pointer;
          padding: 10px 4px; border-radius: 8px;
          background: transparent; border: 1px solid transparent;
          transition: all .15s ease;
          font-size: 10px; color: var(--studio-text-dim);
        }
        .studio-sidebar .sb-nav-item:hover { background: var(--studio-raised); color: var(--studio-text); }
        .studio-sidebar .sb-nav-item.sb-active {
          background: var(--studio-raised);
          border-color: var(--studio-border-active);
          color: var(--studio-text);
        }
        .studio-sidebar .sb-nav-item .nav-num {
          font-family: var(--studio-mono); font-size: 10px;
          letter-spacing: .08em; color: var(--studio-text-muted);
          min-width: 18px; text-align: center;
        }
        .studio-sidebar .sb-nav-item.sb-active .nav-num { color: var(--studio-accent); }
        .studio-sidebar .sb-nav-item svg { width: 14px; height: 14px; flex-shrink: 0; }
        .studio-sidebar .sb-nav-item.sb-active svg { color: var(--studio-accent); }
        .studio-sidebar .sb-nav-item span.label-text {
          display: block; font-size: 9px; line-height: 1.15; font-weight: 600;
          text-align: center; max-width: 100%; word-break: break-word;
          letter-spacing: -.01em; color: inherit;
        }
        .studio-sidebar .sb-nav-item .nav-num { display: none; }
        .studio-sidebar .studio-stats { display: none; }
        .studio-sidebar .sb-nav-item {
          padding: 8px 4px; gap: 0;
        }

        /* Stats card — compacté en 56px */
        .studio-stats {
          display: flex; flex-direction: column; gap: 2px; margin-top: 8px;
          padding: 8px 4px; border-radius: 8px; text-align: center;
          border: 1px solid var(--studio-border);
          background: var(--studio-surface);
        }
        .studio-stats .stat-value {
          font-family: var(--studio-mono); font-size: 15px;
          font-weight: 600; color: var(--studio-accent);
          line-height: 1; letter-spacing: -.02em;
        }
        .studio-stats .stat-label {
          font-size: 7px; text-transform: uppercase;
          letter-spacing: .06em; color: var(--studio-text-muted);
        }

        /* ── MAIN GRID ── */
        .studio-grid {
          display: grid;
          grid-template-columns: 1fr;
          gap: 24px;
        }

        /* ── RESPONSIVE BREAKPOINTS ── */
        @media (min-width: 769px) {
          .studio-grid {
            grid-template-columns: 76px 1fr;
            gap: 16px;
          }
          .studio-sidebar .sb-nav-item {
            flex-direction: column; align-items: center; justify-content: center;
            padding: 9px 4px; gap: 5px;
          }
          /* On affiche le libellé (petit) sous l'icône ; seul le numéro reste masqué. */
          .studio-sidebar .sb-nav-item .nav-num { display: none; }
        }
        @media (min-width: 1025px) {
          .studio-grid {
            grid-template-columns: 88px 1fr;
            gap: 24px;
          }
        }

        /* Mobile: horizontal scroll nav */
        .studio-mobile-nav {
          display: flex; gap: 6px; overflow-x: auto;
          padding-bottom: 4px; -webkit-overflow-scrolling: touch;
          scrollbar-width: none;
        }
        .studio-mobile-nav::-webkit-scrollbar { display: none; }
        .studio-mobile-nav .mob-tab {
          flex-shrink: 0; display: flex; align-items: center; gap: 5px;
          padding: 8px 14px; border-radius: 999px; font-size: 12px;
          border: 1px solid var(--studio-border);
          background: var(--studio-surface);
          color: var(--studio-text-dim);
          cursor: pointer; white-space: nowrap;
          transition: all .15s ease;
        }
        .studio-mobile-nav .mob-tab.sb-active {
          background: var(--studio-accent-dim);
          border-color: var(--studio-border-active);
          color: var(--studio-text);
        }
        .studio-mobile-nav .mob-tab svg { width: 13px; height: 13px; }
        .studio-mobile-nav .mob-tab.sb-active svg { color: var(--studio-accent); }

        @media (min-width: 769px) {
          .studio-mobile-nav { display: none; }
        }

        /* ── Header bar ── */
        .studio-header {
          display: flex; align-items: flex-start; justify-content: space-between;
          gap: 16px; flex-wrap: wrap; padding-bottom: 8px;
        }
        .studio-header h2 {
          font-family: var(--studio-serif);
          font-size: clamp(18px, 3vw, 22px);
          font-weight: 500; letter-spacing: -.01em;
          color: var(--studio-text); margin: 0;
        }

        /* Section header */
        .studio-root .section-header h2 {
          font-family: var(--studio-serif);
          font-size: clamp(18px, 3vw, 22px);
          font-weight: 500; letter-spacing: -.01em;
          color: var(--studio-text);
        }
      `}</style>

      {/* ── Header with exit button ── */}
      <div className="studio-header">
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '4px' }}>
            <h2 style={{ fontFamily: 'var(--studio-serif)', fontSize: '20px', fontWeight: 500, color: 'var(--studio-text)', margin: 0 }}>
              {t('studio.title')}
            </h2>
          </div>
          <p style={{ fontSize: '12px', color: 'var(--studio-text-dim)', margin: 0 }}>{t('studio.desc')}</p>
        </div>
        <button
          onClick={() => goBack(project.id)}
          style={{
            display: 'flex', alignItems: 'center', gap: '6px',
            padding: '7px 14px', borderRadius: '8px', cursor: 'pointer',
            fontSize: '12px', fontWeight: 600,
            border: '1px solid var(--studio-border)',
            background: 'var(--studio-surface)',
            color: 'var(--studio-text-dim)',
            transition: 'all .12s ease',
            whiteSpace: 'nowrap', flexShrink: 0,
          }}
          onMouseOver={e => { e.currentTarget.style.borderColor = 'var(--studio-border-active)'; e.currentTarget.style.color = 'var(--studio-accent)'; }}
          onMouseOut={e => { e.currentTarget.style.borderColor = 'var(--studio-border)'; e.currentTarget.style.color = 'var(--studio-text-dim)'; }}
        >
          <ArrowLeft className="w-3.5 h-3.5" />Retour
        </button>
      </div>

      {/* ── Mobile: horizontal scroll tabs ── */}
      <div className="studio-mobile-nav" style={{ marginTop: '16px' }}>
        {nav.map(item => {
          const isActive = active === item.id;
          const Icon = item.icon;
          return (
            <button key={item.id} onClick={() => setActive(item.id)} className={`mob-tab${isActive ? ' sb-active' : ''}`}>
              <Icon /><span className="label-text">{item.label}</span>
            </button>
          );
        })}
      </div>

      <div className="studio-grid" style={{ marginTop: '20px' }}>
        {/* ── SIDEBAR (tablet+) ── */}
        <nav className="studio-sidebar" style={{ display: 'none' }}>
          <style>{`@media(min-width:769px){.studio-sidebar{display:flex!important}}`}</style>
          {nav.map(item => {
            const isActive = active === item.id;
            const Icon = item.icon;
            return (
              <button key={item.id} onClick={() => setActive(item.id)}
                className={`sb-nav-item${isActive ? ' sb-active' : ''}`}>
                <span className="nav-num">{item.num}</span>
                <Icon />
                <span className="label-text" style={{ fontWeight: 600 }}>{item.label}</span>
              </button>
            );
          })}

          <div className="studio-stats">
            <div style={{ flex: 1, textAlign: 'center' }}>
              <div className="stat-value">{collections.length}</div>
              <div className="stat-label">Collections</div>
            </div>
          </div>
        </nav>

        {/* ── CONTENT ── */}
        <div style={{ minWidth: 0 }}>
          {active === 'schema' && <SchemaBuilder project={project} />}
          {active === 'export' && <CodeExporter project={project} collections={collections} />}
          {active === 'search' && <SearchPage project={project} collections={collections} />}
          {active === 'audit'  && <AuditLogsPage project={project} />}
          {active === 'graphql' && <GraphQLExplorer projectUuid={project.uuid} />}
          {active === 'aiguide' && <AiGuide project={project} collections={collections} />}
        </div>
      </div>
    </div>
  );
}

function GraphQLExplorer({ projectUuid }: { projectUuid: string }) {
  const t = useTranslation();
  const [copied, setCopied] = useState(false);
  const endpoint = `POST /api/projects/${projectUuid}/graphql`;

  const copy = async () => {
    await navigator.clipboard.writeText(endpoint);
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '24px' }}>
      <div className="section-header">
        <h2>{t('studio.graphql.title')}</h2>
        <p className="nav-desc" style={{ marginTop: '4px' }}>{t('studio.graphql.desc')}</p>
      </div>

      <div style={{
        border: '1px solid var(--studio-border)', borderRadius: '10px',
        background: 'var(--studio-surface)', padding: '24px',
      }}>
        <h3 style={{
          fontFamily: 'var(--studio-mono)', fontSize: '11px', fontWeight: 600,
          textTransform: 'uppercase', letterSpacing: '.06em',
          color: 'var(--studio-text-muted)', marginBottom: '16px',
        }}>{t('studio.graphql.endpoint')}</h3>

        <div style={{
          display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '24px',
          padding: '12px 16px', borderRadius: '8px',
          background: 'var(--studio-raised)',
          border: '1px solid var(--studio-border)',
        }}>
          <code style={{
            fontFamily: 'var(--studio-mono)', fontSize: '13px',
            color: 'var(--studio-accent)', flex: 1,
          }}>{endpoint}</code>
          <Button variant="outline" size="sm" onClick={copy}>
            {copied ? t('studio.graphql.copied') : t('studio.graphql.copy')}
          </Button>
        </div>

        <div style={{
          border: '1px solid var(--studio-border)',
          borderRadius: '8px', padding: '20px',
          background: '#0a0e0c',
        }}>
          <p style={{
            fontFamily: 'var(--studio-mono)', fontSize: '10px', fontWeight: 600,
            textTransform: 'uppercase', letterSpacing: '.06em',
            color: 'var(--studio-text-muted)', marginBottom: '12px',
          }}>{t('studio.graphql.example')}</p>
          <pre style={{
            fontFamily: 'var(--studio-mono)', fontSize: '12px',
            color: 'var(--studio-text-dim)', lineHeight: 1.6,
            margin: 0, whiteSpace: 'pre-wrap',
          }}>{`# Query your data
query {
  _ping
  articles { uuid title status }
}

# With filters
query {
  articles(locale: "en", status: "published") {
    uuid title created_at
  }
}

# Mutation example
mutation {
  createArticles(input: {
    title: "New Article"
    status: "draft"
  }) { uuid title }
}`}</pre>
        </div>

        <p style={{
          fontSize: '11px', color: 'var(--studio-text-muted)',
          marginTop: '16px', fontStyle: 'italic',
        }}>{t('studio.graphql.hint')}</p>
      </div>
    </div>
  );
}
