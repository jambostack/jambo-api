import { useState } from 'react';
import { Card, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import Heading from '@/components/heading';
import type { Project, Collection } from '@/types/index.d';
import {
  Database, Download, Search, ScrollText, Braces,
  ArrowRight, Box, Layers
} from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import SchemaBuilder from './SchemaBuilder';
import CodeExporter from './CodeExporter';
import SearchPage from './SearchPage';
import AuditLogsPage from './AuditLogsPage';

interface StudioLayoutProps { project: Project; collections: Collection[]; }

type Panel = 'schema' | 'export' | 'search' | 'audit' | 'graphql';

interface NavItem {
  id: Panel;
  num: string;
  icon: React.ElementType;
  label: string;
  desc: string;
}

export default function StudioLayout({ project, collections }: StudioLayoutProps) {
  const t = useTranslation();
  const [active, setActive] = useState<Panel>('schema');

  const nav: NavItem[] = [
    { id: 'schema',  num: '01', icon: Database,   label: t('studio.tab_schema'),  desc: t('studio.schema.desc') },
    { id: 'export',  num: '02', icon: Download,   label: t('studio.tab_export'),  desc: t('studio.export.desc') },
    { id: 'search',  num: '03', icon: Search,     label: t('studio.tab_search'),  desc: t('studio.search.desc') },
    { id: 'audit',   num: '04', icon: ScrollText, label: t('studio.tab_audit'),   desc: t('studio.audit.desc') },
    { id: 'graphql', num: '05', icon: Braces,     label: t('studio.tab_graphql'), desc: t('studio.graphql.desc') },
  ];

  const stats = {
    schema: collections.length,
  };

  return (
    <div className="studio-root">
      <style>{`
        .studio-root {
          --studio-bg: #0b0f0d;
          --studio-surface: #111714;
          --studio-raised: #171d19;
          --studio-border: rgba(255,255,255,.06);
          --studio-border-active: rgba(47,207,143,.25);
          --studio-text: #dde4df;
          --studio-text-dim: #85918b;
          --studio-text-muted: #5c6762;
          --studio-accent: #2fcf8f;
          --studio-accent-dim: rgba(47,207,143,.10);
          --studio-red: #f87171;
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
          display: flex; align-items: center; gap: 10px;
          width: 100%; text-align: left; cursor: pointer;
          padding: 10px 12px; border-radius: 8px;
          background: transparent; border: 1px solid transparent;
          transition: all .15s ease;
          font-size: 13px; color: var(--studio-text-dim);
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

        /* Stats card */
        .studio-stats {
          display: flex; gap: 12px; margin-top: 8px;
          padding: 12px; border-radius: 10px;
          border: 1px solid var(--studio-border);
          background: var(--studio-surface);
        }
        .studio-stats .stat-value {
          font-family: var(--studio-mono); font-size: 22px;
          font-weight: 600; color: var(--studio-accent);
          line-height: 1; letter-spacing: -.02em;
        }
        .studio-stats .stat-label {
          font-size: 9px; text-transform: uppercase;
          letter-spacing: .08em; color: var(--studio-text-muted);
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
            grid-template-columns: 220px 1fr;
            gap: 28px;
          }
          .studio-sidebar .sb-nav-item {
            flex-direction: column; align-items: flex-start;
            padding: 14px; gap: 6px;
          }
          .studio-sidebar .sb-nav-item .nav-num { font-size: 10px; }
        }
        @media (min-width: 1025px) {
          .studio-grid {
            grid-template-columns: 240px 1fr;
            gap: 32px;
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

        /* Section header */
        .studio-root .section-header h2 {
          font-family: var(--studio-serif);
          font-size: clamp(18px, 3vw, 22px);
          font-weight: 500; letter-spacing: -.01em;
          color: var(--studio-text);
        }
      `}</style>

      <Heading title={t('studio.title')} description={t('studio.desc')} />

      {/* ── Mobile: horizontal scroll tabs ── */}
      <div className="studio-mobile-nav" style={{ marginTop: '16px' }}>
        {nav.map(item => {
          const isActive = active === item.id;
          const Icon = item.icon;
          return (
            <button key={item.id} onClick={() => setActive(item.id)} className={`mob-tab${isActive ? ' sb-active' : ''}`}>
              <Icon />{item.label}
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
                <span style={{ fontWeight: 600 }}>{item.label}</span>
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
