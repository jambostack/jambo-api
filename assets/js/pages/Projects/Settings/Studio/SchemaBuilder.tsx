import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Switch } from '@/components/ui/switch';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  GripVertical, Plus, Trash2, Eye, Save, Copy, Wand2,
  Type, AlignLeft, Hash, List, ToggleLeft, Calendar, Clock,
  AtSign, Link, Lock, Palette, Image, GitBranch, Code2, FileText
} from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import type { Project } from '@/types/index.d';
import fieldsDef from '@/lib/fields.json';

const FIELD_TYPES = Object.entries(fieldsDef).map(([key, val]) => ({ type: key, label: val.label, desc: val.desc }));

const ICON_MAP: Record<string, React.ComponentType<any>> = {
  text: Type, longtext: AlignLeft, richtext: FileText, slug: Link,
  email: AtSign, password: Lock, number: Hash, enumeration: List,
  boolean: ToggleLeft, color: Palette, date: Calendar, time: Clock,
  media: Image, relation: GitBranch, json: Code2,
};

interface SchemaField { key: string; name: string; slug: string; type: string; isRequired: boolean; options?: Record<string, any>; }
interface SchemaCollection { key: string; name: string; slug: string; description: string; isSingleton: boolean; fields: SchemaField[]; }

export default function SchemaBuilder({ project }: { project: Project }) {
  const t = useTranslation();
  const [collections, setCollections] = useState<SchemaCollection[]>([]);
  const [selectedCollection, setSelectedCollection] = useState<string | null>(null);
  const [preview, setPreview] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const current = collections.find(c => c.key === selectedCollection);

  function slugify(s: string) { return s.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|^_$/g, ''); }

  function addCollection() {
    const key = `col_${Date.now()}`;
    setCollections([...collections, { key, name: '', slug: '', description: '', isSingleton: false, fields: [] }]);
    setSelectedCollection(key);
  }

  function updateCollection(key: string, data: Partial<SchemaCollection>) {
    setCollections(collections.map(c => c.key === key ? { ...c, ...data, slug: data.name ? slugify(data.name) : c.slug } : c));
  }

  function removeCollection(key: string) {
    setCollections(collections.filter(c => c.key !== key));
    if (selectedCollection === key) setSelectedCollection(null);
  }

  function addField() {
    if (!current) return;
    updateCollection(current.key, { fields: [...current.fields, { key: `fld_${Date.now()}`, name: '', slug: '', type: 'text', isRequired: false }] });
  }

  function updateField(colKey: string, fKey: string, data: Partial<SchemaField>) {
    setCollections(collections.map(c => c.key === colKey ? { ...c, fields: c.fields.map(f => f.key === fKey ? { ...f, ...data, slug: data.name ? slugify(data.name) : f.slug } : f) } : c));
  }

  function removeField(colKey: string, fKey: string) {
    setCollections(collections.map(c => c.key === colKey ? { ...c, fields: c.fields.filter(f => f.key !== fKey) } : c));
  }

  function duplicateField(colKey: string, fKey: string) {
    const col = collections.find(c => c.key === colKey);
    const field = col?.fields.find(f => f.key === fKey);
    if (!field) return;
    updateCollection(colKey, { fields: [...(col?.fields ?? []), { ...field, key: `fld_${Date.now()}`, name: `${field.name} ${t('studio.schema.field_copy')}`, slug: `${field.slug}_copy` }] });
  }

  async function handleSave() {
    setSaving(true);
    try { await router.post(`/api/projects/${project.uuid}/studio/schema`, { collections }, { onSuccess: () => setSaving(false), onError: () => setSaving(false) }); } catch { setSaving(false); }
  }

  function generatePreview() {
    if (!current) return;
    const lines = [
      t('studio.schema.preview_collection', { name: current.name || t('studio.schema.untitled') }),
      `   slug: ${current.slug || '(auto)'}  |  singleton: ${current.isSingleton ? 'oui' : 'non'}`,
      `   ${current.fields.length} ${t('studio.schema.fields_count', { count: '' }).replace('{count} ', '').trim()}:`,
    ];
    current.fields.forEach((f, i) => {
      lines.push(`   ${i + 1}. ${f.name || t('studio.schema.untitled')}  [${f.type}]  ${f.isRequired ? t('studio.schema.preview_required') : t('studio.schema.preview_optional')}`);
    });
    setPreview(lines.join('\n'));
  }

  const untitled = t('studio.schema.untitled');

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight">{t('studio.schema.title')}</h2>
          <p className="text-muted-foreground">{t('studio.schema.desc')}</p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={addCollection}><Plus className="w-4 h-4 mr-2" />{t('studio.schema.new_collection')}</Button>
          <Button onClick={handleSave} disabled={saving || collections.length === 0}><Save className="w-4 h-4 mr-2" />{saving ? t('studio.schema.saving') : t('studio.schema.apply')}</Button>
        </div>
      </div>

      <div className="grid grid-cols-12 gap-6">
        <div className="col-span-12 lg:col-span-4 space-y-2">
          <h3 className="font-semibold text-sm text-muted-foreground uppercase tracking-wider">{t('studio.schema.collections_title')}</h3>
          {collections.map(col => (
            <Card key={col.key} className={`cursor-pointer transition-all hover:border-primary/50 ${selectedCollection === col.key ? 'border-primary ring-1 ring-primary/20' : ''}`} onClick={() => setSelectedCollection(col.key)}>
              <CardContent className="p-3 flex items-center justify-between">
                <div className="flex-1 min-w-0">
                  <p className="font-medium truncate">{col.name || untitled}</p>
                  <p className="text-xs text-muted-foreground">{col.fields.length} {t('studio.schema.fields_count', { count: '' }).trim().replace('{count} ', '')} · {col.isSingleton ? t('studio.schema.singleton') : t('studio.schema.multiple')}</p>
                </div>
                <Button variant="ghost" size="icon" onClick={e => { e.stopPropagation(); removeCollection(col.key); }}><Trash2 className="w-4 h-4 text-destructive" /></Button>
              </CardContent>
            </Card>
          ))}
          {collections.length === 0 && <p className="text-sm text-muted-foreground text-center py-8">{t('studio.schema.no_collections')}</p>}
        </div>

        <div className="col-span-12 lg:col-span-5 space-y-4">
          {current ? (<>
            <div className="grid grid-cols-2 gap-3">
              <div><Label>{t('common.name')}</Label><Input value={current.name} onChange={e => updateCollection(current.key, { name: e.target.value })} placeholder={t('studio.schema.articles_ph')} /></div>
              <div><Label>{t('studio.schema.slug_label')}</Label><Input value={current.slug} onChange={e => updateCollection(current.key, { slug: e.target.value })} placeholder="articles" /></div>
            </div>
            <div><Label>{t('studio.schema.desc_label')}</Label><Input value={current.description} onChange={e => updateCollection(current.key, { description: e.target.value })} placeholder={t('studio.schema.desc_placeholder')} /></div>
            <div className="flex items-center gap-3">
              <Switch checked={current.isSingleton} onCheckedChange={v => updateCollection(current.key, { isSingleton: v })} />
              <Label>{t('studio.schema.singleton_label')}</Label>
            </div>
            <Separator />
            <div className="flex items-center justify-between">
              <h3 className="font-semibold text-sm uppercase tracking-wider text-muted-foreground">{t('studio.schema.fields_title')}</h3>
              <div className="flex gap-2">
                <Button variant="outline" size="sm" onClick={generatePreview}><Eye className="w-3 h-3 mr-1" />{t('studio.schema.preview_btn')}</Button>
                <Button size="sm" onClick={addField}><Plus className="w-3 h-3 mr-1" />{t('studio.schema.add_field_btn')}</Button>
              </div>
            </div>
            <div className="space-y-2 max-h-[500px] overflow-y-auto pr-1">
              {current.fields.map((field, idx) => {
                const Icon = ICON_MAP[field.type] || Type;
                return (
                  <Card key={field.key} className="group">
                    <CardContent className="p-3">
                      <div className="flex items-center gap-2 mb-2">
                        <GripVertical className="w-4 h-4 text-muted-foreground cursor-grab" />
                        <span className="text-xs font-mono text-muted-foreground w-6">{idx + 1}</span>
                        <Input className="h-8 flex-1" placeholder={t('studio.schema.field_name_ph')} value={field.name} onChange={e => updateField(current.key, field.key, { name: e.target.value })} />
                        <Select value={field.type} onValueChange={v => updateField(current.key, field.key, { type: v })}>
                          <SelectTrigger className="w-36 h-8"><SelectValue /></SelectTrigger>
                          <SelectContent>{FIELD_TYPES.map(ft => (<SelectItem key={ft.type} value={ft.type}>{ft.label}</SelectItem>))}</SelectContent>
                        </Select>
                        <div className="flex items-center gap-1">
                          <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => duplicateField(current.key, field.key)}><Copy className="w-3 h-3" /></Button>
                          <Button variant="ghost" size="icon" className="h-7 w-7" onClick={() => removeField(current.key, field.key)}><Trash2 className="w-3 h-3 text-destructive" /></Button>
                        </div>
                      </div>
                      <div className="flex items-center gap-4 ml-12">
                        <div className="flex items-center gap-1.5">
                          <Switch checked={field.isRequired} onCheckedChange={v => updateField(current.key, field.key, { isRequired: v })} />
                          <span className="text-xs text-muted-foreground">{t('studio.schema.field_required')}</span>
                        </div>
                        <Badge variant="secondary" className="text-xs"><Icon className="w-3 h-3 mr-1" />{FIELD_TYPES.find(ft => ft.type === field.type)?.label ?? field.type}</Badge>
                      </div>
                    </CardContent>
                  </Card>
                );
              })}
            </div>
          </>) : (
            <div className="flex flex-col items-center justify-center h-64 text-muted-foreground">
              <Wand2 className="w-12 h-12 mb-3 opacity-30" />
              <p>{t('studio.schema.select_collection')}</p>
            </div>
          )}
        </div>

        <div className="col-span-12 lg:col-span-3">
          <Card>
            <CardHeader className="pb-2"><CardTitle className="text-sm flex items-center gap-2"><Eye className="w-4 h-4" />{t('studio.schema.preview_title')}</CardTitle></CardHeader>
            <CardContent>
              {preview ? <pre className="text-xs font-mono bg-muted p-3 rounded-md whitespace-pre-wrap">{preview}</pre> : <p className="text-xs text-muted-foreground">{t('studio.schema.preview_hint')}</p>}
              {current && (
                <div className="mt-3 pt-3 border-t">
                  <p className="text-xs text-muted-foreground">
                    <strong>{current.fields.length}</strong> {t('studio.schema.fields_count', { count: '' }).replace('{count} ', '').trim()} · <strong>{current.fields.filter(f => f.isRequired).length}</strong> {t('studio.schema.field_required').toLowerCase()}s
                  </p>
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
