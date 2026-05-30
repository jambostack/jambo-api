import { router } from '@inertiajs/react';
import { useState, useEffect, useRef, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import {
  Plus, Trash2, Eye, Save, Copy, Wand2, Loader2, ChevronRight, ChevronLeft,
  Type, AlignLeft, Hash, List, ToggleLeft, Calendar, Clock,
  AtSign, Link, Lock, Palette, Image, GitBranch, Code2, FileText, X, Layers,
  MessageSquare, Send, Bot, User, Check,
  PanelLeftClose, PanelLeftOpen, PanelRightClose, PanelRightOpen,
  FolderOpen, Pencil, Sparkles
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
interface ChatMessage { role: 'user' | 'assistant' | 'system'; content: string; schema?: Array<{ name: string; slug: string; description: string; isSingleton: boolean; fields: Array<{ name: string; slug: string; type: string; isRequired: boolean }> }>; }

function slugify(s: string) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''); }

type MobileTab = 'collections' | 'editor' | 'chat';

/* ══════════════════════════ SCHEMA CHAT PANEL ══════════════════════════ */
function SchemaChatPanel({
  project, currentCollections, onApplySchema,
}: {
  project: Project;
  currentCollections: SchemaCollection[];
  onApplySchema: (newCollections: SchemaCollection[]) => void;
}) {
  const [messages, setMessages] = useState<ChatMessage[]>([
    { role: 'assistant', content: 'Décris les collections à créer ou modifier.', schema: undefined },
  ]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const chatEndRef = useRef<HTMLDivElement>(null);
  const scrollDown = () => setTimeout(() => chatEndRef.current?.scrollIntoView({ behavior: 'smooth' }), 60);

  function buildContext(): string {
    if (currentCollections.length === 0) return '(aucune collection existante)';
    return currentCollections.map(c =>
      `- ${c.name} (${c.slug}, ${c.isSingleton ? 'singleton' : 'collection'}): ${c.fields.map(f => f.name + '[' + f.type + ']' + (f.isRequired ? '*' : '')).join(', ') || 'aucun champ'}`
    ).join('\n');
  }

  async function send() {
    const prompt = input.trim();
    if (!prompt || busy) return;
    setInput(''); setBusy(true);
    setMessages(prev => [...prev, { role: 'user', content: prompt, schema: undefined }]);
    scrollDown();
    try {
      const res = await fetch(`/api/projects/${project.uuid}/studio/ai-chat`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ prompt, context: buildContext(), history: messages.slice(-6).map(m => ({ role: m.role, content: m.content })) }),
      });
      const data = await res.json() as { reply?: string; collections?: any[]; error?: string };
      if (!res.ok || data.error) throw new Error(data.error);
      setMessages(prev => [...prev, { role: 'assistant', content: data.reply ?? 'Schéma généré.', schema: data.collections }]);
    } catch {
      setMessages(prev => [...prev, { role: 'assistant', content: 'Erreur — réessaie.', schema: undefined }]);
    } finally { setBusy(false); scrollDown(); }
  }

  function handleApplySchema(schema: NonNullable<ChatMessage['schema']>) {
    onApplySchema(schema.map(c => ({
      key: `col_${Date.now()}_${Math.random().toString(36).slice(2,6)}`,
      name: c.name, slug: c.slug, description: c.description ?? '', isSingleton: c.isSingleton ?? false,
      fields: (c.fields ?? []).map(f => ({
        key: `fld_${Date.now()}_${Math.random().toString(36).slice(2,6)}`,
        name: f.name, slug: f.slug, type: f.type, isRequired: f.isRequired ?? false,
      })),
    })));
    setMessages(prev => [...prev, { role: 'system', content: `✅ ${schema.length} collection(s) ajoutée(s).`, schema: undefined }]);
    scrollDown();
  }

  return (
    <div className="scp-root">
      <style>{`
        .scp-root { display:flex; flex-direction:column; flex:1; min-height:0; }
        .scp-messages { flex:1; overflow-y:auto; padding:10px 8px; display:flex; flex-direction:column; gap:8px; min-height:0; }
        .scp-msg { display:flex; gap:6px; font-size:11.5px; line-height:1.5; }
        .scp-msg.user { flex-direction:row-reverse; }
        .scp-msg .avatar { width:24px; height:24px; border-radius:6px; flex-shrink:0; display:flex; align-items:center; justify-content:center; font-size:11px; }
        .scp-msg.assistant .avatar { background:rgba(47,207,143,.12); color:var(--studio-accent,#2fcf8f); }
        .scp-msg.user .avatar { background:rgba(255,255,255,.06); color:var(--studio-text-dim,#85918b); }
        .scp-msg.system .avatar { background:rgba(247,185,85,.10); color:var(--studio-amber,#f7b955); }
        .scp-msg .bubble { padding:6px 10px; border-radius:8px; max-width:88%; background:var(--studio-raised,#171d19); color:var(--studio-text-dim,#85918b); border:1px solid var(--studio-border,rgba(255,255,255,.04)); }
        .scp-msg.user .bubble { background:rgba(47,207,143,.08); border-color:rgba(47,207,143,.12); color:var(--studio-text,#dde4df); }
        .scp-schema-card { margin-top:6px; padding:8px; border-radius:6px; background:var(--studio-surface,#111714); border:1px solid rgba(47,207,143,.12); font-size:10.5px; line-height:1.5; }
        .scp-schema-card h5 { margin:0 0 4px; font-size:11px; color:var(--studio-accent); }
        .scp-schema-card ul { margin:2px 0; padding-left:14px; }
        .scp-schema-card li { color:var(--studio-text-muted,#5c6762); margin:1px 0; }
        .scp-schema-card li b { color:var(--studio-text-dim,#85918b); }
        .scp-apply-btn { margin-top:6px; display:inline-flex; align-items:center; gap:4px; padding:4px 10px; border-radius:5px; cursor:pointer; font-size:10.5px; font-weight:600; border:none; background:var(--studio-accent); color:#000; }
        .scp-input-row { display:flex; gap:6px; padding:8px 10px; border-top:1px solid var(--studio-border); flex-shrink:0; }
        .scp-input-row input { flex:1; height:32px; border-radius:6px; padding:0 8px; font-size:11.5px; background:var(--studio-bg); border:1px solid var(--studio-border); color:var(--studio-text); outline:none; }
        .scp-input-row input:focus { border-color:var(--studio-border-active); }
        .scp-input-row button { height:32px; width:32px; border-radius:6px; display:flex; align-items:center; justify-content:center; cursor:pointer; border:none; background:var(--studio-accent); color:#000; flex-shrink:0; }
        .scp-input-row button:disabled { opacity:.4; cursor:not-allowed; }
      `}</style>
      <div className="scp-messages">
        {messages.map((m, i) => (
          <div key={i} className={`scp-msg ${m.role}`}>
            <div className="avatar">{m.role === 'assistant' ? <Bot className="w-3 h-3" /> : m.role === 'system' ? <Check className="w-3 h-3" /> : <User className="w-3 h-3" />}</div>
            <div className="bubble">
              <div style={{ whiteSpace: 'pre-wrap' }}>{m.content}</div>
              {m.schema && m.schema.length > 0 && (
                <div className="scp-schema-card">
                  <h5>{m.schema.length} collection{m.schema.length>1?'s':''} générée{m.schema.length>1?'s':''}</h5>
                  {m.schema.map((col, ci) => (
                    <div key={ci} style={{ marginBottom: ci < m.schema!.length-1 ? 6 : 0 }}>
                      <div style={{ fontWeight:600, color:'var(--studio-text)', fontSize:'11px' }}>{col.name} <span style={{ fontFamily:'var(--studio-mono)', fontSize:'9px', color:'var(--studio-text-muted)' }}>{col.slug}</span></div>
                      <ul>{col.fields.map((f,fi)=>(<li key={fi}>{f.isRequired&&'· '}<b>{f.name}</b> <span style={{ fontFamily:'var(--studio-mono)',fontSize:'9px' }}>[{f.type}]</span></li>))}</ul>
                    </div>
                  ))}
                  <button className="scp-apply-btn" onClick={() => handleApplySchema(m.schema!)}><Check className="w-3 h-3" />Appliquer</button>
                </div>
              )}
            </div>
          </div>
        ))}
        {busy && <div className="scp-msg assistant"><div className="avatar"><Bot className="w-3 h-3" /></div><div className="bubble"><Loader2 className="w-3 h-3 animate-spin" style={{ display:'inline', color:'var(--studio-accent)', marginRight:'6px', verticalAlign:'middle' }} />Génération...</div></div>}
        <div ref={chatEndRef} />
      </div>
      <div className="scp-input-row">
        <input placeholder="Décris les collections..." value={input} onChange={e => setInput(e.target.value)} onKeyDown={e => e.key==='Enter'&&send()} disabled={busy} />
        <button onClick={send} disabled={busy || !input.trim()}>{busy ? <Loader2 className="w-3 h-3 animate-spin" /> : <Send className="w-3 h-3" />}</button>
      </div>
    </div>
  );
}

/* ══════════════════════════ PREVIEW PANEL ══════════════════════════ */
function SchemaPreviewPanel({ current, preview }: { current: SchemaCollection | null; preview: string | null }) {
  return (
    <div style={{ flex: 1, overflow: 'auto', padding: '12px', minHeight: 0 }}>
      {preview ? (
        <pre style={{ fontFamily: 'var(--studio-mono)', fontSize: '11px', color: 'var(--studio-text-dim)', whiteSpace: 'pre-wrap', lineHeight: 1.6, margin: 0 }}>{preview}</pre>
      ) : current ? (
        <div style={{ padding: '12px', border: '1px solid var(--studio-border)', borderRadius: '8px', background: 'var(--studio-raised)' }}>
          <h4 style={{ fontSize: '13px', fontWeight: 600, color: 'var(--studio-text)', margin: '0 0 8px' }}>{current.name || 'Sans titre'}</h4>
          <div style={{ fontFamily: 'var(--studio-mono)', fontSize: '11px', color: 'var(--studio-text-dim)', lineHeight: 1.6 }}>
            <p style={{ margin: '0 0 4px' }}>slug: {current.slug || '(auto)'} · {current.isSingleton ? 'singleton' : 'collection'}</p>
            {current.fields.map((f, i) => (
              <div key={f.key} style={{ marginLeft: '8px' }}>{i + 1}. {f.name || '?'} <span style={{ color: 'var(--studio-accent)' }}>[{f.type}]</span>{f.isRequired && <span style={{ color: 'var(--studio-amber)', fontSize: '9px' }}> • requis</span>}</div>
            ))}
          </div>
        </div>
      ) : (
        <p style={{ fontSize: '12px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '24px 0' }}>Sélectionnez une collection.</p>
      )}
    </div>
  );
}

/* ══════════════════════════ DESKTOP RIGHT PANEL ══════════════════════════ */
function DesktopRightPanel({
  project, currentCollections, onApplySchema, current, onGeneratePreview, preview,
}: {
  project: Project; currentCollections: SchemaCollection[]; onApplySchema: (c: SchemaCollection[]) => void;
  current: SchemaCollection | null; onGeneratePreview: () => void; preview: string | null;
}) {
  const [tab, setTab] = useState<'chat'|'preview'>('chat');
  return (
    <div className="drp-root">
      <style>{`
        .drp-root { display:flex; flex-direction:column; height:100%; min-height:0; }
        .drp-tabs { display:flex; flex-shrink:0; border-bottom:1px solid var(--studio-border); }
        .drp-tab { flex:1; display:flex; align-items:center; justify-content:center; gap:6px; padding:8px 10px; font-size:11px; font-weight:600; cursor:pointer; border:none; background:transparent; color:var(--studio-text-muted); border-bottom:2px solid transparent; transition:all .15s; }
        .drp-tab:hover { color:var(--studio-text-dim); }
        .drp-tab.active { color:var(--studio-accent); border-color:var(--studio-accent); }
        .drp-tab svg { width:13px; height:13px; }
      `}</style>
      <div className="drp-tabs">
        <button className={`drp-tab ${tab==='chat'?'active':''}`} onClick={()=>setTab('chat')}><MessageSquare />Chat</button>
        <button className={`drp-tab ${tab==='preview'?'active':''}`} onClick={()=>{onGeneratePreview();setTab('preview');}}><Eye />Preview</button>
      </div>
      {tab === 'chat' && <SchemaChatPanel project={project} currentCollections={currentCollections} onApplySchema={onApplySchema} />}
      {tab === 'preview' && <SchemaPreviewPanel current={current} preview={preview} />}
    </div>
  );
}

/* ══════════════════════════ MAIN SCHEMA BUILDER ══════════════════════════ */
export default function SchemaBuilder({ project }: { project: Project }) {
  const t = useTranslation();
  const [collections, setCollections] = useState<SchemaCollection[]>([]);
  const [selectedIdx, setSelectedIdx] = useState<number | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const [rightPanelOpen, setRightPanelOpen] = useState(true);
  const [mobileTab, setMobileTab] = useState<MobileTab>('editor');

  const collectionListRef = useRef<HTMLDivElement>(null);
  const current = selectedIdx !== null ? collections[selectedIdx] : null;
  const untitled = t('studio.schema.untitled');
  const sidebarW = sidebarOpen ? 170 : 0;
  const rightW = rightPanelOpen ? 280 : 0;

  /* ── Load ── */
  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      try {
        const res = await fetch(`/api/projects/${project.uuid}/studio/collections`);
        if (!res.ok) throw new Error('Failed');
        const data = await res.json() as { data: ServerCollection[] };
        if (cancelled) return;
        const loaded = (data.data ?? []).map((c): SchemaCollection => ({
          key: `col_${c.id}`, name: c.name, slug: c.slug, description: c.description ?? '', isSingleton: c.isSingleton,
          fields: (c.fields ?? []).map((f, fi): SchemaField => ({ key: `fld_${c.id}_${fi}`, name: f.name, slug: f.slug, type: f.type, isRequired: f.isRequired, options: f.options })),
        }));
        setCollections(loaded);
        if (loaded.length > 0) setSelectedIdx(0);
      } catch {} finally { if (!cancelled) setLoading(false); }
    })();
    return () => { cancelled = true; };
  }, [project.uuid]);

  /* ── CRUD ── */
  const addCollection = useCallback(() => {
    setCollections(prev => [...prev, { key: `col_${Date.now()}`, name: '', slug: '', description: '', isSingleton: false, fields: [] }]);
    setSelectedIdx(collections.length);
    setTimeout(() => collectionListRef.current?.lastElementChild?.scrollIntoView({ behavior: 'smooth' }), 50);
  }, [collections.length]);
  function updateCollection(idx: number, d: Partial<SchemaCollection>) { setCollections(prev => prev.map((c, i) => i === idx ? { ...c, ...d, slug: d.name !== undefined ? slugify(d.name) : c.slug } : c)); }
  function removeCollection(idx: number) { setCollections(prev => prev.filter((_, i) => i !== idx)); if (selectedIdx === idx) setSelectedIdx(null); else if (selectedIdx !== null && selectedIdx > idx) setSelectedIdx(selectedIdx - 1); }
  function addField() { if (selectedIdx === null) return; setCollections(prev => prev.map((c, i) => i === selectedIdx ? { ...c, fields: [...c.fields, { key: `fld_${Date.now()}`, name: '', slug: '', type: 'text', isRequired: false }] } : c)); }
  function updateField(colIdx: number, fKey: string, d: Partial<SchemaField>) { setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: c.fields.map(f => f.key === fKey ? { ...f, ...d, slug: d.name !== undefined ? slugify(d.name) : f.slug } : f) } : c)); }
  function removeField(colIdx: number, fKey: string) { setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: c.fields.filter(f => f.key !== fKey) } : c)); }
  function duplicateField(colIdx: number, fKey: string) { const col = collections[colIdx]; const f = col?.fields.find(x => x.key === fKey); if (!f) return; setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: [...c.fields, { ...f, key: `fld_${Date.now()}`, name: `${f.name} (copy)`, slug: `${f.slug}_copy` }] } : c)); }
  function handleApplySchema(newCols: SchemaCollection[]) { setCollections(prev => [...prev, ...newCols]); if (selectedIdx === null && newCols.length > 0) setSelectedIdx(collections.length); }
  async function handleSave() { setSaving(true); try { await router.post(`/api/projects/${project.uuid}/studio/schema`, { collections }, { onSuccess: () => setSaving(false), onError: () => setSaving(false) }); } catch { setSaving(false); } }
  function generatePreview() { if (!current) return; setPreview([`▸ ${current.name || untitled}`, `  slug: ${current.slug || '(auto)'}  ·  ${current.isSingleton ? 'singleton' : 'collection'}`, `  ${current.fields.length} champs:`, ...current.fields.map((f, i) => `  ${i + 1}. ${f.name || '?'} [${f.type}] ${f.isRequired ? '• requis' : ''}`)].join('\n')); }
  function selectNext() { if (selectedIdx !== null && selectedIdx < collections.length - 1) setSelectedIdx(selectedIdx + 1); }
  function selectPrev() { if (selectedIdx !== null && selectedIdx > 0) setSelectedIdx(selectedIdx - 1); }

  if (loading) return <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '80px 0' }}><Loader2 className="w-5 h-5 animate-spin" style={{ color: 'var(--studio-accent)' }} /></div>;

  return (
    <div className="sb-root">
      <style>{`
        .sb-root { display:flex; flex-direction:column; gap:12px; height:calc(100vh - 180px); min-height:0; }
        .sb-root * { box-sizing:border-box; }

        /* ── HEADER ── */
        .sb-bar { display:flex; align-items:center; gap:8px; justify-content:space-between; flex-wrap:wrap; flex-shrink:0; }
        .sb-bar h2 { font-family:var(--studio-serif); font-size:18px; font-weight:500; margin:0; color:var(--studio-text); }
        .sb-bar p { margin:1px 0 0; font-size:11px; color:var(--studio-text-dim); }
        .sb-actions { display:flex; gap:6px; flex-wrap:wrap; align-items:center; }

        /* ── DESKTOP GRID ── */
        .sb-grid-wrapper { display:flex; gap:16px; min-height:0; flex:1; }

        /* Sidebar */
        .sb-sidebar { flex-shrink:0; display:flex; flex-direction:column; gap:4px; overflow:hidden; transition:width .18s; }
        .sb-sidebar-header { display:flex; align-items:center; justify-content:space-between; padding:0 4px 6px; flex-shrink:0; }
        .sb-sidebar-header h3 { margin:0; font-size:9px; font-family:var(--studio-mono); text-transform:uppercase; letter-spacing:.08em; color:var(--studio-text-muted); }
        .sb-sidebar-list { flex:1; overflow-y:auto; display:flex; flex-direction:column; gap:3px; border:1px solid var(--studio-border); border-radius:8px; padding:6px; background:var(--studio-surface); }
        .sb-col-item { display:flex; align-items:center; gap:6px; padding:8px; border-radius:6px; cursor:pointer; border:1px solid transparent; transition:all .1s; font-size:12px; background:transparent; width:100%; text-align:left; }
        .sb-col-item:hover { background:var(--studio-raised); }
        .sb-col-item.sb-active { border-color:var(--studio-border-active); background:var(--studio-accent-dim); }
        .sb-col-item .col-name { flex:1; min-width:0; font-weight:600; font-size:12px; color:var(--studio-text); }
        .sb-col-item .col-meta { font-size:9px; color:var(--studio-text-muted); font-family:var(--studio-mono); }

        /* Editor */
        .sb-editor { flex:1; min-width:0; overflow:hidden; display:flex; flex-direction:column; }
        .sb-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:40px 20px; gap:12px; text-align:center; border:1px dashed var(--studio-border); border-radius:10px; background:var(--studio-surface); min-height:300px; }
        .sb-empty svg { opacity:.25; }
        .sb-empty h3 { font-size:15px; font-weight:600; color:var(--studio-text-dim); margin:0; }
        .sb-empty p { font-size:12px; color:var(--studio-text-muted); max-width:300px; margin:0; }
        .sb-field-card { border:1px solid var(--studio-border); border-radius:7px; padding:10px; background:var(--studio-raised); margin-bottom:4px; }
        .sb-field-card:hover { border-color:var(--studio-border-active); }

        /* Right panel */
        .sb-right-panel { flex-shrink:0; overflow:hidden; border:1px solid var(--studio-border); border-radius:10px; background:var(--studio-surface); transition:width .18s; }
        .sb-right-panel > * { flex:1; min-height:0; display:flex; flex-direction:column; }
        .sb-right-panel.collapsed { width:0!important; border:none; }

        /* Toggle buttons */
        .sb-toggle-btn { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:6px; cursor:pointer; border:1px solid var(--studio-border); background:var(--studio-surface); color:var(--studio-text-dim); flex-shrink:0; transition:all .12s; }
        .sb-toggle-btn:hover { border-color:var(--studio-border-active); color:var(--studio-accent); }
        .sb-toggle-btn svg { width:13px; height:13px; }

        /* ── MOBILE GRID ── */
        .sb-mobile-content { flex:1; min-height:0; overflow-y:auto; -webkit-overflow-scrolling:touch; }

        /* Mobile: collection list as cards */
        .sb-mobile-col-list { display:flex; flex-direction:column; gap:6px; padding:2px 0; }
        .sb-mobile-col-card { display:flex; align-items:center; gap:10px; padding:12px 14px; border:1px solid var(--studio-border); border-radius:10px; background:var(--studio-surface); cursor:pointer; transition:all .12s; }
        .sb-mobile-col-card:active { transform:scale(.98); }
        .sb-mobile-col-card.sb-active { border-color:var(--studio-border-active); background:var(--studio-accent-dim); }
        .sb-mobile-col-card .col-icon { width:36px; height:36px; border-radius:8px; background:var(--studio-accent-dim); display:flex; align-items:center; justify-content:center; color:var(--studio-accent); flex-shrink:0; }
        .sb-mobile-col-card .col-info { flex:1; min-width:0; }
        .sb-mobile-col-card .col-info .cname { font-size:13px; font-weight:600; color:var(--studio-text); }
        .sb-mobile-col-card .col-info .cmeta { font-size:10px; color:var(--studio-text-muted); font-family:var(--studio-mono); margin-top:2px; }

        /* ── BOTTOM TAB BAR (mobile only) ── */
        .sb-bottom-bar { display:none; flex-shrink:0; border-top:1px solid var(--studio-border); background:var(--studio-surface); padding:4px 8px 8px; }
        .sb-bottom-bar-inner { display:flex; gap:4px; }
        .sb-bottom-tab { flex:1; display:flex; flex-direction:column; align-items:center; gap:3px; padding:6px 0; border-radius:10px; cursor:pointer; background:transparent; border:none; color:var(--studio-text-muted); transition:all .12s; -webkit-tap-highlight-color:transparent; }
        .sb-bottom-tab:active { transform:scale(.96); }
        .sb-bottom-tab.active { color:var(--studio-accent); }
        .sb-bottom-tab .tab-badge { position:absolute; top:-2px; right:calc(50% - 14px); min-width:16px; height:16px; border-radius:999px; font-size:9px; font-weight:700; display:flex; align-items:center; justify-content:center; background:var(--studio-accent); color:#000; padding:0 4px; }
        .sb-bottom-tab svg { width:18px; height:18px; }
        .sb-bottom-tab span { font-size:10px; font-weight:600; }

        /* ── RESPONSIVE BREAKPOINTS ── */
        @media (max-width: 1024px) {
          .sb-root { height:calc(100dvh - 100px); gap:8px; }
          .sb-sidebar, .sb-right-panel { display:none!important; }
          .sb-grid-wrapper { flex-direction:column; }
          .sb-bottom-bar { display:block; }
          .sb-mobile-content { display:block; }
          .sb-bar h2 { font-size:16px; }
          .sb-bar p { display:none; }
          .sb-actions > .sb-toggle-btn { display:none; }
          .sb-actions button { font-size:11px; height:30px; }
          .sb-field-card .sb-field-row { flex-wrap:wrap; gap:4px; }
          .sb-field-card .sb-field-row > * { min-width:0; }
        }
        @media (max-width: 480px) {
          .sb-root { height:calc(100dvh - 140px); }
          .sb-bar { flex-direction:column; align-items:flex-start; gap:6px; }
          .sb-actions { width:100%; }
          .sb-actions > * { flex:1; }
        }
        @media (min-width: 1025px) {
          .sb-mobile-content { display:none; }
          .sb-bottom-bar { display:none; }
        }
      `}</style>

      {/* ═══════════════ HEADER ═══════════════ */}
      <div className="sb-bar">
        <div>
          <h2>{t('studio.schema.title')}</h2>
          <p>{collections.length} collection{collections.length>1?'s':''}{current&&` · ${current.fields.length} champs`}</p>
        </div>
        <div className="sb-actions">
          <button className="sb-toggle-btn" onClick={() => setSidebarOpen(v => !v)} title="Sidebar">{sidebarOpen ? <PanelLeftClose /> : <PanelLeftOpen />}</button>
          <button className="sb-toggle-btn" onClick={() => setRightPanelOpen(v => !v)} title="Panel">{rightPanelOpen ? <PanelRightClose /> : <PanelRightOpen />}</button>
          <Button variant="outline" size="sm" onClick={addCollection}><Plus className="w-3.5 h-3.5 mr-1 md:mr-2" /><span className="hidden sm:inline">Collection</span></Button>
          <Button size="sm" onClick={handleSave} disabled={saving || collections.length === 0} style={{ background: 'var(--studio-accent)', color: '#000' }}>{saving ? <Loader2 className="w-3.5 h-3.5 animate-spin mr-1" /> : <Save className="w-3.5 h-3.5 mr-1" />}<span className="hidden sm:inline">{t('studio.schema.apply')}</span></Button>
        </div>
      </div>

      {/* ═══════════════ DESKTOP: 3-column layout ═══════════════ */}
      <div className="sb-grid-wrapper">
        <div className="sb-sidebar" style={{ width: sidebarW > 0 ? sidebarW + 'px' : undefined }}>
          {sidebarW > 0 && (<>
            <div className="sb-sidebar-header"><h3>Collections · {collections.length}</h3></div>
            <div className="sb-sidebar-list" ref={collectionListRef}>
              {collections.map((col, idx) => (
                <button key={col.key} onClick={() => setSelectedIdx(idx)} className={`sb-col-item${idx === selectedIdx ? ' sb-active' : ''}`}>
                  <div style={{ flex: 1, minWidth: 0 }}><div className="col-name">{col.name || untitled}</div><div className="col-meta">{col.fields.length} champs</div></div>
                  <button onClick={e => { e.stopPropagation(); removeCollection(idx); }} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '1px', opacity: .4 }}><Trash2 className="w-3 h-3" style={{ color: 'var(--studio-red)' }} /></button>
                </button>
              ))}
              {collections.length === 0 && <p style={{ fontSize: '11px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '16px 6px' }}>Vide</p>}
            </div>
          </>)}
        </div>

        {/* Desktop Editor (same as before, unchanged) */}
        <div className="sb-editor desktop-editor">
          <style>{`@media(max-width:1024px){.desktop-editor{display:none!important}}`}</style>
          {renderEditor(current, selectedIdx, collections, untitled, t, selectPrev, selectNext, addCollection, updateCollection, removeCollection, addField, updateField, removeField, duplicateField, generatePreview)}
        </div>

        <div className={`sb-right-panel${rightPanelOpen ? '' : ' collapsed'}`} style={{ width: rightW > 0 ? rightW + 'px' : undefined }}>
          {rightW > 0 && (
            <DesktopRightPanel project={project} currentCollections={collections} onApplySchema={handleApplySchema} current={current} onGeneratePreview={generatePreview} preview={preview} />
          )}
        </div>
      </div>

      {/* ═══════════════ MOBILE: tab-based content ═══════════════ */}
      <div className="sb-mobile-content">
        {mobileTab === 'collections' && (
          <MobileCollectionsView
            collections={collections} selectedIdx={selectedIdx} untitled={untitled}
            onSelect={(idx) => { setSelectedIdx(idx); setMobileTab('editor'); }}
            onDelete={removeCollection}
            onAdd={addCollection}
          />
        )}
        {mobileTab === 'editor' && (
          <MobileEditorView
            current={current} selectedIdx={selectedIdx} total={collections.length}
            untitled={untitled} t={t}
            onPrev={selectPrev} onNext={selectNext}
            onAddCollection={addCollection}
            updateCollection={updateCollection}
            addField={addField} updateField={updateField}
            removeField={removeField} duplicateField={duplicateField}
          />
        )}
        {mobileTab === 'chat' && (
          <div style={{ display: 'flex', flexDirection: 'column', height: '100%', minHeight: 0 }}>
            <DesktopRightPanel
              project={project} currentCollections={collections} onApplySchema={handleApplySchema}
              current={current} onGeneratePreview={generatePreview} preview={preview}
            />
          </div>
        )}
      </div>

      {/* ═══════════════ MOBILE: bottom tab bar ═══════════════ */}
      <div className="sb-bottom-bar">
        <div className="sb-bottom-bar-inner">
          <button className={`sb-bottom-tab${mobileTab === 'collections' ? ' active' : ''}`} onClick={() => setMobileTab('collections')}>
            <FolderOpen />Collection{collections.length > 0 && <span className="tab-badge">{collections.length}</span>}
            <span>Collections</span>
          </button>
          <button className={`sb-bottom-tab${mobileTab === 'editor' ? ' active' : ''}`} onClick={() => setMobileTab('editor')}>
            <Pencil />
            <span>Éditeur</span>
          </button>
          <button className={`sb-bottom-tab${mobileTab === 'chat' ? ' active' : ''}`} onClick={() => setMobileTab('chat')}>
            <Sparkles />
            <span>Chat IA</span>
          </button>
        </div>
      </div>
      <style>{`@media (min-width: 1025px) { .sb-bottom-bar { display: none !important; } .sb-mobile-content { display: none !important; } }`}</style>
    </div>
  );
}

/* ═══════════════ SHARED EDITOR RENDERER (desktop + mobile) ═══════════════ */
function renderEditor(
  current: SchemaCollection | null, selectedIdx: number | null, collections: SchemaCollection[],
  untitled: string, t: any,
  selectPrev: () => void, selectNext: () => void,
  addCollection: () => void, updateCollection: (i: number, d: Partial<SchemaCollection>) => void, removeCollection: (i: number) => void,
  addField: () => void, updateField: (ci: number, fk: string, d: Partial<SchemaField>) => void, removeField: (ci: number, fk: string) => void, duplicateField: (ci: number, fk: string) => void,
  generatePreview: () => void,
) {
  if (!current) return (
    <div className="sb-empty">
      <Wand2 style={{ width: '36px', height: '36px' }} />
      <div>
        <h3>{collections.length === 0 ? 'Créez votre première collection' : 'Sélectionnez une collection'}</h3>
        <p>{collections.length === 0 ? 'Définissez votre modèle de données.' : 'Choisissez une collection dans la barre latérale.'}</p>
      </div>
      {collections.length === 0 && <Button onClick={addCollection} style={{ background: 'var(--studio-accent)', color: '#000' }}><Plus className="w-4 h-4 mr-2" />Nouvelle collection</Button>}
    </div>
  );

  return (<>
    {/* Collection form */}
    <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px', marginBottom: '10px' }}>
      <div><Label style={{ fontSize: '10px', color: 'var(--studio-text-muted)' }}>{t('common.name')}</Label><Input value={current.name} onChange={e => updateCollection(selectedIdx!, { name: e.target.value })} placeholder={t('studio.schema.articles_ph')} style={{ height: '32px', fontSize: '12px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} /></div>
      <div><Label style={{ fontSize: '10px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.slug_label')}</Label><Input value={current.slug} onChange={e => updateCollection(selectedIdx!, { slug: e.target.value })} placeholder="articles" style={{ height: '32px', fontSize: '12px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)', fontFamily: 'var(--studio-mono)' }} /></div>
    </div>
    <div style={{ marginBottom: '10px' }}>
      <Label style={{ fontSize: '10px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.desc_label')}</Label>
      <Input value={current.description} onChange={e => updateCollection(selectedIdx!, { description: e.target.value })} placeholder={t('studio.schema.desc_placeholder')} style={{ height: '32px', fontSize: '12px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} />
    </div>
    <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '14px' }}>
      <Switch checked={current.isSingleton} onCheckedChange={v => updateCollection(selectedIdx!, { isSingleton: v })} />
      <Label style={{ fontSize: '12px', color: 'var(--studio-text-dim)' }}>{t('studio.schema.singleton_label')}</Label>
    </div>
    <Separator style={{ marginBottom: '12px', borderColor: 'var(--studio-border)' }} />

    {/* Fields */}
    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '8px', gap: '8px' }}>
      <h3 style={{ fontSize: '9px', fontFamily: 'var(--studio-mono)', textTransform: 'uppercase', letterSpacing: '.08em', color: 'var(--studio-text-muted)', margin: 0 }}>Champs · {current.fields.length}</h3>
      <Button size="sm" onClick={addField} style={{ height: '26px', fontSize: '10px', background: 'var(--studio-accent)', color: '#000', padding: '0 8px' }}><Plus className="w-3 h-3 mr-1" />Ajouter</Button>
    </div>
    <div style={{ flex: 1, overflowY: 'auto', paddingRight: '2px', minHeight: 0 }}>
      {current.fields.map((field, idx) => {
        const Icon = ICON_MAP[field.type] || Type;
        return (
          <div key={field.key} className="sb-field-card">
            <div className="sb-field-row" style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '4px' }}>
              <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '9px', color: 'var(--studio-text-muted)', minWidth: '14px' }}>{idx + 1}</span>
              <Input placeholder="Nom du champ" value={field.name} onChange={e => updateField(selectedIdx!, field.key, { name: e.target.value })} style={{ height: '28px', fontSize: '11px', flex: 1, minWidth: 0, background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} />
              <Select value={field.type} onValueChange={v => updateField(selectedIdx!, field.key, { type: v })}>
                <SelectTrigger style={{ width: '110px', height: '28px', fontSize: '10px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }}><SelectValue /></SelectTrigger>
                <SelectContent>{FIELD_TYPES.map(ft => (<SelectItem key={ft.type} value={ft.type}>{ft.label}</SelectItem>))}</SelectContent>
              </Select>
              <Button variant="ghost" size="icon" style={{ height: '22px', width: '22px' }} onClick={() => duplicateField(selectedIdx!, field.key)}><Copy className="w-3 h-3" /></Button>
              <Button variant="ghost" size="icon" style={{ height: '22px', width: '22px' }} onClick={() => removeField(selectedIdx!, field.key)}><Trash2 className="w-3 h-3" style={{ color: 'var(--studio-red)' }} /></Button>
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: '10px', paddingLeft: '20px' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: '3px' }}>
                <Switch checked={field.isRequired} onCheckedChange={v => updateField(selectedIdx!, field.key, { isRequired: v })} />
                <span style={{ fontSize: '10px', color: 'var(--studio-text-muted)' }}>Requis</span>
              </div>
              <Badge variant="secondary" style={{ fontSize: '9px', background: 'var(--studio-accent-dim)', color: 'var(--studio-accent)', border: 'none', padding: '1px 6px' }}><Icon className="w-2.5 h-2.5 mr-1" />{FIELD_TYPES.find(ft => ft.type === field.type)?.label ?? field.type}</Badge>
            </div>
          </div>
        );
      })}
      {current.fields.length === 0 && <p style={{ fontSize: '11px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '20px' }}>Aucun champ. Cliquez "Ajouter".</p>}
    </div>
  </>);
}

/* ═══════════════ MOBILE COLLECTIONS VIEW ═══════════════ */
function MobileCollectionsView({
  collections, selectedIdx, untitled,
  onSelect, onDelete, onAdd,
}: {
  collections: SchemaCollection[]; selectedIdx: number | null; untitled: string;
  onSelect: (idx: number) => void; onDelete: (idx: number) => void; onAdd: () => void;
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '8px', paddingBottom: '16px' }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between' }}>
        <h3 style={{ fontSize: '10px', fontFamily: 'var(--studio-mono)', textTransform: 'uppercase', letterSpacing: '.08em', color: 'var(--studio-text-muted)', margin: 0 }}>{collections.length} collection{collections.length>1?'s':''}</h3>
        <Button size="sm" onClick={onAdd} style={{ height: '28px', fontSize: '11px', background: 'var(--studio-accent)', color: '#000' }}><Plus className="w-3 h-3 mr-1" />Nouvelle</Button>
      </div>
      {collections.length === 0 && (
        <div style={{ textAlign: 'center', padding: '48px 16px', color: 'var(--studio-text-muted)' }}>
          <FolderOpen style={{ width: '40px', height: '40px', opacity: .2, margin: '0 auto 12px', display: 'block' }} />
          <p style={{ fontSize: '13px', margin: 0 }}>Aucune collection créée</p>
          <p style={{ fontSize: '11px', margin: '4px 0 16px' }}>Appuyez sur "+ Nouvelle" ou utilisez le Chat IA.</p>
          <Button onClick={onAdd} style={{ background: 'var(--studio-accent)', color: '#000' }}><Plus className="w-3.5 h-3.5 mr-1" />Nouvelle collection</Button>
        </div>
      )}
      <div className="sb-mobile-col-list">
        {collections.map((col, idx) => (
          <div key={col.key} className={`sb-mobile-col-card${idx === selectedIdx ? ' sb-active' : ''}`} onClick={() => onSelect(idx)}>
            <div className="col-icon"><FolderOpen className="w-4 h-4" /></div>
            <div className="col-info">
              <div className="cname">{col.name || untitled}</div>
              <div className="cmeta">{col.fields.length} champs · {col.isSingleton ? 'singleton' : 'collection'} · {col.slug || 'sans slug'}</div>
            </div>
            <button onClick={e => { e.stopPropagation(); onDelete(idx); }} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '4px' }}><Trash2 className="w-4 h-4" style={{ color: 'var(--studio-red)' }} /></button>
          </div>
        ))}
      </div>
    </div>
  );
}

/* ═══════════════ MOBILE EDITOR VIEW ═══════════════ */
function MobileEditorView({
  current, selectedIdx, total, untitled, t,
  onPrev, onNext, onAddCollection,
  updateCollection, addField, updateField, removeField, duplicateField,
}: {
  current: SchemaCollection | null; selectedIdx: number | null; total: number; untitled: string; t: any;
  onPrev: () => void; onNext: () => void; onAddCollection: () => void;
  updateCollection: (i: number, d: Partial<SchemaCollection>) => void;
  addField: () => void; updateField: (ci: number, fk: string, d: Partial<SchemaField>) => void;
  removeField: (ci: number, fk: string) => void; duplicateField: (ci: number, fk: string) => void;
}) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', minHeight: 0, paddingBottom: '16px' }}>
      {/* Collection selector bar */}
      <div style={{ display: 'flex', alignItems: 'center', gap: '6px', marginBottom: '12px', padding: '8px 10px', border: '1px solid var(--studio-border)', borderRadius: '10px', background: 'var(--studio-surface)' }}>
        <button onClick={onPrev} disabled={selectedIdx === null || selectedIdx === 0} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--studio-text-dim)', padding: '4px', opacity: selectedIdx !== null && selectedIdx > 0 ? 1 : .3 }}><ChevronLeft className="w-4 h-4" /></button>
        <span style={{ flex: 1, textAlign: 'center', fontFamily: 'var(--studio-mono)', fontSize: '11px', color: 'var(--studio-text-muted)' }}>
          {selectedIdx !== null ? `${selectedIdx + 1} / ${total}` : '—'}
        </span>
        <button onClick={onNext} disabled={selectedIdx === null || selectedIdx === total - 1} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--studio-text-dim)', padding: '4px', opacity: selectedIdx !== null && selectedIdx < total - 1 ? 1 : .3 }}><ChevronRight className="w-4 h-4" /></button>
        <div style={{ flex: 3, textAlign: 'center', fontSize: '13px', fontWeight: 600, color: 'var(--studio-text)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{current ? (current.name || untitled) : 'Aucune'}</div>
      </div>

      {current ? (
        <>
          {renderEditor(current, selectedIdx, [], untitled, t, onPrev, onNext, onAddCollection, updateCollection, () => {}, addField, updateField, removeField, duplicateField, () => {})}
        </>
      ) : (
        <div className="sb-empty" style={{ minHeight: '200px' }}>
          <FolderOpen style={{ width: '36px', height: '36px' }} />
          <h3>Aucune collection sélectionnée</h3>
          <p>Appuyez sur l'onglet Collections pour en choisir une ou en créer une.</p>
        </div>
      )}
    </div>
  );
}
