import { router } from '@inertiajs/react';
import { useState, useEffect, useRef, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import {
  Plus, Trash2, Eye, Save, Copy, Wand2, Sparkles, Loader2, Menu, ChevronRight,
  Type, AlignLeft, Hash, List, ToggleLeft, Calendar, Clock,
  AtSign, Link, Lock, Palette, Image, GitBranch, Code2, FileText, X, Layers
} from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project } from '@/types/index.d';
import fieldsDef from '@/lib/fields.json';

const FIELD_TYPES = Object.entries(fieldsDef).map(([k, v]) => ({ type: k, label: v.label, desc: v.desc }));

const ICON_MAP: Record<string, React.ComponentType<{ className?: string }>> = {
  text: Type, longtext: AlignLeft, richtext: FileText, slug: Link,
  email: AtSign, password: Lock, number: Hash, enumeration: List,
  boolean: ToggleLeft, color: Palette, date: Calendar, time: Clock,
  media: Image, relation: GitBranch, json: Code2,
};

interface SchemaField { key: string; name: string; slug: string; type: string; isRequired: boolean; options?: Record<string, any>; }
interface SchemaCollection {
  key: string; name: string; slug: string; description: string;
  isSingleton: boolean; fields: SchemaField[];
}

interface ServerCollection {
  id: number; uuid: string; name: string; slug: string;
  description?: string; isSingleton: boolean;
  fields: Array<{ name: string; slug: string; type: string; isRequired: boolean; options?: any; order?: number }>;
}

function slugify(s: string) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''); }

export default function SchemaBuilder({ project }: { project: Project }) {
  const t = useTranslation();
  const [collections, setCollections] = useState<SchemaCollection[]>([]);
  const [selectedIdx, setSelectedIdx] = useState<number | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sheetOpen, setSheetOpen] = useState(false);

  // ── AI state ──
  const [aiPrompt, setAiPrompt] = useState('');
  const [aiGenerating, setAiGenerating] = useState(false);
  const [aiError, setAiError] = useState('');

  const collectionListRef = useRef<HTMLDivElement>(null);
  const current = selectedIdx !== null ? collections[selectedIdx] : null;

  /* ── Load existing collections from API ── */
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        const res = await fetch(`/api/projects/${project.uuid}/studio/collections`);
        if (!res.ok) throw new Error('Failed to load');
        const data = await res.json() as { data: ServerCollection[] };
        if (cancelled) return;
        const loaded = (data.data ?? []).map((c): SchemaCollection => ({
          key: `col_${c.id}`,
          name: c.name,
          slug: c.slug,
          description: c.description ?? '',
          isSingleton: c.isSingleton,
          fields: (c.fields ?? []).map((f, fi): SchemaField => ({
            key: `fld_${c.id}_${fi}`,
            name: f.name, slug: f.slug, type: f.type,
            isRequired: f.isRequired, options: f.options,
          })),
        }));
        setCollections(loaded);
        if (loaded.length > 0) setSelectedIdx(0);
      } catch {
        // Silent: l'endpoint peut ne pas exister encore — on part de zéro
      } finally { if (!cancelled) setLoading(false); }
    })();
    return () => { cancelled = true; };
  }, [project.uuid]);

  /* ── Collection CRUD ── */
  const addCollection = useCallback(() => {
    const c: SchemaCollection = { key: `col_${Date.now()}`, name: '', slug: '', description: '', isSingleton: false, fields: [] };
    setCollections(prev => [...prev, c]);
    setSelectedIdx(collections.length);
    setTimeout(() => collectionListRef.current?.lastElementChild?.scrollIntoView({ behavior: 'smooth' }), 50);
  }, [collections.length]);

  function updateCollection(idx: number, data: Partial<SchemaCollection>) {
    setCollections(prev => prev.map((c, i) => i === idx ? { ...c, ...data, slug: data.name !== undefined ? slugify(data.name) : c.slug } : c));
  }
  function removeCollection(idx: number) {
    setCollections(prev => prev.filter((_, i) => i !== idx));
    if (selectedIdx === idx) setSelectedIdx(null);
    else if (selectedIdx !== null && selectedIdx > idx) setSelectedIdx(selectedIdx - 1);
  }

  /* ── Field CRUD ── */
  function addField() {
    if (selectedIdx === null) return;
    const col = collections[selectedIdx];
    const f: SchemaField = { key: `fld_${Date.now()}`, name: '', slug: '', type: 'text', isRequired: false };
    setCollections(prev => prev.map((c, i) => i === selectedIdx ? { ...c, fields: [...c.fields, f] } : c));
  }
  function updateField(colIdx: number, fKey: string, data: Partial<SchemaField>) {
    setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: c.fields.map(f => f.key === fKey ? { ...f, ...data, slug: data.name !== undefined ? slugify(data.name) : f.slug } : f) } : c));
  }
  function removeField(colIdx: number, fKey: string) {
    setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: c.fields.filter(f => f.key !== fKey) } : c));
  }
  function duplicateField(colIdx: number, fKey: string) {
    const col = collections[colIdx];
    const field = col?.fields.find(f => f.key === fKey);
    if (!field) return;
    const dup: SchemaField = { ...field, key: `fld_${Date.now()}`, name: `${field.name} (copy)`, slug: `${field.slug}_copy` };
    setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: [...c.fields, dup] } : c));
  }

  /* ── Save ── */
  async function handleSave() {
    setSaving(true);
    try {
      await router.post(`/api/projects/${project.uuid}/studio/schema`, { collections }, {
        onSuccess: () => setSaving(false),
        onError: () => setSaving(false),
      });
    } catch { setSaving(false); }
  }

  /* ── Preview ── */
  function generatePreview() {
    if (!current) return;
    const lines = [
      `▸ ${current.name || untitled}`,
      `  slug: ${current.slug || '(auto)'}  ·  ${current.isSingleton ? 'singleton' : 'collection'}`,
      `  ${current.fields.length} champs:`,
      ...current.fields.map((f, i) =>
        `  ${i + 1}. ${f.name || '?'} [${f.type}] ${f.isRequired ? '• requis' : ''}`),
    ];
    setPreview(lines.join('\n'));
  }

  /* ── AI Schema Generation ── */
  async function handleAiGenerate() {
    if (!aiPrompt.trim()) return;
    setAiGenerating(true);
    setAiError('');
    try {
      const res = await fetch(`/api/projects/${project.uuid}/studio/ai-schema`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt: aiPrompt }),
      });
      const data = await res.json() as { collections?: Array<{ name: string; slug: string; description: string; isSingleton: boolean; fields: Array<{ name: string; slug: string; type: string; isRequired: boolean }> }>; error?: string };
      if (!res.ok || data.error) { setAiError(data.error ?? 'AI generation failed'); return; }
      if (data.collections && data.collections.length > 0) {
        const newCols = data.collections.map(c => ({
          key: `col_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
          name: c.name, slug: c.slug, description: c.description ?? '',
          isSingleton: c.isSingleton ?? false,
          fields: (c.fields ?? []).map(f => ({
            key: `fld_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
            name: f.name, slug: f.slug, type: f.type, isRequired: f.isRequired ?? false,
          })),
        }));
        setCollections(prev => [...prev, ...newCols]);
        setSelectedIdx(collections.length);
        setAiPrompt('');
      }
    } catch { setAiError('Network error'); }
    finally { setAiGenerating(false); }
  }

  const untitled = t('studio.schema.untitled');

  /* ── Mobile-friendly navigation between collections ── */
  function selectNext() {
    if (selectedIdx !== null && selectedIdx < collections.length - 1) setSelectedIdx(selectedIdx + 1);
  }
  function selectPrev() {
    if (selectedIdx !== null && selectedIdx > 0) setSelectedIdx(selectedIdx - 1);
  }

  if (loading) {
    return (
      <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '80px 0' }}>
        <Loader2 className="w-5 h-5 animate-spin" style={{ color: 'var(--studio-accent)' }} />
      </div>
    );
  }

  return (
    <div className="sb-root">
      <style>{`
        .sb-root {
          display: flex; flex-direction: column; gap: 20px;
          font-family: ui-sans-serif, system-ui, -apple-system, sans-serif;
        }
        .sb-root * { box-sizing: border-box; }

        /* ── Header bar ── */
        .sb-bar {
          display: flex; align-items: center; gap: 10px;
          justify-content: space-between; flex-wrap: wrap;
        }
        .sb-bar h2 { font-family: var(--studio-serif, Georgia, serif); font-size: 20px; font-weight: 500; margin: 0; color: var(--studio-text, #dde4df); }
        .sb-bar p { margin: 2px 0 0; font-size: 12px; color: var(--studio-text-dim, #85918b); }
        .sb-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── Main grid: collections left, editor center, preview right ── */
        .sb-grid {
          display: grid;
          grid-template-columns: 260px 1fr 220px;
          gap: 20px;
        }

        /* ── Collection list ── */
        .sb-col-list {
          display: flex; flex-direction: column; gap: 4px;
          border: 1px solid var(--studio-border, rgba(255,255,255,.06));
          border-radius: 10px; padding: 8px;
          background: var(--studio-surface, #111714);
          max-height: calc(100vh - 260px);
          overflow-y: auto;
        }
        .sb-col-item {
          display: flex; align-items: center; gap: 8px;
          padding: 10px 10px; border-radius: 7px; cursor: pointer;
          border: 1px solid transparent;
          transition: all .12s ease;
          font-size: 13px;
        }
        .sb-col-item:hover { background: var(--studio-raised, #171d19); }
        .sb-col-item.sb-active {
          border-color: var(--studio-border-active, rgba(47,207,143,.25));
          background: var(--studio-accent-dim, rgba(47,207,143,.08));
        }
        .sb-col-item .col-name { flex: 1; min-width: 0; font-weight: 600; }
        .sb-col-item .col-meta { font-size: 10px; color: var(--studio-text-muted, #5c6762); font-family: var(--studio-mono, monospace); }

        /* ── Editor ── */
        .sb-editor { min-width: 0; }
        .sb-empty {
          display: flex; flex-direction: column; align-items: center; justify-content: center;
          padding: 60px 20px; gap: 16px; text-align: center;
          border: 1px dashed var(--studio-border, rgba(255,255,255,.06));
          border-radius: 10px;
          background: var(--studio-surface, #111714);
        }
        .sb-empty svg { opacity: 0.25; }
        .sb-empty h3 { font-size: 16px; font-weight: 600; color: var(--studio-text-dim, #85918b); }
        .sb-empty p { font-size: 13px; color: var(--studio-text-muted, #5c6762); max-width: 320px; }

        /* ── Field card ── */
        .sb-field-card {
          border: 1px solid var(--studio-border, rgba(255,255,255,.06));
          border-radius: 8px; padding: 12px;
          background: var(--studio-raised, #171d19);
          margin-bottom: 6px;
        }
        .sb-field-card:hover { border-color: var(--studio-border-active, rgba(47,207,143,.15)); }

        /* ── Preview panel ── */
        .sb-preview {
          border: 1px solid var(--studio-border, rgba(255,255,255,.06));
          border-radius: 10px; background: var(--studio-surface, #111714);
          padding: 16px; max-height: calc(100vh - 260px);
          overflow-y: auto; position: sticky; top: 0;
        }
        .sb-preview h4 { margin: 0 0 8px; font-size: 11px; font-family: var(--studio-mono, monospace); text-transform: uppercase; letter-spacing: .06em; color: var(--studio-text-muted, #5c6762); }
        .sb-preview pre { font-family: var(--studio-mono, monospace); font-size: 11px; color: var(--studio-text-dim, #85918b); white-space: pre-wrap; line-height: 1.6; }

        /* ── AI box ── */
        .sb-ai-box {
          border: 1px solid rgba(47,207,143,.15); border-radius: 10px;
          padding: 14px; background: rgba(47,207,143,.03);
          display: flex; flex-direction: column; gap: 8px;
        }
        .sb-ai-box h4 { margin: 0; font-size: 12px; font-weight: 600; color: var(--studio-accent, #2fcf8f); display: flex; align-items: center; gap: 6px; }

        /* ── Responsive ── */
        @media (max-width: 1024px) {
          .sb-grid { grid-template-columns: 1fr 200px; }
          .sb-col-list-wrap { display: none; }
        }
        @media (max-width: 768px) {
          .sb-grid { grid-template-columns: 1fr; }
          .sb-preview { display: none; }
          .sb-bar h2 { font-size: 18px; }
          .sb-actions { width: 100%; }
          .sb-actions button { flex: 1; }
          .sb-col-list-wrap { display: none; }
          .sb-field-card .sb-field-row { flex-wrap: wrap; }
          .sb-field-card .sb-field-row > * { min-width: 0; }
        }
        @media (max-width: 480px) {
          .sb-bar { flex-direction: column; align-items: flex-start; }
          .sb-actions { flex-wrap: wrap; justify-content: stretch; }
          .sb-actions > * { flex: 1; }
        }
      `}</style>

      {/* ── Header ── */}
      <div className="sb-bar">
        <div>
          <h2>{t('studio.schema.title')}</h2>
          <p>
            {collections.length} {collections.length <= 1 ? t('studio.schema.collections_title').replace(/s$/, '') : t('studio.schema.collections_title').toLowerCase()}
            {current && ` · ${current.fields.length} champs`}
          </p>
        </div>
        <div className="sb-actions" style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
          {/* Mobile: collection drawer trigger */}
          <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
            <SheetTrigger asChild>
              <Button variant="outline" size="sm" className="lg-plus:hidden" style={{ display: 'none' }}>
                <Layers className="w-3.5 h-3.5 mr-1" />
                {collections.length} collections
              </Button>
            </SheetTrigger>
            <SheetContent side="left" style={{ width: '280px', background: 'var(--studio-bg, #0b0f0d)', borderColor: 'var(--studio-border)' }}>
              <SheetHeader><SheetTitle style={{ fontFamily: 'var(--studio-serif)', color: 'var(--studio-text)' }}>{t('studio.schema.collections_title')}</SheetTitle></SheetHeader>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', marginTop: '12px' }}>
                {collections.map((col, idx) => (
                  <button key={col.key} onClick={() => { setSelectedIdx(idx); setSheetOpen(false); }}
                    className={`sb-col-item${idx === selectedIdx ? ' sb-active' : ''}`}
                    style={{ width: '100%', textAlign: 'left', background: idx === selectedIdx ? 'var(--studio-accent-dim)' : 'transparent', border: idx === selectedIdx ? '1px solid var(--studio-border-active)' : '1px solid transparent', padding: '10px', borderRadius: '7px', cursor: 'pointer' }}>
                    <div>
                      <div className="col-name" style={{ fontSize: '13px', fontWeight: 600, color: 'var(--studio-text)' }}>{col.name || untitled}</div>
                      <div className="col-meta" style={{ fontSize: '10px', color: 'var(--studio-text-muted)' }}>{col.fields.length} champs · {col.isSingleton ? 'singleton' : 'collection'}</div>
                    </div>
                  </button>
                ))}
                <Button variant="ghost" size="sm" onClick={() => { addCollection(); setSheetOpen(false); }} style={{ justifyContent: 'flex-start' }}>
                  <Plus className="w-3.5 h-3.5 mr-2" />{t('studio.schema.new_collection')}
                </Button>
              </div>
            </SheetContent>
          </Sheet>

          <Button variant="outline" size="sm" onClick={addCollection}>
            <Plus className="w-3.5 h-3.5 mr-1 md:mr-2" /><span className="hidden sm:inline">{t('studio.schema.new_collection')}</span>
          </Button>
          <Button size="sm" onClick={handleSave} disabled={saving || collections.length === 0} style={{ background: 'var(--studio-accent)', color: '#000' }}>
            <Save className="w-3.5 h-3.5 mr-1 md:mr-2" />
            {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <span className="hidden sm:inline">{t('studio.schema.apply')}</span>}
          </Button>
        </div>
      </div>

      {/* ── Main grid ── */}
      <div className="sb-grid">
        {/* LEFT: Collection list (desktop only) */}
        <div className="sb-col-list-wrap" style={{ display: 'none' }}>
          <style>{`@media(min-width:1025px){.sb-col-list-wrap{display:block!important}}`}</style>
          <h3 style={{ fontSize: '10px', fontFamily: 'var(--studio-mono)', textTransform: 'uppercase', letterSpacing: '.08em', color: 'var(--studio-text-muted)', padding: '0 4px 8px', margin: 0 }}>
            {t('studio.schema.collections_title')} · {collections.length}
          </h3>
          <div className="sb-col-list" ref={collectionListRef}>
            {collections.map((col, idx) => (
              <button key={col.key} onClick={() => setSelectedIdx(idx)}
                className={`sb-col-item${idx === selectedIdx ? ' sb-active' : ''}`}
                style={{ width: '100%', textAlign: 'left', background: idx === selectedIdx ? 'var(--studio-accent-dim)' : 'transparent', border: idx === selectedIdx ? '1px solid var(--studio-border-active)' : '1px solid transparent' }}>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div className="col-name" style={{ color: 'var(--studio-text)' }}>{col.name || untitled}</div>
                  <div className="col-meta">{col.fields.length} champs · {col.isSingleton ? 'singleton' : 'collection'}</div>
                </div>
                <Button variant="ghost" size="icon" className="h-6 w-6 opacity-50 hover:opacity-100" onClick={e => { e.stopPropagation(); removeCollection(idx); }}>
                  <Trash2 className="w-3 h-3" style={{ color: 'var(--studio-red)' }} />
                </Button>
              </button>
            ))}
            {collections.length === 0 && (
              <p style={{ fontSize: '12px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '20px 8px' }}>{t('studio.schema.no_collections')}</p>
            )}
          </div>
        </div>

        {/* CENTER: Editor */}
        <div className="sb-editor">
          {current ? (<>
            {/* Mobile prev/next navigation */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' }} className="lg-plus:hidden">
              <style>{`@media(min-width:1025px){.lg-plus\\:hidden{display:none!important}}`}</style>
              <Button variant="ghost" size="icon" onClick={selectPrev} disabled={selectedIdx === null || selectedIdx === 0}><ChevronRight className="w-4 h-4 rotate-180" /></Button>
              <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '11px', color: 'var(--studio-text-muted)' }}>
                {(selectedIdx ?? 0) + 1} / {collections.length}
              </span>
              <Button variant="ghost" size="icon" onClick={selectNext} disabled={selectedIdx === null || selectedIdx === collections.length - 1}><ChevronRight className="w-4 h-4" /></Button>
              <span style={{ fontSize: '13px', fontWeight: 600, color: 'var(--studio-text)' }}>{current.name || untitled}</span>
            </div>

            {/* AI Suggest */}
            <div className="sb-ai-box" style={{ marginBottom: '16px' }}>
              <h4><Sparkles className="w-3.5 h-3.5" />AI Schema Assistant</h4>
              <div style={{ display: 'flex', gap: '8px' }}>
                <Input
                  placeholder="Ex: a blog with articles, categories and comments..."
                  value={aiPrompt}
                  onChange={e => setAiPrompt(e.target.value)}
                  onKeyDown={e => e.key === 'Enter' && handleAiGenerate()}
                  style={{ flex: 1, height: '34px', fontSize: '12px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }}
                  disabled={aiGenerating}
                />
                <Button
                  size="sm"
                  onClick={handleAiGenerate}
                  disabled={aiGenerating || !aiPrompt.trim()}
                  style={{ background: 'var(--studio-accent)', color: '#000', height: '34px', fontSize: '12px', whiteSpace: 'nowrap' }}
                >
                  {aiGenerating ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <><Sparkles className="w-3.5 h-3.5 mr-1" />Generate</>}
                </Button>
              </div>
              {aiError && <p style={{ fontSize: '11px', color: 'var(--studio-red)', margin: 0 }}>{aiError}</p>}
            </div>

            {/* Collection form */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px', marginBottom: '12px' }}>
              <div><Label style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('common.name')}</Label>
                <Input value={current.name} onChange={e => updateCollection(selectedIdx!, { name: e.target.value })}
                  placeholder={t('studio.schema.articles_ph')}
                  style={{ height: '34px', fontSize: '13px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} />
              </div>
              <div><Label style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.slug_label')}</Label>
                <Input value={current.slug} onChange={e => updateCollection(selectedIdx!, { slug: e.target.value })}
                  placeholder="articles"
                  style={{ height: '34px', fontSize: '13px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)', fontFamily: 'var(--studio-mono)' }} />
              </div>
            </div>
            <div style={{ marginBottom: '12px' }}>
              <Label style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.desc_label')}</Label>
              <Input value={current.description} onChange={e => updateCollection(selectedIdx!, { description: e.target.value })}
                placeholder={t('studio.schema.desc_placeholder')}
                style={{ height: '34px', fontSize: '13px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} />
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '16px' }}>
              <Switch checked={current.isSingleton} onCheckedChange={v => updateCollection(selectedIdx!, { isSingleton: v })} />
              <Label style={{ fontSize: '13px', color: 'var(--studio-text-dim)' }}>{t('studio.schema.singleton_label')}</Label>
            </div>

            <Separator style={{ marginBottom: '14px', borderColor: 'var(--studio-border)' }} />

            {/* Fields header */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px', flexWrap: 'wrap', gap: '8px' }}>
              <h3 style={{ fontSize: '10px', fontFamily: 'var(--studio-mono)', textTransform: 'uppercase', letterSpacing: '.08em', color: 'var(--studio-text-muted)', margin: 0 }}>
                {t('studio.schema.fields_title')} · {current.fields.length}
              </h3>
              <div style={{ display: 'flex', gap: '6px' }}>
                <Button variant="outline" size="sm" onClick={generatePreview} style={{ height: '28px', fontSize: '11px' }}>
                  <Eye className="w-3 h-3 mr-1" />Preview
                </Button>
                <Button size="sm" onClick={addField} style={{ height: '28px', fontSize: '11px', background: 'var(--studio-accent)', color: '#000' }}>
                  <Plus className="w-3 h-3 mr-1" />{t('studio.schema.add_field_btn')}
                </Button>
              </div>
            </div>

            {/* Field list */}
            <div style={{ maxHeight: 'calc(100vh - 540px)', overflowY: 'auto', paddingRight: '4px' }}>
              {current.fields.map((field, idx) => {
                const Icon = ICON_MAP[field.type] || Type;
                return (
                  <div key={field.key} className="sb-field-card">
                    <div className="sb-field-row" style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' }}>
                      <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '10px', color: 'var(--studio-text-muted)', minWidth: '16px' }}>{idx + 1}</span>
                      <Input
                        placeholder={t('studio.schema.field_name_ph')} value={field.name}
                        onChange={e => updateField(selectedIdx!, field.key, { name: e.target.value })}
                        style={{ height: '30px', fontSize: '12px', flex: 1, minWidth: 0, background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }}
                      />
                      <Select value={field.type} onValueChange={v => updateField(selectedIdx!, field.key, { type: v })}>
                        <SelectTrigger style={{ width: '120px', height: '30px', fontSize: '11px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }}>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>{FIELD_TYPES.map(ft => (<SelectItem key={ft.type} value={ft.type}>{ft.label}</SelectItem>))}</SelectContent>
                      </Select>
                      <Button variant="ghost" size="icon" style={{ height: '24px', width: '24px' }} onClick={() => duplicateField(selectedIdx!, field.key)}><Copy className="w-3 h-3" /></Button>
                      <Button variant="ghost" size="icon" style={{ height: '24px', width: '24px' }} onClick={() => removeField(selectedIdx!, field.key)}><Trash2 className="w-3 h-3" style={{ color: 'var(--studio-red)' }} /></Button>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '12px', paddingLeft: '24px' }}>
                      <div style={{ display: 'flex', alignItems: 'center', gap: '4px' }}>
                        <Switch checked={field.isRequired} onCheckedChange={v => updateField(selectedIdx!, field.key, { isRequired: v })} />
                        <span style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.field_required')}</span>
                      </div>
                      <Badge variant="secondary" style={{ fontSize: '10px', background: 'var(--studio-accent-dim)', color: 'var(--studio-accent)', border: 'none' }}>
                        <Icon className="w-3 h-3 mr-1" />{FIELD_TYPES.find(ft => ft.type === field.type)?.label ?? field.type}
                      </Badge>
                    </div>
                  </div>
                );
              })}
              {current.fields.length === 0 && (
                <p style={{ fontSize: '12px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '24px' }}>No fields yet. Click "+ Field" to add one.</p>
              )}
            </div>
          </>) : (
            <div className="sb-empty">
              <Wand2 style={{ width: '40px', height: '40px' }} />
              <div>
                <h3>{collections.length === 0 ? 'Create your first collection' : 'Select a collection'}</h3>
                <p>{collections.length === 0 ? 'Collections define your data model — like database tables with typed fields.' : 'Pick a collection from the left sidebar to edit its fields and settings.'}</p>
              </div>
              {collections.length === 0 && (
                <Button onClick={addCollection} style={{ background: 'var(--studio-accent)', color: '#000' }}>
                  <Plus className="w-4 h-4 mr-2" />{t('studio.schema.new_collection')}
                </Button>
              )}
            </div>
          )}
        </div>

        {/* RIGHT: Preview panel (desktop only) */}
        <div className="sb-preview" style={{ display: 'none' }}>
          <style>{`@media(min-width:769px){.sb-preview{display:block!important}}`}</style>
          <h4><Eye className="w-3.5 h-3.5" style={{ display: 'inline', verticalAlign: 'middle', marginRight: '4px' }} />Preview</h4>
          {preview ? (
            <pre>{preview}</pre>
          ) : (
            <p style={{ fontSize: '12px', color: 'var(--studio-text-muted)' }}>
              {t('studio.schema.preview_hint')}
            </p>
          )}
          {current && (
            <div style={{ marginTop: '12px', paddingTop: '12px', borderTop: '1px solid var(--studio-border)' }}>
              <p style={{ fontSize: '11px', color: 'var(--studio-text-muted)', fontFamily: 'var(--studio-mono)' }}>
                {current.fields.length} fields · {current.fields.filter(f => f.isRequired).length} required
                {current.isSingleton && ' · singleton'}
              </p>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
