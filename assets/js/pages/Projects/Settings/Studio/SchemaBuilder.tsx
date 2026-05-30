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
  Plus, Trash2, Eye, Save, Copy, Wand2, Sparkles, Loader2, ChevronRight,
  Type, AlignLeft, Hash, List, ToggleLeft, Calendar, Clock,
  AtSign, Link, Lock, Palette, Image, GitBranch, Code2, FileText, X, Layers,
  MessageSquare, Send, Bot, User, Check, RefreshCw, PanelRightClose, PanelRightOpen
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

/* ── SchemaChatPanel ── */
function SchemaChatPanel({
  project, currentCollections, onApplySchema, onClose,
}: {
  project: Project;
  currentCollections: SchemaCollection[];
  onApplySchema: (newCollections: SchemaCollection[]) => void;
  onClose?: () => void;
}) {
  const t = useTranslation();
  const [messages, setMessages] = useState<ChatMessage[]>([
    { role: 'assistant', content: 'Bonjour ! Décris-moi les collections que tu souhaites créer ou modifier pour ton projet, et je génère le schéma correspondant. Tu peux aussi me demander d\'ajouter des champs à une collection existante ou de repenser tout le schéma.', schema: undefined },
  ]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const chatEndRef = useRef<HTMLDivElement>(null);

  const scrollDown = () => setTimeout(() => chatEndRef.current?.scrollIntoView({ behavior: 'smooth' }), 80);

  /* Traduit les collections courantes en description pour le contexte AI */
  function buildContext(): string {
    if (currentCollections.length === 0) return 'Le projet n\'a encore aucune collection.';
    return 'Collections existantes dans le projet :\n' + currentCollections.map(c =>
      `- ${c.name} (slug: ${c.slug}, ${c.isSingleton ? 'singleton' : 'collection'}): ${c.fields.map(f => f.name + ' [' + f.type + ']' + (f.isRequired ? '*' : '')).join(', ') || 'aucun champ'}`
    ).join('\n');
  }

  async function send() {
    const prompt = input.trim();
    if (!prompt || busy) return;
    setInput('');
    setBusy(true);
    const userMsg: ChatMessage = { role: 'user', content: prompt, schema: undefined };
    setMessages(prev => [...prev, userMsg]);
    scrollDown();

    try {
      const res = await fetch(`/api/projects/${project.uuid}/studio/ai-chat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          prompt,
          context: buildContext(),
          history: messages.slice(-6).map(m => ({ role: m.role, content: m.content })),
        }),
      });
      const data = await res.json() as { reply?: string; collections?: Array<{ name: string; slug: string; description: string; isSingleton: boolean; fields: Array<{ name: string; slug: string; type: string; isRequired: boolean }> }>; error?: string };
      if (!res.ok || data.error) throw new Error(data.error ?? 'Erreur');

      const assistantMsg: ChatMessage = {
        role: 'assistant',
        content: data.reply ?? 'Schéma généré. Tu peux l\'appliquer ci-dessous.',
        schema: data.collections,
      };
      setMessages(prev => [...prev, assistantMsg]);
    } catch (e: any) {
      setMessages(prev => [...prev, { role: 'assistant', content: 'Désolé, une erreur est survenue. Réessaie.', schema: undefined }]);
    } finally {
      setBusy(false);
      scrollDown();
    }
  }

  function handleApplySchema(schema: NonNullable<ChatMessage['schema']>) {
    const newCols: SchemaCollection[] = schema.map(c => ({
      key: `col_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
      name: c.name, slug: c.slug,
      description: c.description ?? '',
      isSingleton: c.isSingleton ?? false,
      fields: (c.fields ?? []).map(f => ({
        key: `fld_${Date.now()}_${Math.random().toString(36).slice(2, 6)}`,
        name: f.name, slug: f.slug, type: f.type, isRequired: f.isRequired ?? false,
      })),
    }));
    onApplySchema(newCols);
    setMessages(prev => [...prev, { role: 'system', content: `✅ ${newCols.length} collection(s) ajoutée(s) au schéma.`, schema: undefined }]);
    scrollDown();
  }

  const quickPrompts = [
    'Crée un blog avec articles, catégories et commentaires',
    'Ajoute un champ "auteur" de type relation à Blog Posts',
    'Crée une page À propos en singleton avec titre, image, contenu',
    'Repense tout le schéma pour un site e-commerce',
  ];

  return (
    <div className="scp-root">
      <style>{`
        .scp-root { display: flex; flex-direction: column; height: 100%; min-height: 0; }
        .scp-root * { box-sizing: border-box; }

        .scp-header {
          display: flex; align-items: center; justify-content: space-between;
          padding: 12px 14px; border-bottom: 1px solid var(--studio-border, rgba(255,255,255,.06));
          flex-shrink: 0;
        }
        .scp-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: var(--studio-text, #dde4df); }
        .scp-title svg { width: 16px; height: 16px; color: var(--studio-accent, #2fcf8f); }

        .scp-messages { flex: 1; overflow-y: auto; padding: 12px 10px; display: flex; flex-direction: column; gap: 10px; min-height: 0; }
        .scp-msg { display: flex; gap: 8px; font-size: 12px; line-height: 1.55; max-width: 100%; }
        .scp-msg.user { flex-direction: row-reverse; }
        .scp-msg .avatar {
          width: 26px; height: 26px; border-radius: 7px; flex-shrink: 0;
          display: flex; align-items: center; justify-content: center;
          font-size: 12px;
        }
        .scp-msg.assistant .avatar { background: rgba(47,207,143,.12); color: var(--studio-accent, #2fcf8f); }
        .scp-msg.user .avatar { background: rgba(255,255,255,.08); color: var(--studio-text-dim, #85918b); }
        .scp-msg.system .avatar { background: rgba(247,185,85,.12); color: var(--studio-amber, #f7b955); }
        .scp-msg .bubble {
          padding: 8px 12px; border-radius: 10px; max-width: 85%;
          background: var(--studio-raised, #171d19); color: var(--studio-text-dim, #85918b);
          border: 1px solid var(--studio-border, rgba(255,255,255,.06));
        }
        .scp-msg.user .bubble { background: rgba(47,207,143,.10); border-color: rgba(47,207,143,.15); color: var(--studio-text, #dde4df); }

        .scp-schema-card {
          margin-top: 8px; padding: 10px; border-radius: 8px;
          background: var(--studio-surface, #111714);
          border: 1px solid rgba(47,207,143,.15);
          font-size: 11px; line-height: 1.6;
        }
        .scp-schema-card h5 { margin: 0 0 6px; font-size: 12px; color: var(--studio-accent, #2fcf8f); }
        .scp-schema-card ul { margin: 4px 0; padding-left: 16px; }
        .scp-schema-card li { color: var(--studio-text-muted, #5c6762); margin: 2px 0; }
        .scp-schema-card li strong { color: var(--studio-text-dim, #85918b); }

        .scp-apply-btn {
          margin-top: 8px; display: flex; align-items: center; gap: 6px;
          padding: 6px 12px; border-radius: 7px; cursor: pointer;
          font-size: 11px; font-weight: 600; border: none;
          background: var(--studio-accent, #2fcf8f); color: #000;
        }
        .scp-apply-btn:hover { filter: brightness(1.1); }

        .scp-input-row {
          display: flex; gap: 6px; padding: 10px 12px; border-top: 1px solid var(--studio-border, rgba(255,255,255,.06));
          flex-shrink: 0; background: var(--studio-surface, #111714);
        }
        .scp-input-row input {
          flex: 1; height: 34px; border-radius: 8px; padding: 0 10px; font-size: 12px;
          background: var(--studio-bg, #0b0f0d); border: 1px solid var(--studio-border, rgba(255,255,255,.06));
          color: var(--studio-text, #dde4df); outline: none;
        }
        .scp-input-row input:focus { border-color: var(--studio-border-active, rgba(47,207,143,.25)); }
        .scp-input-row button {
          height: 34px; width: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
          cursor: pointer; border: none; background: var(--studio-accent, #2fcf8f); color: #000;
          flex-shrink: 0;
        }
        .scp-input-row button:disabled { opacity: .4; cursor: not-allowed; }

        .scp-quick-prompts { display: flex; gap: 6px; padding: 8px 12px; overflow-x: auto; flex-shrink: 0; scrollbar-width: none; }
        .scp-quick-prompts::-webkit-scrollbar { display: none; }
        .scp-quick-pill {
          flex-shrink: 0; padding: 5px 10px; border-radius: 999px; font-size: 10px; cursor: pointer;
          border: 1px solid var(--studio-border, rgba(255,255,255,.06));
          background: var(--studio-surface, #111714); color: var(--studio-text-muted, #5c6762);
          transition: all .12s ease; white-space: nowrap;
        }
        .scp-quick-pill:hover { border-color: var(--studio-border-active, rgba(47,207,143,.25)); color: var(--studio-text-dim, #85918b); }
      `}</style>

      {/* Header */}
      <div className="scp-header">
        <div className="scp-title"><MessageSquare />Schema Chat</div>
        {onClose && <button onClick={onClose} style={{ background: 'none', border: 'none', cursor: 'pointer', color: 'var(--studio-text-muted)', padding: '2px' }}><X className="w-4 h-4" /></button>}
      </div>

      {/* Messages */}
      <div className="scp-messages">
        {messages.map((m, i) => (
          <div key={i} className={`scp-msg ${m.role}`}>
            <div className="avatar">
              {m.role === 'assistant' ? <Bot className="w-3.5 h-3.5" /> : m.role === 'system' ? <Check className="w-3.5 h-3.5" /> : <User className="w-3.5 h-3.5" />}
            </div>
            <div className="bubble">
              <div style={{ whiteSpace: 'pre-wrap' }}>{m.content}</div>
              {m.schema && m.schema.length > 0 && (
                <div className="scp-schema-card">
                  <h5>📋 Schéma généré ({m.schema.length} collection{m.schema.length > 1 ? 's' : ''})</h5>
                  {m.schema.map((col, ci) => (
                    <div key={ci} style={{ marginBottom: ci < m.schema!.length - 1 ? 8 : 0 }}>
                      <div style={{ fontWeight: 600, color: 'var(--studio-text)' }}>
                        {col.name} <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '10px', color: 'var(--studio-text-muted)' }}>({col.slug})</span>
                        {col.isSingleton && <Badge variant="secondary" style={{ fontSize: '9px', marginLeft: '6px', background: 'var(--studio-accent-dim)', color: 'var(--studio-accent)', border: 'none' }}>singleton</Badge>}
                      </div>
                      <ul>
                        {col.fields.map((f, fi) => (
                          <li key={fi}>{f.isRequired && <strong>* </strong>}<strong>{f.name}</strong> <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '10px' }}>[{f.type}]</span></li>
                        ))}
                      </ul>
                    </div>
                  ))}
                  <button className="scp-apply-btn" onClick={() => handleApplySchema(m.schema!)}>
                    <Check className="w-3.5 h-3.5" />Appliquer au schéma
                  </button>
                </div>
              )}
            </div>
          </div>
        ))}
        {busy && (
          <div className="scp-msg assistant">
            <div className="avatar"><Bot className="w-3.5 h-3.5" /></div>
            <div className="bubble" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
              <Loader2 className="w-3.5 h-3.5 animate-spin" style={{ color: 'var(--studio-accent)' }} />
              Génération en cours...
            </div>
          </div>
        )}
        <div ref={chatEndRef} />
      </div>

      {/* Quick prompts */}
      <div className="scp-quick-prompts">
        {quickPrompts.map((qp, i) => (
          <button key={i} className="scp-quick-pill" onClick={() => { setInput(qp); }} disabled={busy}>{qp}</button>
        ))}
      </div>

      {/* Input */}
      <div className="scp-input-row">
        <input
          placeholder="Décris les collections souhaitées..."
          value={input}
          onChange={e => setInput(e.target.value)}
          onKeyDown={e => e.key === 'Enter' && send()}
          disabled={busy}
        />
        <button onClick={send} disabled={busy || !input.trim()}>
          {busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Send className="w-3.5 h-3.5" />}
        </button>
      </div>
    </div>
  );
}

/* ── SchemaBuilder ── */
export default function SchemaBuilder({ project }: { project: Project }) {
  const t = useTranslation();
  const [collections, setCollections] = useState<SchemaCollection[]>([]);
  const [selectedIdx, setSelectedIdx] = useState<number | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(true);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [chatOpen, setChatOpen] = useState(false);

  const collectionListRef = useRef<HTMLDivElement>(null);
  const current = selectedIdx !== null ? collections[selectedIdx] : null;

  /* ── Load existing collections ── */
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
          name: c.name, slug: c.slug,
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
      } catch { /* pas encore de collections */ }
      finally { if (!cancelled) setLoading(false); }
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

  /* ── Called by SchemaChatPanel ── */
  function handleApplySchema(newCols: SchemaCollection[]) {
    setCollections(prev => [...prev, ...newCols]);
    if (selectedIdx === null && newCols.length > 0) setSelectedIdx(collections.length);
  }

  /* ── Save ── */
  async function handleSave() {
    setSaving(true);
    try {
      await router.post(`/api/projects/${project.uuid}/studio/schema`, { collections }, {
        onSuccess: () => setSaving(false), onError: () => setSaving(false),
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
      ...current.fields.map((f, i) => `  ${i + 1}. ${f.name || '?'} [${f.type}] ${f.isRequired ? '• requis' : ''}`),
    ];
    setPreview(lines.join('\n'));
  }

  function selectNext() {
    if (selectedIdx !== null && selectedIdx < collections.length - 1) setSelectedIdx(selectedIdx + 1);
  }
  function selectPrev() {
    if (selectedIdx !== null && selectedIdx > 0) setSelectedIdx(selectedIdx - 1);
  }

  const untitled = t('studio.schema.untitled');

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
        .sb-root { display: flex; flex-direction: column; gap: 20px; }
        .sb-root * { box-sizing: border-box; }

        .sb-bar { display: flex; align-items: center; gap: 10px; justify-content: space-between; flex-wrap: wrap; }
        .sb-bar h2 { font-family: var(--studio-serif, Georgia, serif); font-size: 20px; font-weight: 500; margin: 0; color: var(--studio-text, #dde4df); }
        .sb-bar p { margin: 2px 0 0; font-size: 12px; color: var(--studio-text-dim, #85918b); }
        .sb-actions { display: flex; gap: 8px; flex-wrap: wrap; }

        /* ── Main grid: list | editor | chat ── */
        .sb-grid { display: grid; grid-template-columns: 260px 1fr 280px; gap: 20px; }

        .sb-col-list {
          display: flex; flex-direction: column; gap: 4px;
          border: 1px solid var(--studio-border, rgba(255,255,255,.06));
          border-radius: 10px; padding: 8px; background: var(--studio-surface, #111714);
          max-height: calc(100vh - 280px); overflow-y: auto;
        }
        .sb-col-item {
          display: flex; align-items: center; gap: 8px;
          padding: 10px; border-radius: 7px; cursor: pointer;
          border: 1px solid transparent; transition: all .12s ease; font-size: 13px;
        }
        .sb-col-item:hover { background: var(--studio-raised, #171d19); }
        .sb-col-item.sb-active {
          border-color: var(--studio-border-active, rgba(47,207,143,.25));
          background: var(--studio-accent-dim, rgba(47,207,143,.08));
        }
        .sb-col-item .col-name { flex: 1; min-width: 0; font-weight: 600; }
        .sb-col-item .col-meta { font-size: 10px; color: var(--studio-text-muted, #5c6762); font-family: var(--studio-mono, monospace); }

        .sb-editor { min-width: 0; }
        .sb-empty {
          display: flex; flex-direction: column; align-items: center; justify-content: center;
          padding: 60px 20px; gap: 16px; text-align: center;
          border: 1px dashed var(--studio-border); border-radius: 10px;
          background: var(--studio-surface);
        }
        .sb-empty svg { opacity: 0.25; }
        .sb-empty h3 { font-size: 16px; font-weight: 600; color: var(--studio-text-dim); margin: 0; }
        .sb-empty p { font-size: 13px; color: var(--studio-text-muted); max-width: 320px; margin: 0; }

        .sb-field-card {
          border: 1px solid var(--studio-border); border-radius: 8px; padding: 12px;
          background: var(--studio-raised); margin-bottom: 6px;
        }
        .sb-field-card:hover { border-color: var(--studio-border-active); }

        /* ── Chat panel ── */
        .sb-chat-panel {
          border: 1px solid var(--studio-border); border-radius: 10px;
          background: var(--studio-surface); overflow: hidden;
          display: flex; flex-direction: column;
          max-height: calc(100vh - 280px); min-height: 400px;
          position: sticky; top: 0;
        }

        .sb-chat-toggle {
          display: none; align-items: center; gap: 6px;
          padding: 6px 12px; border-radius: 8px; cursor: pointer;
          font-size: 12px; font-weight: 600; border: 1px solid var(--studio-border);
          background: var(--studio-surface); color: var(--studio-text-dim);
        }
        .sb-chat-toggle:hover { border-color: var(--studio-border-active); color: var(--studio-accent); }

        /* ── Responsive ── */
        @media (max-width: 1200px) {
          .sb-grid { grid-template-columns: 240px 1fr 260px; }
        }
        @media (max-width: 1024px) {
          .sb-grid { grid-template-columns: 1fr; }
          .sb-col-list-wrap { display: none; }
          .sb-chat-panel { display: none; max-height: none; }
          .sb-chat-toggle { display: inline-flex; }
        }
        @media (max-width: 768px) {
          .sb-bar h2 { font-size: 18px; }
          .sb-actions { width: 100%; }
          .sb-actions button { flex: 1; }
          .sb-field-card .sb-field-row { flex-wrap: wrap; }
        }
        @media (max-width: 480px) {
          .sb-bar { flex-direction: column; align-items: flex-start; }
          .sb-actions { flex-wrap: wrap; }
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
        <div className="sb-actions">
          {/* Mobile: collection drawer */}
          <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
            <SheetTrigger asChild>
              <Button variant="outline" size="sm" className="lg-plus:hidden" style={{ display: 'none' }}>
                <Layers className="w-3.5 h-3.5 mr-1" />{collections.length} collections
              </Button>
            </SheetTrigger>
            <SheetContent side="left" style={{ width: '280px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)' }}>
              <SheetHeader><SheetTitle style={{ fontFamily: 'var(--studio-serif)', color: 'var(--studio-text)' }}>{t('studio.schema.collections_title')}</SheetTitle></SheetHeader>
              <div style={{ display: 'flex', flexDirection: 'column', gap: '4px', marginTop: '12px' }}>
                {collections.map((col, idx) => (
                  <button key={col.key} onClick={() => { setSelectedIdx(idx); setSheetOpen(false); }}
                    className={`sb-col-item${idx === selectedIdx ? ' sb-active' : ''}`}
                    style={{ width: '100%', textAlign: 'left', background: idx === selectedIdx ? 'var(--studio-accent-dim)' : 'transparent', border: idx === selectedIdx ? '1px solid var(--studio-border-active)' : '1px solid transparent', padding: '10px', borderRadius: '7px', cursor: 'pointer' }}>
                    <div>
                      <div className="col-name" style={{ fontSize: '13px', fontWeight: 600, color: 'var(--studio-text)' }}>{col.name || untitled}</div>
                      <div className="col-meta">{col.fields.length} champs · {col.isSingleton ? 'singleton' : 'collection'}</div>
                    </div>
                  </button>
                ))}
                <Button variant="ghost" size="sm" onClick={() => { addCollection(); setSheetOpen(false); }} style={{ justifyContent: 'flex-start' }}>
                  <Plus className="w-3.5 h-3.5 mr-2" />{t('studio.schema.new_collection')}
                </Button>
              </div>
            </SheetContent>
          </Sheet>

          {/* Chat toggle (mobile/tablet) */}
          <button className="sb-chat-toggle" onClick={() => setChatOpen(true)}>
            <MessageSquare className="w-3.5 h-3.5" />Chat IA
          </button>

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
        {/* LEFT: Collection list (desktop) */}
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
            {/* Mobile nav */}
            <div style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '12px' }} className="lg-plus:hidden">
              <style>{`@media(min-width:1025px){.lg-plus\\:hidden{display:none!important}}`}</style>
              <Button variant="ghost" size="icon" onClick={selectPrev} disabled={selectedIdx === null || selectedIdx === 0}><ChevronRight className="w-4 h-4 rotate-180" /></Button>
              <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '11px', color: 'var(--studio-text-muted)' }}>{(selectedIdx ?? 0) + 1} / {collections.length}</span>
              <Button variant="ghost" size="icon" onClick={selectNext} disabled={selectedIdx === null || selectedIdx === collections.length - 1}><ChevronRight className="w-4 h-4" /></Button>
              <span style={{ fontSize: '13px', fontWeight: 600, color: 'var(--studio-text)' }}>{current.name || untitled}</span>
            </div>

            {/* Collection form */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px', marginBottom: '12px' }}>
              <div><Label style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('common.name')}</Label>
                <Input value={current.name} onChange={e => updateCollection(selectedIdx!, { name: e.target.value })} placeholder={t('studio.schema.articles_ph')}
                  style={{ height: '34px', fontSize: '13px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} />
              </div>
              <div><Label style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.slug_label')}</Label>
                <Input value={current.slug} onChange={e => updateCollection(selectedIdx!, { slug: e.target.value })} placeholder="articles"
                  style={{ height: '34px', fontSize: '13px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)', fontFamily: 'var(--studio-mono)' }} />
              </div>
            </div>
            <div style={{ marginBottom: '12px' }}>
              <Label style={{ fontSize: '11px', color: 'var(--studio-text-muted)' }}>{t('studio.schema.desc_label')}</Label>
              <Input value={current.description} onChange={e => updateCollection(selectedIdx!, { description: e.target.value })} placeholder={t('studio.schema.desc_placeholder')}
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
                <Button variant="outline" size="sm" onClick={generatePreview} style={{ height: '28px', fontSize: '11px' }}><Eye className="w-3 h-3 mr-1" />Preview</Button>
                <Button size="sm" onClick={addField} style={{ height: '28px', fontSize: '11px', background: 'var(--studio-accent)', color: '#000' }}><Plus className="w-3 h-3 mr-1" />{t('studio.schema.add_field_btn')}</Button>
              </div>
            </div>

            {/* Field list */}
            <div style={{ maxHeight: 'calc(100vh - 520px)', overflowY: 'auto', paddingRight: '4px' }}>
              {current.fields.map((field, idx) => {
                const Icon = ICON_MAP[field.type] || Type;
                return (
                  <div key={field.key} className="sb-field-card">
                    <div className="sb-field-row" style={{ display: 'flex', alignItems: 'center', gap: '8px', marginBottom: '6px' }}>
                      <span style={{ fontFamily: 'var(--studio-mono)', fontSize: '10px', color: 'var(--studio-text-muted)', minWidth: '16px' }}>{idx + 1}</span>
                      <Input placeholder={t('studio.schema.field_name_ph')} value={field.name} onChange={e => updateField(selectedIdx!, field.key, { name: e.target.value })}
                        style={{ height: '30px', fontSize: '12px', flex: 1, minWidth: 0, background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }} />
                      <Select value={field.type} onValueChange={v => updateField(selectedIdx!, field.key, { type: v })}>
                        <SelectTrigger style={{ width: '120px', height: '30px', fontSize: '11px', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', color: 'var(--studio-text)' }}><SelectValue /></SelectTrigger>
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
                <p style={{ fontSize: '12px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '24px' }}>Aucun champ. Clique "+ Champ" pour en ajouter un.</p>
              )}
            </div>
          </>) : (
            <div className="sb-empty">
              <Wand2 style={{ width: '40px', height: '40px' }} />
              <div>
                <h3>{collections.length === 0 ? 'Créez votre première collection' : 'Sélectionnez une collection'}</h3>
                <p>{collections.length === 0 ? 'Les collections définissent votre modèle de données. Utilisez le chat IA à droite pour les générer automatiquement.' : 'Choisissez une collection dans la liste de gauche.'}</p>
              </div>
              <div style={{ display: 'flex', gap: '8px' }}>
                {collections.length === 0 && (
                  <Button onClick={addCollection} style={{ background: 'var(--studio-accent)', color: '#000' }}>
                    <Plus className="w-4 h-4 mr-2" />{t('studio.schema.new_collection')}
                  </Button>
                )}
              </div>
            </div>
          )}
        </div>

        {/* RIGHT: Chat panel (desktop) */}
        <div className="sb-chat-panel">
          <SchemaChatPanel
            project={project}
            currentCollections={collections}
            onApplySchema={handleApplySchema}
          />
        </div>
      </div>

      {/* ── Chat Sheet (mobile/tablet) ── */}
      <Sheet open={chatOpen} onOpenChange={setChatOpen}>
        <SheetContent side="bottom" style={{ height: '85vh', background: 'var(--studio-bg)', borderColor: 'var(--studio-border)', borderTopLeftRadius: '16px', borderTopRightRadius: '16px' }}>
          <SchemaChatPanel
            project={project}
            currentCollections={collections}
            onApplySchema={cols => { handleApplySchema(cols); }}
            onClose={() => setChatOpen(false)}
          />
        </SheetContent>
      </Sheet>
    </div>
  );
}
