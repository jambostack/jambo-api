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

        .studio-root .nav-num {
          font-family: var(--studio-mono);
          font-size: 10px;
          letter-spacing: .08em;
          color: var(--studio-text-muted);
        }
        .studio-root .nav-item-active .nav-num { color: var(--studio-accent); }

        .studio-root .nav-desc {
          font-size: 12px;
          color: var(--studio-text-dim);
          line-height: 1.4;
        }

        .studio-root .stat-value {
          font-family: var(--studio-mono);
          font-size: 28px;
          font-weight: 600;
          color: var(--studio-accent);
          line-height: 1;
          letter-spacing: -.02em;
        }
        .studio-root .stat-label {
          font-size: 10px;
          text-transform: uppercase;
          letter-spacing: .08em;
          color: var(--studio-text-muted);
        }

        .studio-root .section-header h2 {
          font-family: var(--studio-serif);
          font-size: 22px;
          font-weight: 500;
          letter-spacing: -.01em;
          color: var(--studio-text);
        }

        @media (max-width: 768px) {
          .studio-root .studio-grid { grid-template-columns: 1fr; }
        }
      `}</style>

      <Heading title={t('studio.title')} description={t('studio.desc')} />

      <div className="studio-grid mt-8" style={{ display: 'grid', gridTemplateColumns: '240px 1fr', gap: '32px' }}>
        {/* ── SIDEBAR ── */}
        <nav style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
          {nav.map(item => {
            const isActive = active === item.id;
            const Icon = item.icon;
            return (
              <button
                key={item.id}
                onClick={() => setActive(item.id)}
                className={isActive ? 'nav-item-active' : ''}
                style={{
                  display: 'flex', alignItems: 'flex-start', gap: '12px',
                  width: '100%', textAlign: 'left', cursor: 'pointer',
                  padding: '14px', borderRadius: '8px',
                  background: isActive ? 'var(--studio-raised)' : 'transparent',
                  border: isActive ? '1px solid var(--studio-border-active)' : '1px solid transparent',
                  transition: 'all .15s ease',
                }}
              >
                <span className="nav-num" style={{ marginTop: '1px' }}>{item.num}</span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '3px' }}>
                    <Icon className="w-3.5 h-3.5" style={{ color: isActive ? 'var(--studio-accent)' : 'var(--studio-text-dim)' }} />
                    <span style={{
                      fontSize: '13px', fontWeight: 600,
                      color: isActive ? 'var(--studio-text)' : 'var(--studio-text-dim)',
                    }}>{item.label}</span>
                  </div>
                  <p className="nav-desc">{item.desc}</p>
                </div>
              </button>
            );
          })}

          {/* Stats card */}
          <div style={{
            marginTop: '16px', padding: '16px',
            border: '1px solid var(--studio-border)', borderRadius: '10px',
            background: 'var(--studio-surface)',
          }}>
            <div style={{ display: 'flex', gap: '16px' }}>
              <div style={{ flex: 1, textAlign: 'center' }}>
                <div className="stat-value">{collections.length}</div>
                <div className="stat-label">{t('studio.schema.collections_title')}</div>
              </div>
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
