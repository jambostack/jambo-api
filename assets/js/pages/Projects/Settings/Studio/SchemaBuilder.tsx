import React, { useState, useEffect, useRef, useCallback } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter } from '@/components/ui/dialog';
import {
  Plus, Trash2, Eye, Save, Copy, Wand2, Loader2, ChevronRight, ChevronLeft,
  Type, AlignLeft, Hash, List, ToggleLeft, Calendar, Clock,
  AtSign, Link, Lock, Palette, Image, GitBranch, Code2, FileText, X, Layers,
  MessageSquare, Send, Bot, User, Check,
  PanelLeftClose, PanelLeftOpen, PanelRightClose, PanelRightOpen,
  FolderOpen, Pencil, Sparkles,
  Users, Lock, UserPlus, Shield, GripVertical, ChevronDown, Settings2,
  Database, Table2, Slash
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
  key: string; uuid?: string; name: string; slug: string; description: string;
  isSingleton: boolean; fields: SchemaField[];
}
interface ServerCollection {
  id: number; uuid: string; name: string; slug: string;
  description?: string; isSingleton: boolean;
  fields: Array<{ name: string; slug: string; type: string; isRequired: boolean; options?: any; order?: number }>;
}
interface ChatMessage { role: 'user' | 'assistant' | 'system'; content: string; schema?: Array<{ name: string; slug: string; description: string; isSingleton: boolean; fields: Array<{ name: string; slug: string; type: string; isRequired: boolean }> }>; entries?: Array<{ collection: string; entries: Array<Record<string, any>> }>; }
interface EndUserFieldData { id: number; name: string; slug: string; type: string; required: boolean; order: number; is_system: boolean; }
type StudioCommand = 'schema' | 'data' | 'all' | null;

function slugify(s: string) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''); }

type MobileTab = 'collections' | 'editor' | 'chat';

const SCHEMA_CHAT_QUICK_PILL_KEYS = [
  'studio.chat.prompt_blog',
  'studio.chat.prompt_enduser_fields',
  'studio.chat.prompt_catalog',
  'studio.chat.prompt_elearning',
  'studio.chat.prompt_articles',
];

/* ══════════════════════════ SCHEMA CHAT PANEL ══════════════════════════ */
function SchemaChatPanel({
  project, currentCollections, onApplySchema, onApplyEndUserFields, endUserFields,
}: {
  project: Project;
  currentCollections: SchemaCollection[];
  onApplySchema: (newCollections: SchemaCollection[]) => void;
  onApplyEndUserFields: (fields: SchemaField[]) => void;
  endUserFields: EndUserFieldData[];
}) {
  const t = useTranslation();
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [activeCommand, setActiveCommand] = useState<StudioCommand>(null);
  const [capabilities, setCapabilities] = useState<{ text: boolean; images: boolean; voice: boolean; provider: string | null; model: string; limits: string[] } | null>(null);
  const chatEndRef = useRef<HTMLDivElement>(null);
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const scrollDown = () => setTimeout(() => chatEndRef.current?.scrollIntoView({ behavior: 'smooth' }), 60);

  // Charger les capacités IA
  useEffect(() => {
    fetch(`/api/projects/${project.uuid}/studio/ai-capabilities`)
      .then(r => r.json()).then(d => setCapabilities(d)).catch(() => {});
  }, [project.uuid]);

  // Charger l'historique depuis la DB au montage
  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const res = await fetch(`/api/projects/${project.uuid}/studio/chat-messages`);
        const data = await res.json() as { data?: Array<{ role: string; content: string; schema: any; entries: any }> };
        if (cancelled) return;
        const loaded = (data.data ?? []).map(m => ({ role: m.role as ChatMessage['role'], content: m.content, schema: m.schema ?? undefined, entries: m.entries ?? undefined }));
        if (loaded.length === 0) {
          loaded.push({ role: 'assistant' as const, content: t('studio.chat.welcome_v2'), schema: undefined });
        }
        setMessages(loaded);
      } catch {}
    })();
    return () => { cancelled = true; };
  }, [project.uuid]);

  // Effacer l'historique côté serveur
  async function clearHistory() {
    try { await fetch(`/api/projects/${project.uuid}/studio/chat-messages`, { method: 'DELETE' }); } catch {}
    setMessages([{ role: 'assistant', content: t('studio.chat.welcome_v2'), schema: undefined }]);
    setActiveCommand(null);
  }

  function parseCommand(input: string): { command: StudioCommand; prompt: string } {
    const m = input.trim().match(/^\/(schema|data|all)\s+(.+)$/i);
    if (m) return { command: m[1].toLowerCase() as Exclude<StudioCommand, null>, prompt: m[2].trim() };
    return { command: null, prompt: input.trim() };
  }

  function insertCommand(cmd: 'schema' | 'data' | 'all') {
    const prefix = '/' + cmd + ' ';
    setInput(prefix);
    setActiveCommand(cmd);
    textareaRef.current?.focus();
  }

  // Surveiller l'input pour détecter/supprimer la commande active
  useEffect(() => {
    const { command } = parseCommand(input);
    if (command !== activeCommand) setActiveCommand(command);
  }, [input]);

  function buildContext(): string {
    const baseUrl = '/api/' + project.uuid;
    const graphqlUrl = '/api/projects/' + project.uuid + '/graphql';
    const parts: string[] = [];

    parts.push('## API REST');
    parts.push('- Base URL: ' + baseUrl);
    parts.push('- Auth endpoints: POST /auth/login, POST /auth/register, POST /auth/refresh (JWT)');
    parts.push('- Files endpoint: GET/POST ' + baseUrl + '/files, GET ' + baseUrl + '/files/{uuid}');
    parts.push('- OpenAPI spec: GET ' + baseUrl + '/openapi.json');
    parts.push('- Authentification: Bearer token (API token) ou JWT end-user');
    parts.push('');

    parts.push('## GraphQL');
    parts.push('- Endpoint: POST/GET ' + graphqlUrl);
    parts.push('- Schema auto-généré depuis les collections du projet');
    parts.push('- Supporte queries, mutations, filtres, pagination, tri');
    parts.push('');

    parts.push('## EndUser (collection système - NE PAS recréer)');
    parts.push('- Slug: end_users, toujours disponible pour la gestion utilisateurs');
    parts.push('- Champs système: email (email*), name (text), status (active/banned/pending), avatar_url (text), custom_fields (json)');
    const customEUF = endUserFields.filter(f => !f.is_system);
    if (customEUF.length > 0) {
      parts.push('- Champs personnalisés EndUser ACTUELS (à inclure dans les entrées):');
      customEUF.forEach(f => parts.push('  ' + f.name + ' (' + f.slug + ') [' + f.type + ']' + (f.required ? '*' : '')));
    }
    parts.push('- Utiliser relation vers end_users pour auteurs, propriétaires, membres, clients');
    parts.push('- Ne JAMAIS créer de nouvelle collection pour les utilisateurs/personnes');
    parts.push('- Pour /data sur end_users: inclure TOUS les champs personnalisés listés ci-dessus dans chaque entrée');
    parts.push('');

    parts.push('## Collections existantes');
    if (currentCollections.length === 0) {
      parts.push('(aucune collection existante)');
    } else {
      currentCollections.forEach(c => {
        const fields = c.fields.map(f => f.name + '[' + f.type + ']' + (f.isRequired ? '*' : '')).join(', ') || 'aucun champ';
        parts.push('- ' + c.name + ' (slug: ' + c.slug + ', ' + (c.isSingleton ? 'singleton' : 'collection') + ')');
        parts.push('  Fields: ' + fields);
        parts.push('  REST: GET ' + baseUrl + '/' + c.slug + ' (list), GET/PATCH/DELETE ' + baseUrl + '/' + c.slug + '/{uuid}, POST ' + baseUrl + '/' + c.slug + ' (create)');
      });
    }

    return parts.join('\n');
  }

  async function send() {
    const { command, prompt } = parseCommand(input);
    if (!prompt || busy) return;
    setInput(''); setBusy(true);
    setMessages(prev => [...prev, { role: 'user', content: input.trim(), schema: undefined }]);
    scrollDown();
    try {
      const res = await fetch(`/api/projects/${project.uuid}/studio/ai-chat`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ command, prompt, context: buildContext(), history: messages.slice(-20).map(m => ({ role: m.role, content: m.content })) }),
      });
      const data = await res.json() as { reply?: string; collections?: any[]; entries?: any[]; error?: string };
      if (!res.ok || data.error) throw new Error(data.error);
      setMessages(prev => [...prev, {
        role: 'assistant',
        content: data.reply ?? t('studio.chat.default_reply'),
        schema: data.collections ?? undefined,
        entries: data.entries ?? undefined,
      }]);
    } catch {
      setMessages(prev => [...prev, { role: 'assistant', content: t('studio.chat.error'), schema: undefined }]);
    } finally { setBusy(false); scrollDown(); }
  }

  function handleApplySchema(schema: NonNullable<ChatMessage['schema']>) {
    const newCollections: SchemaCollection[] = [];
    const existingEndUserFields: SchemaField[] = [];

    for (const c of schema) {
      if (c.slug === 'end_users') {
        // EndUsers est une collection système : extraire les champs personnalisés
        existingEndUserFields.push(...(c.fields ?? []).filter(f => !['email','name','status','avatar_url','custom_fields'].includes(f.slug)));
      } else {
        newCollections.push({
          key: `col_${Date.now()}_${Math.random().toString(36).slice(2,6)}`,
          name: c.name, slug: c.slug, description: c.description ?? '', isSingleton: c.isSingleton ?? false,
          fields: (c.fields ?? []).map(f => ({
            key: `fld_${Date.now()}_${Math.random().toString(36).slice(2,6)}`,
            name: f.name, slug: f.slug, type: f.type, isRequired: f.isRequired ?? false,
              options: (f as any).options ?? undefined,
          })),
        });
      }
    }

    if (newCollections.length > 0) {
      onApplySchema(newCollections);
    }
    if (existingEndUserFields.length > 0) {
      onApplyEndUserFields(existingEndUserFields);
    }

    const parts = [];
    if (newCollections.length > 0) parts.push(t('studio.chat.applied', { count: newCollections.length }));
    if (existingEndUserFields.length > 0) parts.push('👤 ' + t('studio.chat.enduser_fields_added', { count: existingEndUserFields.length }));
    setMessages(prev => [...prev, { role: 'system', content: parts.join(' — ') || t('common.ok'), schema: undefined }]);
    scrollDown();
  }

  async function handleApplyEntries(entries: NonNullable<ChatMessage['entries']>) {
    if (!Array.isArray(entries)) return;
    setBusy(true);
    let created = 0;
    let errors = 0;
    for (const eg of entries) {
      const egEntries = Array.isArray(eg?.entries) ? eg.entries : [];
      if (!eg?.collection || egEntries.length === 0) continue;
      for (const entry of egEntries) {
        try {
          const body = { ...entry, status: 'published' };
          // Supprimer les champs système que l'IA pourrait inclure
          delete (body as any).uuid; delete (body as any).id;
          delete (body as any).created_at; delete (body as any).updated_at;

          const res = await fetch(`/api/projects/${project.uuid}/studio/apply-entries`, {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ collection: eg.collection, entry: body }),
          });
          if (res.ok) created++; else errors++;
        } catch { errors++; }
      }
    }
    setBusy(false);
    setMessages(prev => [...prev, { role: 'system', content: t('studio.chat.entries_applied', { created: String(created), errors: String(errors) }), schema: undefined }]);
    scrollDown();
  }

  // Auto-resize textarea
  const handleTextareaChange = (value: string) => {
    setInput(value);
    const el = textareaRef.current;
    if (el) {
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    }
  };

  // Rendu du placeholder contextuel
  const placeholder = activeCommand === 'schema' ? t('studio.chat.placeholder_schema')
    : activeCommand === 'data' ? t('studio.chat.placeholder_data')
    : activeCommand === 'all' ? t('studio.chat.placeholder_all')
    : t('studio.chat.placeholder_default');

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
        .scp-data-card { margin-top:6px; padding:8px; border-radius:6px; background:var(--studio-surface,#111714); border:1px solid rgba(97,175,239,.15); font-size:10.5px; line-height:1.5; }
        .scp-data-card h5 { margin:0 0 4px; font-size:11px; color:#61afef; }
        .scp-data-entry { padding:4px 6px; margin:2px 0; border-radius:4px; background:rgba(255,255,255,.02); }
        .scp-data-entry + .scp-data-entry { border-top:1px solid rgba(255,255,255,.03); }
        .scp-command-bar { display:flex; gap:4px; padding:6px 8px; flex-shrink:0; border-top:1px solid var(--studio-border); align-items:center; }
        .scp-cmd-btn { display:flex; align-items:center; gap:4px; padding:3px 8px; border-radius:6px; font-size:10.5px; font-weight:600; cursor:pointer; border:1px solid var(--studio-border); background:var(--studio-surface); color:var(--studio-text-muted); transition:all .12s; white-space:nowrap; }
        .scp-cmd-btn:hover { border-color:var(--studio-border-active); color:var(--studio-text-dim); }
        .scp-cmd-btn.active { border-color:var(--studio-accent); color:var(--studio-accent); background:rgba(47,207,143,.06); }
        .scp-cmd-btn svg { width:12px; height:12px; }
        .scp-clear-btn { margin-left:auto; display:flex; align-items:center; gap:3px; padding:3px 8px; border-radius:6px; font-size:10.5px; cursor:pointer; border:1px solid transparent; background:transparent; color:var(--studio-text-muted); transition:all .12s; }
        .scp-clear-btn:hover { color:#e06c75; border-color:rgba(224,108,117,.2); }
        .scp-input-row { display:flex; gap:6px; padding:8px 10px; border-top:1px solid var(--studio-border); flex-shrink:0; align-items:flex-end; }
        .scp-input-row textarea { flex:1; min-height:40px; max-height:120px; border-radius:6px; padding:6px 8px; font-size:11.5px; font-family:inherit; background:var(--studio-bg); border:1px solid var(--studio-border); color:var(--studio-text); outline:none; resize:none; line-height:1.4; }
        .scp-input-row textarea:focus { border-color:var(--studio-border-active); }
        .scp-input-row button { height:32px; min-width:32px; border-radius:6px; display:flex; align-items:center; justify-content:center; cursor:pointer; border:none; background:var(--studio-accent); color:#000; flex-shrink:0; }
        .scp-input-row button:disabled { opacity:.4; cursor:not-allowed; }
        .scp-quick-prompts { display:flex; gap:4px; padding:6px 8px; overflow-x:auto; flex-shrink:0; scrollbar-width:none; border-top:1px solid var(--studio-border); }
        .scp-quick-prompts::-webkit-scrollbar { display:none; }
        .scp-quick-pill { flex-shrink:0; padding:3px 8px; border-radius:999px; font-size:9.5px; cursor:pointer; border:1px solid var(--studio-border); background:var(--studio-surface); color:var(--studio-text-muted); transition:all .12s; white-space:nowrap; }
        .scp-quick-pill:hover { border-color:var(--studio-border-active); color:var(--studio-text-dim); }
        .scp-quick-pill:disabled { opacity:.3; cursor:not-allowed; }
      `}</style>

      {/* Capabilities badge */}
      {capabilities && (
        <div className="scp-capabilities" style={{ display:'flex', gap:'8px', padding:'5px 10px', fontSize:'10px', fontFamily:'var(--studio-mono)', color:'var(--studio-text-muted)', borderBottom:'1px solid var(--studio-border)', flexShrink:0 }}>
          <span style={{ color: capabilities.text ? 'var(--studio-accent)' : '#e06c75' }}>{capabilities.text ? '🟢 Texte' : '🔴 Texte'}{capabilities.provider ? ` (${capabilities.model})` : ''}</span>
          <span style={{ color: capabilities.images ? 'var(--studio-accent)' : '#e06c75' }}>{capabilities.images ? '🟢 Images' : '🔴 Images'}</span>
          {capabilities.limits.includes('qualite_limitee') && <span style={{ color:'var(--studio-amber)' }}>⚠️ Qualité limitée</span>}
        </div>
      )}

      {/* Messages */}
      <div className="scp-messages">
        {messages.map((m, i) => (
          <div key={i} className={`scp-msg ${m.role}`}>
            <div className="avatar">{m.role === 'assistant' ? <Bot className="w-3 h-3" /> : m.role === 'system' ? <Check className="w-3 h-3" /> : <User className="w-3 h-3" />}</div>
            <div className="bubble">
              <div style={{ whiteSpace: 'pre-wrap' }}>{m.content}</div>

              {/* Schema card */}
              {m.schema && m.schema.length > 0 && (
                <div className="scp-schema-card">
                  <h5>{m.schema.length} collection{m.schema.length>1?'s':''} générée{m.schema.length>1?'s':''}</h5>
                  {m.schema.map((col, ci) => (
                    <div key={ci} style={{ marginBottom: ci < m.schema!.length-1 ? 6 : 0 }}>
                      <div style={{ fontWeight:600, color:'var(--studio-text)', fontSize:'11px' }}>{col.name} <span style={{ fontFamily:'var(--studio-mono)', fontSize:'9px', color:'var(--studio-text-muted)' }}>{col.slug}</span></div>
                      <ul>{col.fields.map((f,fi)=>(<li key={fi}>{f.isRequired&&'· '}<b>{f.name}</b> <span style={{ fontFamily:'var(--studio-mono)',fontSize:'9px' }}>[{f.type}]</span>{f.type === 'relation' && <span style={{ color:'#61afef', fontSize:'8px', marginLeft:'2px' }}>→</span>}</li>))}</ul>
                    </div>
                  ))}
                  <button className="scp-apply-btn" onClick={() => handleApplySchema(m.schema!)}><Check className="w-3 h-3" />{t('studio.chat.apply_schema')}</button>
                </div>
              )}

              {/* Data card (entries) */}
              {m.entries && m.entries.length > 0 && m.entries.map((entryGroup, gi) => {
                const egEntries = Array.isArray(entryGroup?.entries) ? entryGroup.entries : (entryGroup?.entry ? [entryGroup.entry] : []);
                if (!entryGroup?.collection && egEntries.length === 0) return null;
                return (
                <div key={gi} className="scp-data-card">
                  <h5>📊 {entryGroup.collection || t('studio.chat.data_entries')} — {egEntries.length} {egEntries.length > 1 ? t('studio.chat.entries') : t('studio.chat.entry_single')}</h5>
                  {egEntries.slice(0, 3).map((entry, ei) => (
                    <div key={ei} className="scp-data-entry">
                      <div style={{ fontWeight:600, color:'var(--studio-text)', fontSize:'10.5px' }}>{ei + 1}. {entry?.title || entry?.name || (t('studio.chat.entry_label') + ' ' + (ei+1))}</div>
                      <div style={{ color:'var(--studio-text-muted)', fontSize:'10px' }}>
                        {entry && Object.entries(entry).filter(([k]) => !['title','name','slug'].includes(k)).slice(0, 3).map(([k,v]) => (
                          <span key={k} style={{ marginRight:'8px' }}>{k}: <span style={{ color:'var(--studio-text-dim)' }}>{typeof v === 'string' ? v.slice(0, 40) + (v.length>40?'…':'') : String(v)}</span></span>
                        ))}
                        {entry && Object.keys(entry).length > 6 && <span style={{ color:'var(--studio-text-muted)' }}>…</span>}
                      </div>
                    </div>
                  ))}
                  <button className="scp-apply-btn" style={{ background:'#61afef' }} onClick={() => handleApplyEntries(m.entries!)}><Check className="w-3 h-3" />{t('studio.chat.entries_ok')}</button>
                </div>
                );
              })}
            </div>
          </div>
        ))}
        {busy && <div className="scp-msg assistant"><div className="avatar"><Bot className="w-3 h-3" /></div><div className="bubble"><Loader2 className="w-3 h-3 animate-spin" style={{ display:'inline', color:'var(--studio-accent)', marginRight:'6px', verticalAlign:'middle' }} />{t('studio.chat.thinking')}</div></div>}
        <div ref={chatEndRef} />
      </div>

      {/* CommandBar — just above the textarea */}
      <div className="scp-command-bar">
        <button className={`scp-cmd-btn ${activeCommand === 'schema' ? 'active' : ''}`} onClick={() => insertCommand('schema')} title="/schema — Générer uniquement le schéma de collections"><Slash className="w-3 h-3" />schema</button>
        <button className={`scp-cmd-btn ${activeCommand === 'data' ? 'active' : ''}`} onClick={() => insertCommand('data')} title="/data — Générer du contenu professionnel"><Database className="w-3 h-3" />data</button>
        <button className={`scp-cmd-btn ${activeCommand === 'all' ? 'active' : ''}`} onClick={() => insertCommand('all')} title="/all — Collections + contenu"><Table2 className="w-3 h-3" />all</button>
        <button className="scp-clear-btn" onClick={clearHistory} disabled={busy} title={t('studio.chat.clear_title')}><Trash2 className="w-3 h-3" />{t('studio.chat.clear')}</button>
      </div>

      {/* Input area */}
      <div className="scp-input-row">
        <textarea
          ref={textareaRef}
          placeholder={placeholder}
          value={input}
          onChange={e => handleTextareaChange(e.target.value)}
          onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(); } }}
          disabled={busy}
          rows={2}
          maxLength={2000}
        />
        <button onClick={send} disabled={busy || !input.trim()}>{busy ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Send className="w-3.5 h-3.5" />}</button>
      </div>

      {/* Quick prompts */}
      <div className="scp-quick-prompts">
        {SCHEMA_CHAT_QUICK_PILL_KEYS.map((key,i) => { const qp = t(key); return (<button key={i} className="scp-quick-pill" onClick={() => { setInput(qp); setActiveCommand(parseCommand(qp).command); textareaRef.current?.focus(); }} disabled={busy}>{qp}</button>); })}
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
            {current.fields.map((f, i) => {
              const relTarget = f.type === 'relation' && (f.options as any)?.targetCollection;
              const enumVals = f.type === 'enumeration' && (f.options as any)?.values?.length;
              return (
                <div key={f.key} style={{ marginLeft: '8px' }}>
                  {i + 1}. {f.name || '?'} <span style={{ color: 'var(--studio-accent)' }}>[{f.type}]</span>
                  {f.isRequired && <span style={{ color: 'var(--studio-amber)', fontSize: '9px' }}> • requis</span>}
                  {relTarget && <span style={{ color: '#61afef', fontSize: '9px', marginLeft: '2px' }}> → {relTarget as string}</span>}
                  {enumVals && <span style={{ color: 'var(--studio-text-muted)', fontSize: '9px', marginLeft: '2px' }}> [{((f.options as any)?.values as string[]).join(', ')}]</span>}
                </div>
              );
            })}
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
  project, currentCollections, onApplySchema, onApplyEndUserFields, endUserFields, current, onGeneratePreview, preview,
}: {
  project: Project; currentCollections: SchemaCollection[]; onApplySchema: (c: SchemaCollection[]) => void;
  onApplyEndUserFields: (fields: SchemaField[]) => void;
  endUserFields: EndUserFieldData[];
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
      <div style={{ display: tab === 'chat' ? 'flex' : 'none', flex: 1, minHeight: 0, flexDirection: 'column' }}>
        <SchemaChatPanel project={project} currentCollections={currentCollections} onApplySchema={onApplySchema} onApplyEndUserFields={onApplyEndUserFields} endUserFields={endUserFields} />
      </div>
      <div style={{ display: tab === 'preview' ? 'flex' : 'none', flex: 1, minHeight: 0, flexDirection: 'column' }}>
        <SchemaPreviewPanel current={current} preview={preview} />
      </div>
    </div>
  );
}

/* ══════════════════════════ END USER EDITOR ══════════════════════════ */
function EndUserEditor({
  fields, loading, onAdd, onUpdate, onDelete, newField, setNewField,
}: {
  fields: EndUserFieldData[]; loading: boolean;
  onAdd: () => void; onUpdate: (slug: string, data: Record<string, any>) => void;
  onDelete: (slug: string) => void;
  newField: { name: string; type: string; isRequired: boolean };
  setNewField: (f: { name: string; type: string; isRequired: boolean }) => void;
}) {
  const systemFields = fields.filter(f => f.is_system);
  const customFields = fields.filter(f => !f.is_system);

  if (loading) return <div style={{ display:'flex', justifyContent:'center', padding:'40px' }}><Loader2 className="w-5 h-5 animate-spin" style={{ color:'var(--studio-accent)' }} /></div>;

  return (
    <div className="eue-root">
      <style>{`
        .eue-root { display:flex; flex-direction:column; gap:16px; overflow-y:auto; padding-bottom:16px; }
        .eue-section-title { font-size:9px; font-family:var(--studio-mono); text-transform:uppercase; letter-spacing:.08em; color:var(--studio-text-muted); margin:0 0 6px; display:flex; align-items:center; gap:6px; }
        .eue-section-title svg { width:12px; height:12px; }
        .eue-field-row { display:flex; align-items:center; gap:8px; padding:8px 10px; border:1px solid var(--studio-border); border-radius:7px; background:var(--studio-raised); margin-bottom:4px; }
        .eue-field-row.locked { opacity:.6; background:var(--studio-surface); }
        .eue-field-icon { width:28px; height:28px; border-radius:6px; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:11px; }
        .eue-field-name { flex:1; font-size:12px; font-weight:600; color:var(--studio-text); }
        .eue-field-type { font-family:var(--studio-mono); font-size:10px; color:var(--studio-text-muted); }
        .eue-field-badge { font-size:9px; padding:2px 6px; border-radius:999px; font-weight:600; }
        .eue-add-row { display:flex; gap:6px; align-items:center; margin-top:8px; }
        .eue-add-row input, .eue-add-row select { height:30px; font-size:11px; border-radius:6px; padding:0 8px; background:var(--studio-bg); border:1px solid var(--studio-border); color:var(--studio-text); outline:none; }
        .eue-add-row input:focus, .eue-add-row select:focus { border-color:var(--studio-border-active); }
        .eue-add-row input { flex:1; min-width:0; }
        .eue-add-row select { width:110px; flex-shrink:0; }
      `}</style>

      {/* System fields */}
      <div>
        <h4 className="eue-section-title"><Shield className="w-3 h-3" style={{color:'var(--studio-amber)'}} />Champs système (protégés)</h4>
        {systemFields.map(f => (
          <div key={f.slug} className="eue-field-row locked">
            <div className="eue-field-icon" style={{ background:'rgba(247,185,85,.10)', color:'var(--studio-amber)' }}><Lock className="w-3 h-3" /></div>
            <span className="eue-field-name">{f.name}</span>
            <span className="eue-field-type">[{f.type}]</span>
            <span className="eue-field-badge" style={{ background:'var(--studio-border)', color:'var(--studio-text-muted)' }}>système</span>
          </div>
        ))}
      </div>

      {/* Custom fields */}
      <div>
        <h4 className="eue-section-title"><FolderOpen className="w-3 h-3" />Champs personnalisés · {customFields.length}</h4>
        {customFields.map(f => (
          <div key={f.slug} className="eue-field-row" style={{ flexWrap:'wrap', gap:'6px', alignItems:'center' }}>
            <div className="eue-field-icon" style={{ background:'rgba(47,207,143,.10)', color:'var(--studio-accent)' }}><Pencil className="w-3 h-3" /></div>
            <input
              value={f.name}
              onChange={e => onUpdate(f.slug, { name: e.target.value })}
              style={{ flex:1, minWidth:'100px', height:'28px', fontSize:'11px', background:'var(--studio-bg)', border:'1px solid var(--studio-border)', borderRadius:'5px', color:'var(--studio-text)', padding:'0 6px', outline:'none' }}
              placeholder="Nom"
            />
            <select
              value={f.type}
              onChange={e => onUpdate(f.slug, { type: e.target.value })}
              style={{ width:'100px', height:'28px', fontSize:'10px', background:'var(--studio-bg)', border:'1px solid var(--studio-border)', borderRadius:'5px', color:'var(--studio-text)', outline:'none', flexShrink:0 }}
            >
              {FIELD_TYPES.map(ft => (<option key={ft.type} value={ft.type}>{ft.label}</option>))}
            </select>
            <div style={{ display:'flex', alignItems:'center', gap:'4px', flexShrink:0 }}>
              <Switch checked={f.required} onCheckedChange={v => onUpdate(f.slug, { is_required: v })} />
              <span style={{ fontSize:'10px', color:'var(--studio-text-muted)' }}>Requis</span>
            </div>
            <button onClick={() => onDelete(f.slug)} style={{ background:'none',border:'none',cursor:'pointer',padding:'4px',opacity:.5,flexShrink:0 }}>
              <Trash2 className="w-3.5 h-3.5" style={{color:'var(--studio-red)'}} />
            </button>
          </div>
        ))}
        {customFields.length === 0 && <p style={{ fontSize:'11px', color:'var(--studio-text-muted)', padding:'8px 0' }}>Aucun champ personnalisé.</p>}

        {/* Add field */}
        <div className="eue-add-row">
          <input placeholder="Nom du champ" value={newField.name} onChange={e => setNewField({...newField, name:e.target.value})} onKeyDown={e => e.key==='Enter' && onAdd()} />
          <select value={newField.type} onChange={e => setNewField({...newField, type:e.target.value})}>
            {FIELD_TYPES.map(ft => (<option key={ft.type} value={ft.type}>{ft.label}</option>))}
          </select>
          <div style={{ display:'flex', alignItems:'center', gap:'4px' }}>
            <Switch checked={newField.isRequired} onCheckedChange={v => setNewField({...newField, isRequired:v})} />
            <span style={{ fontSize:'10px', color:'var(--studio-text-muted)' }}>Requis</span>
          </div>
          <Button size="sm" onClick={onAdd} disabled={!newField.name.trim()} style={{ height:'30px', fontSize:'10px', background:'var(--studio-accent)', color:'#000', whiteSpace:'nowrap' }}><Plus className="w-3 h-3 mr-1" />Ajouter</Button>
        </div>
      </div>
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
  const [confirmDeleteIdx, setConfirmDeleteIdx] = useState<number | null>(null);

  // ── EndUser fields ──
  const [endUserFields, setEndUserFields] = useState<EndUserFieldData[]>([]);
  const [endUserLoading, setEndUserLoading] = useState(false);
  const [endUserNew, setEndUserNew] = useState({ name: '', type: 'text', isRequired: false });

  const collectionListRef = useRef<HTMLDivElement>(null);
  const current = selectedIdx !== null && selectedIdx >= 0 ? collections[selectedIdx] : null;
  const isEndUsers = selectedIdx === -1;
  const untitled = t('studio.schema.untitled');
  const sidebarW = sidebarOpen ? 170 : 0;
  const rightW = rightPanelOpen ? 280 : 0;

  /* ── Load ── */
  const loadCollections = useCallback(async () => {
    setLoading(true);
    try {
      const res = await fetch(`/api/projects/${project.uuid}/studio/collections`);
      if (!res.ok) throw new Error('Failed');
      const data = await res.json() as { data: ServerCollection[] };
      const loaded = (data.data ?? []).map((c): SchemaCollection => ({
        key: `col_${c.id}`, uuid: c.uuid, name: c.name, slug: c.slug, description: c.description ?? '', isSingleton: c.isSingleton,
        fields: (c.fields ?? []).map((f, fi): SchemaField => ({ key: `fld_${c.id}_${fi}`, name: f.name, slug: f.slug, type: f.type, isRequired: f.isRequired, options: f.options })),
      }));
      setCollections(loaded);
      // Conserve la sélection courante ; ne sélectionne la 1ère qu'au tout premier chargement.
      setSelectedIdx(prev => prev ?? (loaded.length > 0 ? 0 : null));
    } catch {} finally { setLoading(false); }
  }, [project.uuid]);

  useEffect(() => { loadCollections(); }, [loadCollections]);

  /* ── Load EndUser fields ── */
  const loadEndUserFields = useCallback(async () => {
    setEndUserLoading(true);
    try {
      const res = await fetch(`/api/projects/${project.uuid}/end-users/fields`);
      if (!res.ok) throw new Error('Failed');
      const d = await res.json() as { data: EndUserFieldData[] };
      setEndUserFields(d.data ?? []);
    } catch {} finally { setEndUserLoading(false); }
  }, [project.uuid]);

  useEffect(() => { loadEndUserFields(); }, [loadEndUserFields]);

  /* ── CRUD ── */
  const addCollection = useCallback(() => {
    setCollections(prev => [...prev, { key: `col_${Date.now()}`, name: '', slug: '', description: '', isSingleton: false, fields: [] }]);
    setSelectedIdx(collections.length);
    setTimeout(() => collectionListRef.current?.lastElementChild?.scrollIntoView({ behavior: 'smooth' }), 50);
  }, [collections.length]);
  function updateCollection(idx: number, d: Partial<SchemaCollection>) { setCollections(prev => prev.map((c, i) => i === idx ? { ...c, ...d, slug: d.name !== undefined ? slugify(d.name) : c.slug } : c)); }
  function removeCollection(idx: number) { setConfirmDeleteIdx(idx); }
  function confirmRemoveCollection() {
    if (confirmDeleteIdx === null) return;
    const idx = confirmDeleteIdx;
    setCollections(prev => prev.filter((_, i) => i !== idx));
    if (selectedIdx === idx) setSelectedIdx(null);
    else if (selectedIdx !== null && selectedIdx > idx) setSelectedIdx(selectedIdx - 1);
    setConfirmDeleteIdx(null);
  }
  function addField() { if (selectedIdx === null) return; setCollections(prev => prev.map((c, i) => i === selectedIdx ? { ...c, fields: [...c.fields, { key: `fld_${Date.now()}`, name: '', slug: '', type: 'text', isRequired: false }] } : c)); }
  function updateField(colIdx: number, fKey: string, d: Partial<SchemaField>) { setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: c.fields.map(f => f.key === fKey ? { ...f, ...d, slug: d.name !== undefined ? slugify(d.name) : f.slug } : f) } : c)); }
  function removeField(colIdx: number, fKey: string) { setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: c.fields.filter(f => f.key !== fKey) } : c)); }
  function duplicateField(colIdx: number, fKey: string) { const col = collections[colIdx]; const f = col?.fields.find(x => x.key === fKey); if (!f) return; setCollections(prev => prev.map((c, i) => i === colIdx ? { ...c, fields: [...c.fields, { ...f, key: `fld_${Date.now()}`, name: `${f.name} (copy)`, slug: `${f.slug}_copy` }] } : c)); }
  function handleApplySchema(newCols: SchemaCollection[]) {
    // Dedup: si le slug existe déjà, on ignore la nouvelle collection
    const existingSlugs = new Set(collections.map(c => c.slug));
    const trulyNew = newCols.filter(c => !existingSlugs.has(c.slug));
    if (trulyNew.length === 0) return;
    setCollections(prev => [...prev, ...trulyNew]);
    if (selectedIdx === null && trulyNew.length > 0) setSelectedIdx(collections.length);
  }
  /** Crée les champs personnalisés EndUser proposés par l'IA */
  async function handleApplyEndUserFields(fields: SchemaField[]) {
    for (const f of fields) {
      try {
        await fetch(`/api/projects/${project.uuid}/end-users/fields`, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ name: f.name, slug: f.slug, type: f.type, is_required: f.isRequired }),
        });
      } catch {}
    }
    await loadEndUserFields();
  }
  /* ── EndUser field CRUD ── */
  async function addEndUserField() {
    if (!endUserNew.name.trim()) return;
    try {
      const res = await fetch(`/api/projects/${project.uuid}/end-users/fields`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: endUserNew.name, type: endUserNew.type, is_required: endUserNew.isRequired }),
      });
      if (res.ok) { await loadEndUserFields(); setEndUserNew({ name: '', type: 'text', isRequired: false }); }
    } catch {}
  }
  async function updateEndUserField(slug: string, data: Record<string, any>) {
    try {
      await fetch(`/api/projects/${project.uuid}/end-users/fields/${slug}`, {
        method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data),
      });
      await loadEndUserFields();
    } catch {}
  }
  async function deleteEndUserField(slug: string) {
    try {
      await fetch(`/api/projects/${project.uuid}/end-users/fields/${slug}`, { method: 'DELETE' });
      await loadEndUserFields();
    } catch {}
  }

  async function handleSave() {
    setSaving(true);
    try {
      const res = await fetch(`/api/projects/${project.uuid}/studio/schema`, {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ collections }),
      });
      if (!res.ok) throw new Error('Failed');
      toast.success('Schéma enregistré.');
      // Recharge depuis le serveur pour refléter l'état réellement persisté
      // (slugs normalisés, ids, etc.).
      await loadCollections();
    } catch {
      toast.error("Échec de l'enregistrement du schéma.");
    } finally { setSaving(false); }
  }
  function generatePreview() {
    if (isEndUsers) {
      const lines = [`▸ Utilisateurs`, `  champs personnalisés : ${endUserFields.filter(f => !f.is_system).length}`, `  champs système : ${endUserFields.filter(f => f.is_system).length}`];
      endUserFields.forEach((f, i) => { lines.push(`  ${i + 1}. ${f.name} [${f.type}] ${f.required ? '• requis' : '• optionnel'} ${f.is_system ? '🔒' : ''}`); });
      setPreview(lines.join('\n'));
      return;
    }
    if (!current) return;
    const fieldLines = current.fields.map((f, i) => {
      let extra = '';
      if (f.type === 'relation' && f.options?.targetCollection) {
        extra = ' → ' + f.options.targetCollection;
      } else if (f.type === 'enumeration' && f.options?.values?.length) {
        extra = ' [' + f.options.values.join(', ') + ']';
      }
      return `  ${i + 1}. ${f.name || '?'} [${f.type}]${f.isRequired ? ' • requis' : ''}${extra}`;
    });
    setPreview([`▸ ${current.name || untitled}`, `  slug: ${current.slug || '(auto)'}  ·  ${current.isSingleton ? 'singleton' : 'collection'}`, `  ${current.fields.length} champs:`, ...fieldLines].join('\n'));
  }
  function selectNext() { if (selectedIdx !== null && selectedIdx < collections.length - 1) setSelectedIdx(selectedIdx + 1); }
  function selectPrev() { if (selectedIdx !== null && selectedIdx > 0) setSelectedIdx(selectedIdx - 1); }

  if (loading) return <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', padding: '80px 0' }}><Loader2 className="w-5 h-5 animate-spin" style={{ color: 'var(--studio-accent)' }} /></div>;

  return (
    <div className="sb-root">
      <style>{`
        .sb-root { display:flex; flex-direction:column; gap:12px; height:calc(100vh - 180px); min-height:0; }
        .sb-root * { box-sizing:border-box; }

        /* Theme variables — inherit from app, fallback values for Studio */
        .sb-root {
          --studio-bg: var(--background, #0b0f0d);
          --studio-surface: var(--card, #111714);
          --studio-raised: var(--muted, #171d19);
          --studio-border: var(--border, rgba(255,255,255,.06));
          --studio-border-active: color-mix(in oklch, var(--primary, #2fcf8f) 25%, transparent);
          --studio-text: var(--foreground, #dde4df);
          --studio-text-dim: color-mix(in oklch, var(--foreground, #dde4df) 60%, transparent);
          --studio-text-muted: color-mix(in oklch, var(--foreground, #dde4df) 40%, transparent);
          --studio-accent: var(--primary, #2fcf8f);
          --studio-accent-dim: color-mix(in oklch, var(--primary, #2fcf8f) 15%, transparent);
          --studio-red: var(--destructive, #f87171);
          --studio-amber: #f7b955;
          --studio-mono: 'JetBrains Mono', ui-monospace, monospace;
          --studio-serif: 'Newsreader', 'Cormorant Garamond', Georgia, serif;
        }

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
          .sb-grid-wrapper { display:none!important; }
          .sb-sidebar, .sb-right-panel { display:none!important; }
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
          .sb-grid-wrapper { display:flex; }
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
                <div key={col.key} role="button" tabIndex={0} onKeyDown={e => { if (e.key === 'Enter') setSelectedIdx(idx); }} onClick={() => setSelectedIdx(idx)} className={`sb-col-item${idx === selectedIdx ? ' sb-active' : ''}`}>
                  <div style={{ flex: 1, minWidth: 0 }}><div className="col-name">{col.name || untitled}</div><div className="col-meta">{col.fields.length} champs</div></div>
                  <button onClick={e => { e.stopPropagation(); removeCollection(idx); }} style={{ background: 'none', border: 'none', cursor: 'pointer', padding: '1px', opacity: .4 }}><Trash2 className="w-3 h-3" style={{ color: 'var(--studio-red)' }} /></button>
                </div>
              ))}
              {collections.length === 0 && <p style={{ fontSize: '11px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '16px 6px' }}>Vide</p>}
            </div>

            {/* ── EndUsers card (always visible) ── */}
            <div style={{ marginTop: '8px', paddingTop: '8px', borderTop: '1px solid var(--studio-border)' }}>
              <button
                onClick={() => setSelectedIdx(-1)}
                className={`sb-col-item${isEndUsers ? ' sb-active' : ''}`}
                style={{ width: '100%', textAlign: 'left', background: isEndUsers ? 'var(--studio-accent-dim)' : 'var(--studio-surface)', border: isEndUsers ? '1px solid var(--studio-border-active)' : '1px solid var(--studio-border)' }}
              >
                <div style={{ width: '28px', height: '28px', borderRadius: '6px', background: 'rgba(87,157,219,.15)', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                  <Users className="w-3.5 h-3.5" style={{ color: '#579ddb' }} />
                </div>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div className="col-name">Utilisateurs</div>
                  <div className="col-meta">{endUserFields.length} champs · auth JWT</div>
                </div>
              </button>
            </div>
          </>)}
        </div>

        {/* Desktop Editor */}
        <div className="sb-editor desktop-editor">
          <style>{`@media(max-width:1024px){.desktop-editor{display:none!important}}`}</style>
          {isEndUsers ? (
            <EndUserEditor fields={endUserFields} loading={endUserLoading} onAdd={addEndUserField} onUpdate={updateEndUserField} onDelete={deleteEndUserField} newField={endUserNew} setNewField={setEndUserNew} />
          ) : (
            renderEditor(current, selectedIdx, collections, untitled, t, selectPrev, selectNext, addCollection, updateCollection, removeCollection, addField, updateField, removeField, duplicateField, generatePreview)
          )}
        </div>

        <div className={`sb-right-panel${rightPanelOpen ? '' : ' collapsed'}`} style={{ width: rightW > 0 ? rightW + 'px' : undefined }}>
          {rightW > 0 && (
            <DesktopRightPanel project={project} currentCollections={collections} onApplySchema={handleApplySchema} onApplyEndUserFields={handleApplyEndUserFields} endUserFields={endUserFields} current={current} onGeneratePreview={generatePreview} preview={preview} />
          )}
        </div>
      </div>

      {/* ═══════════════ MOBILE: tab-based content ═══════════════ */}
      <div className="sb-mobile-content">
        <div style={{ display: mobileTab === 'collections' ? 'block' : 'none' }}>
          <MobileCollectionsView
            collections={collections} selectedIdx={selectedIdx} untitled={untitled}
            onSelect={(idx) => { setSelectedIdx(idx); setMobileTab('editor'); }}
            onDelete={removeCollection}
            onAdd={addCollection}
            onSelectEndUsers={() => { setSelectedIdx(-1); setMobileTab('editor'); }}
            isEndUsers={isEndUsers}
            endUserFieldCount={endUserFields.length}
          />
        </div>
        <div style={{ display: mobileTab === 'editor' ? 'flex' : 'none', flexDirection: 'column', flex: 1, minHeight: 0 }}>
          {isEndUsers ? (
            <EndUserEditor fields={endUserFields} loading={endUserLoading} onAdd={addEndUserField} onUpdate={updateEndUserField} onDelete={deleteEndUserField} newField={endUserNew} setNewField={setEndUserNew} />
          ) : (
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
        </div>
        <div style={{ display: mobileTab === 'chat' ? 'flex' : 'none', flexDirection: 'column', flex: 1, minHeight: 0 }}>
          <DesktopRightPanel
            project={project} currentCollections={collections} onApplySchema={handleApplySchema}
            onApplyEndUserFields={handleApplyEndUserFields} endUserFields={endUserFields}
            current={current} onGeneratePreview={generatePreview} preview={preview}
          />
        </div>
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

      {/* ═══════════════ CONFIRM DELETE DIALOG ═══════════════ */}
      <Dialog open={confirmDeleteIdx !== null} onOpenChange={() => setConfirmDeleteIdx(null)}>
        <DialogContent style={{ background: 'var(--studio-surface)', border: '1px solid var(--studio-border)', borderRadius: '12px', color: 'var(--studio-text)', maxWidth: '380px' }}>
          <DialogHeader>
            <DialogTitle style={{ fontFamily: 'var(--studio-serif)', fontSize: '16px', color: 'var(--studio-text)' }}>
              {t('studio.delete_confirm.title')}
            </DialogTitle>
            <DialogDescription style={{ fontSize: '12px', color: 'var(--studio-text-dim)' }}>
              {confirmDeleteIdx !== null && collections[confirmDeleteIdx] && (
                <>{t('studio.delete_confirm.message', { name: collections[confirmDeleteIdx].name || t('studio.schema.untitled') })}</>
              )}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter style={{ display: 'flex', gap: '8px', justifyContent: 'flex-end', marginTop: '16px' }}>
            <Button variant="outline" size="sm" onClick={() => setConfirmDeleteIdx(null)} style={{ borderColor: 'var(--studio-border)', color: 'var(--studio-text-dim)' }}>{t('common.cancel')}</Button>
            <Button size="sm" onClick={confirmRemoveCollection} style={{ background: 'var(--studio-red)', color: '#fff' }}>{t('common.delete')}</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/* ═══════════════ FIELD OPTIONS EDITOR ═══════════════ */
interface FieldOptions {
  minLength?: number; maxLength?: number; pattern?: string; placeholder?: string;
  min?: number; max?: number; step?: number; defaultValue?: string;
  values?: string[];
  mediaTypes?: ('image'|'video'|'document')[]; multiple?: boolean;
  targetCollection?: string;
  toolbar?: ('bold'|'italic'|'link'|'image'|'list'|'heading')[];
  helpText?: string; hideInList?: boolean; readOnly?: boolean;
}
const FIELD_OPTION_DEFAULTS: Record<string, Partial<FieldOptions>> = {
  text: { placeholder: '', minLength: undefined, maxLength: undefined, pattern: '' },
  longtext: { placeholder: '', minLength: undefined, maxLength: undefined },
  number: { min: undefined, max: undefined, step: undefined, defaultValue: '' },
  enumeration: { values: [] },
  media: { mediaTypes: ['image'], multiple: false },
  relation: { targetCollection: '' },
};
function parseFieldOptions(field: SchemaField): FieldOptions {
  try { return typeof field.options === 'object' && field.options ? field.options as FieldOptions : {}; } catch { return {}; }
}
function FieldOptionsEditor({ field, allCollections, onChange }: { field: SchemaField; allCollections: SchemaCollection[]; onChange: (opts: FieldOptions) => void; }) {
  const opts = parseFieldOptions(field);
  const defaults = FIELD_OPTION_DEFAULTS[field.type] ?? {};
  const S = {
    input: { height:'28px', fontSize:'10px', background:'var(--studio-bg)', borderColor:'var(--studio-border)', color:'var(--studio-text)', borderRadius:'5px', padding:'0 6px', outline:'none' } as React.CSSProperties,
    label: { fontSize:'10px', color:'var(--studio-text-muted)', marginBottom:'3px', display:'block' as const },
  };
  if (field.type === 'enumeration') {
    const values = opts.values ?? (defaults.values ?? []);
    return (
      <div style={{ marginTop:'8px', padding:'8px', border:'1px solid var(--studio-border)', borderRadius:'6px', background:'var(--studio-surface)' }}>
        <span style={S.label}>Valeurs de l'énumération</span>
        <div style={{ display:'flex', flexDirection:'column', gap:'3px', marginBottom:'6px' }}>
          {values.map((v: string, i: number) => (
            <div key={i} style={{ display:'flex', gap:'4px', alignItems:'center' }}>
              <input value={v} onChange={e => { const nv = [...values]; nv[i] = e.target.value; onChange({ ...opts, values: nv }); }} style={{ ...S.input, flex:1 }} placeholder={`Valeur ${i+1}`} />
              <button onClick={() => onChange({ ...opts, values: values.filter((_, j) => j !== i) })} style={{ background:'none',border:'none',cursor:'pointer',padding:'2px' }}><Trash2 className="w-3 h-3" style={{ color:'var(--studio-red)' }} /></button>
            </div>
          ))}
        </div>
        <Button size="sm" variant="outline" onClick={() => onChange({ ...opts, values: [...values, ''] })} style={{ height:'22px', fontSize:'9px' }}><Plus className="w-2.5 h-2.5 mr-1" />Ajouter une valeur</Button>
      </div>
    );
  }
  if (field.type === 'number') {
    return (
      <div style={{ marginTop:'8px', padding:'8px', border:'1px solid var(--studio-border)', borderRadius:'6px', background:'var(--studio-surface)', display:'grid', gridTemplateColumns:'1fr 1fr', gap:'6px' }}>
        <div><span style={S.label}>Min</span><input type="number" value={opts.min ?? ''} onChange={e => onChange({ ...opts, min: e.target.value ? Number(e.target.value) : undefined })} style={{ ...S.input, width:'100%' }} /></div>
        <div><span style={S.label}>Max</span><input type="number" value={opts.max ?? ''} onChange={e => onChange({ ...opts, max: e.target.value ? Number(e.target.value) : undefined })} style={{ ...S.input, width:'100%' }} /></div>
        <div><span style={S.label}>Pas</span><input type="number" value={opts.step ?? ''} onChange={e => onChange({ ...opts, step: e.target.value ? Number(e.target.value) : undefined })} style={{ ...S.input, width:'100%' }} /></div>
        <div><span style={S.label}>Défaut</span><input value={opts.defaultValue ?? ''} onChange={e => onChange({ ...opts, defaultValue: e.target.value })} style={{ ...S.input, width:'100%' }} /></div>
      </div>
    );
  }
  if (field.type === 'text' || field.type === 'longtext') {
    return (
      <div style={{ marginTop:'8px', padding:'8px', border:'1px solid var(--studio-border)', borderRadius:'6px', background:'var(--studio-surface)', display:'grid', gridTemplateColumns:'1fr 1fr', gap:'6px' }}>
        <div style={{ gridColumn:'1/-1' }}><span style={S.label}>Placeholder</span><input value={opts.placeholder ?? ''} onChange={e => onChange({ ...opts, placeholder: e.target.value })} style={{ ...S.input, width:'100%' }} /></div>
        <div><span style={S.label}>Longueur min</span><input type="number" value={opts.minLength ?? ''} onChange={e => onChange({ ...opts, minLength: e.target.value ? Number(e.target.value) : undefined })} style={{ ...S.input, width:'100%' }} /></div>
        <div><span style={S.label}>Longueur max</span><input type="number" value={opts.maxLength ?? ''} onChange={e => onChange({ ...opts, maxLength: e.target.value ? Number(e.target.value) : undefined })} style={{ ...S.input, width:'100%' }} /></div>
        <div style={{ gridColumn:'1/-1' }}><span style={S.label}>Pattern regex</span><input value={opts.pattern ?? ''} onChange={e => onChange({ ...opts, pattern: e.target.value })} style={{ ...S.input, width:'100%', fontFamily:'var(--studio-mono)' }} /></div>
      </div>
    );
  }
  if (field.type === 'media') {
    const types = opts.mediaTypes ?? (defaults.mediaTypes ?? ['image']);
    const toggleType = (t: 'image'|'video'|'document') => { const next = types.includes(t) ? types.filter(x => x !== t) : [...types, t]; onChange({ ...opts, mediaTypes: next.length > 0 ? next : ['image'] }); };
    return (
      <div style={{ marginTop:'8px', padding:'8px', border:'1px solid var(--studio-border)', borderRadius:'6px', background:'var(--studio-surface)' }}>
        <span style={S.label}>Types de médias acceptés</span>
        <div style={{ display:'flex', gap:'6px', marginBottom:'8px' }}>
          {(['image','video','document'] as const).map(t => (
            <button key={t} onClick={() => toggleType(t)} style={{ padding:'4px 8px', borderRadius:'5px', fontSize:'10px', cursor:'pointer', border:'1px solid var(--studio-border)', background: types.includes(t) ? 'var(--studio-accent-dim)' : 'transparent', color: types.includes(t) ? 'var(--studio-accent)' : 'var(--studio-text-dim)' }}>
              {t === 'image' ? '🖼️ Image' : t === 'video' ? '🎬 Vidéo' : '📄 Document'}
            </button>
          ))}
        </div>
        <div style={{ display:'flex', alignItems:'center', gap:'8px' }}><Switch checked={opts.multiple ?? defaults.multiple ?? false} onCheckedChange={v => onChange({ ...opts, multiple: v })} /><span style={S.label}>Fichiers multiples</span></div>
      </div>
    );
  }
  if (field.type === 'relation') {
    // Merge EndUsers comme option système + allCollections
    const relationTargets = [
      { key: '__end_users__', name: 'EndUsers (système)', slug: 'end_users' },
      ...allCollections.filter(c => c.slug && c.slug !== 'end_users'),
    ];
    return (
      <div style={{ marginTop:'8px', padding:'8px', border:'1px solid var(--studio-border)', borderRadius:'6px', background:'var(--studio-surface)' }}>
        <span style={S.label}>Collection liée</span>
        <select value={opts.targetCollection ?? ''} onChange={e => onChange({ ...opts, targetCollection: e.target.value })} style={{ ...S.input, width:'100%', marginTop:'4px' }}>
          <option value="">— Sélectionner —</option>
          {relationTargets.map(c => (<option key={c.key} value={c.slug}>{c.name || c.slug}</option>))}
        </select>
      </div>
    );
  }
  return (
    <div style={{ marginTop:'8px', padding:'8px', border:'1px solid var(--studio-border)', borderRadius:'6px', background:'var(--studio-surface)', display:'flex', flexDirection:'column', gap:'6px' }}>
      <div><span style={S.label}>Texte d'aide</span><input value={opts.helpText ?? ''} onChange={e => onChange({ ...opts, helpText: e.target.value })} style={{ ...S.input, width:'100%' }} /></div>
      <div style={{ display:'flex', alignItems:'center', gap:'8px' }}><Switch checked={opts.hideInList ?? false} onCheckedChange={v => onChange({ ...opts, hideInList: v })} /><span style={S.label}>Masquer dans les listes</span></div>
      <div style={{ display:'flex', alignItems:'center', gap:'8px' }}><Switch checked={opts.readOnly ?? false} onCheckedChange={v => onChange({ ...opts, readOnly: v })} /><span style={S.label}>Lecture seule</span></div>
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
      {current.fields.map((field, idx) => (
        <FieldRow
          key={field.key}
          field={field}
          index={idx}
          total={current.fields.length}
          selectedIdx={selectedIdx!}
          collections={collections}
          updateField={updateField}
          removeField={removeField}
          duplicateField={duplicateField}
          moveField={(from, to) => {
            const reordered = [...current.fields];
            const [moved] = reordered.splice(from, 1);
            reordered.splice(to, 0, moved);
            updateCollection(selectedIdx!, { fields: reordered });
          }}
        />
      ))}
      {current.fields.length === 0 && <p style={{ fontSize: '11px', color: 'var(--studio-text-muted)', textAlign: 'center', padding: '20px' }}>Aucun champ. Cliquez "Ajouter".</p>}
    </div>
  </>);
}

/* ═══════════════ FIELD ROW (expandable, draggable) ═══════════════ */
function FieldRow({
  field, index, total, selectedIdx, collections,
  updateField, removeField, duplicateField, moveField,
}: {
  field: SchemaField; index: number; total: number; selectedIdx: number;
  collections: SchemaCollection[];
  updateField: (ci: number, fk: string, d: Partial<SchemaField>) => void;
  removeField: (ci: number, fk: string) => void;
  duplicateField: (ci: number, fk: string) => void;
  moveField: (from: number, to: number) => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const [dragOver, setDragOver] = useState<number | null>(null);
  const Icon = ICON_MAP[field.type] || Type;
  const hasName = field.name.trim().length > 0;
  const hasOptions = field.type === 'enumeration' || field.type === 'number' || field.type === 'text'
    || field.type === 'longtext' || field.type === 'media' || field.type === 'relation';

  function handleOptionsChange(opts: FieldOptions) {
    updateField(selectedIdx, field.key, { options: opts as any });
  }

  return (
    <div
      draggable
      onDragStart={e => e.dataTransfer.setData('text/plain', String(index))}
      onDragOver={e => { e.preventDefault(); setDragOver(index); }}
      onDragLeave={() => setDragOver(null)}
      onDrop={e => {
        e.preventDefault(); setDragOver(null);
        const from = Number(e.dataTransfer.getData('text/plain'));
        if (from !== index && !isNaN(from)) moveField(from, index);
      }}
      style={{
        border: `1px solid ${dragOver === index ? 'var(--studio-accent)' : hasName ? 'var(--studio-border)' : 'var(--studio-red)'}`,
        borderRadius: '7px', marginBottom: '4px', overflow: 'hidden',
        transition: 'border-color .15s',
      }}
    >
      {/* ── Main row ── */}
      <div style={{ display:'flex', alignItems:'center', gap:'6px', padding:'8px 10px', background:'var(--studio-raised)', cursor:'default' }}>
        <div style={{ cursor:'grab', color:'var(--studio-text-muted)', padding:'0 2px' }} title="Glisser pour réordonner">
          <GripVertical className="w-3.5 h-3.5" />
        </div>
        <span style={{ fontFamily:'var(--studio-mono)', fontSize:'9px', color:'var(--studio-text-muted)', minWidth:'14px', textAlign:'center' }}>{index + 1}</span>

        <div style={{ flex:1, minWidth:0, position:'relative' }}>
          <Input
            placeholder="Nom du champ"
            value={field.name}
            onChange={e => updateField(selectedIdx, field.key, { name: e.target.value })}
            style={{
              height:'28px', fontSize:'11px', background:'var(--studio-bg)',
              borderColor: hasName ? 'var(--studio-border)' : 'var(--studio-red)',
              color:'var(--studio-text)', width:'100%',
            }}
          />
          {!hasName && (
            <span style={{ position:'absolute', right:'8px', top:'6px', fontSize:'9px', color:'var(--studio-red)' }}>Requis</span>
          )}
        </div>

        <Select value={field.type} onValueChange={v => updateField(selectedIdx, field.key, { type: v })}>
          <SelectTrigger style={{ width:'110px', height:'28px', fontSize:'10px', background:'var(--studio-bg)', borderColor:'var(--studio-border)', color:'var(--studio-text)' }}>
            <Icon className="w-2.5 h-2.5 mr-1" /><SelectValue />
          </SelectTrigger>
          <SelectContent>{FIELD_TYPES.map(ft => (<SelectItem key={ft.type} value={ft.type}><span style={{display:'flex',alignItems:'center',gap:'6px'}}>{(ICON_MAP[ft.type] && React.createElement(ICON_MAP[ft.type], {className:'w-3 h-3'}))}{ft.label}</span></SelectItem>))}</SelectContent>
        </Select>

        <div style={{ display:'flex', alignItems:'center', gap:'3px' }}>
          <Switch checked={field.isRequired} onCheckedChange={v => updateField(selectedIdx, field.key, { isRequired: v })} />
          <span style={{ fontSize:'10px', color:'var(--studio-text-muted)', minWidth:'40px' }}>Requis</span>
        </div>

        <button onClick={() => setExpanded(!expanded)}
          style={{ background:'none',border:'none',cursor:'pointer',padding:'2px',color:'var(--studio-text-muted)',transition:'transform .15s',transform:expanded?'rotate(180deg)':'none' }}>
          <ChevronDown className="w-3.5 h-3.5" />
        </button>

        <Button variant="ghost" size="icon" style={{ height:'22px', width:'22px' }} onClick={() => duplicateField(selectedIdx, field.key)}>
          <Copy className="w-3 h-3" />
        </Button>
        <Button variant="ghost" size="icon" style={{ height:'22px', width:'22px' }} onClick={() => removeField(selectedIdx, field.key)}>
          <Trash2 className="w-3 h-3" style={{ color:'var(--studio-red)' }} />
        </Button>
      </div>

      {/* ── Slug preview + options ── */}
      {expanded && (
        <div style={{ padding:'8px 10px 10px 38px', background:'var(--studio-surface)', borderTop:'1px solid var(--studio-border)' }}>
          {/* Slug */}
          <div style={{ marginBottom:'6px', display:'flex', alignItems:'center', gap:'6px' }}>
            <span style={{ fontSize:'9px', color:'var(--studio-text-muted)', fontFamily:'var(--studio-mono)' }}>slug:</span>
            <code style={{ fontSize:'10px', fontFamily:'var(--studio-mono)', color:'var(--studio-accent)', background:'var(--studio-raised)', padding:'2px 6px', borderRadius:'4px' }}>{field.slug || '(auto)'}</code>
          </div>

          {/* Type-specific options */}
          {hasOptions && (
            <FieldOptionsEditor field={field} allCollections={collections} onChange={handleOptionsChange} />
          )}

          {/* General options for all types */}
          {!hasOptions && (
            <div style={{ display:'flex', alignItems:'center', gap:'12px', marginTop:'6px' }}>
              <div style={{ display:'flex', alignItems:'center', gap:'6px' }}>
                <Switch checked={parseFieldOptions(field).hideInList ?? false} onCheckedChange={v => handleOptionsChange({ ...parseFieldOptions(field), hideInList: v })} />
                <span style={{ fontSize:'10px', color:'var(--studio-text-muted)' }}>Masquer dans les listes</span>
              </div>
              <div style={{ display:'flex', alignItems:'center', gap:'6px' }}>
                <Switch checked={parseFieldOptions(field).readOnly ?? false} onCheckedChange={v => handleOptionsChange({ ...parseFieldOptions(field), readOnly: v })} />
                <span style={{ fontSize:'10px', color:'var(--studio-text-muted)' }}>Lecture seule</span>
              </div>
            </div>
          )}
        </div>
      )}
    </div>
  );
}

/* ═══════════════ MOBILE COLLECTIONS VIEW ═══════════════ */
function MobileCollectionsView({
  collections, selectedIdx, untitled,
  onSelect, onDelete, onAdd, onSelectEndUsers, isEndUsers,
  endUserFieldCount,
}: {
  collections: SchemaCollection[]; selectedIdx: number | null; untitled: string;
  onSelect: (idx: number) => void; onDelete: (idx: number) => void; onAdd: () => void;
  onSelectEndUsers: () => void; isEndUsers: boolean; endUserFieldCount: number;
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
        {/* ── EndUsers card (always first in mobile) ── */}
        <div className={`sb-mobile-col-card${isEndUsers ? ' sb-active' : ''}`} onClick={onSelectEndUsers} style={{ borderColor: isEndUsers ? 'var(--studio-border-active)' : undefined }}>
          <div className="col-icon" style={{ background: 'rgba(87,157,219,.15)', color: '#579ddb' }}><Users className="w-4 h-4" /></div>
          <div className="col-info">
            <div className="cname">Utilisateurs</div>
            <div className="cmeta">{endUserFieldCount} champs · auth JWT</div>
          </div>
          <ChevronRight className="w-4 h-4" style={{ color: 'var(--studio-text-muted)' }} />
        </div>

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
