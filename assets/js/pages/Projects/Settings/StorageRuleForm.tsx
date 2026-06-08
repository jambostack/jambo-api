import { useState, useEffect } from 'react'
import axios from 'axios'
import { useTranslation } from '@/lib/i18n'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Loader2 } from 'lucide-react'

interface Props {
  open: boolean; onClose: () => void; onSaved: () => void
  projectUuid: string; profiles: any[]; editRule: any | null
}

const MIME_PATTERNS = [
  { label: 'Images (*)', value: 'image/*' },
  { label: 'Vidéos (*)', value: 'video/*' },
  { label: 'Audio (*)', value: 'audio/*' },
  { label: 'PDF', value: 'application/pdf' },
  { label: 'JSON', value: 'application/json' },
  { label: 'Custom...', value: '' },
]

export default function StorageRuleForm({ open, onClose, onSaved, projectUuid, profiles, editRule }: Props) {
  const t = useTranslation()
  const isEdit = editRule !== null

  const [profileUuid, setProfileUuid] = useState('')
  const [mimePattern, setMimePattern] = useState('')
  const [customMime, setCustomMime] = useState('')
  const [extension, setExtension] = useState('')
  const [maxSize, setMaxSize] = useState('')
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (editRule) {
      setProfileUuid(editRule.profile_uuid || '')
      setMimePattern(editRule.mime_type_pattern || '')
      setExtension(editRule.extension || '')
      setMaxSize(editRule.max_size ? String(editRule.max_size) : '')
    } else {
      setProfileUuid(profiles[0]?.uuid || '')
      setMimePattern(''); setCustomMime(''); setExtension(''); setMaxSize('')
    }
  }, [editRule, open, profiles])

  const effectiveMime = mimePattern || customMime

  async function handleSubmit() {
    setSaving(true)
    try {
      const body: any = {
        profile_uuid: profileUuid,
        mime_type_pattern: effectiveMime || null,
        extension: extension || null,
        max_size: maxSize ? parseInt(maxSize) : null,
        priority: 0,
      }
      if (isEdit) {
        await axios.put(`/api/admin/projects/${projectUuid}/storage/rules/${editRule.id}`, body)
      } else {
        await axios.post(`/api/admin/projects/${projectUuid}/storage/rules`, body)
      }
      onSaved()
    } catch { /* toast géré par le parent */ }
    finally { setSaving(false) }
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t('common.edit') : t('projects.settings.storage.add_rule')}
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-4 py-4">
          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.rule_target')}</Label>
            <Select value={profileUuid} onValueChange={setProfileUuid}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                {profiles.filter(p => p.enabled).map(p => (
                  <SelectItem key={p.uuid} value={p.uuid}>{p.name}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.mime_pattern')}</Label>
            <Select value={mimePattern} onValueChange={v => { setMimePattern(v); setCustomMime('') }}>
              <SelectTrigger><SelectValue placeholder="Sélectionner..." /></SelectTrigger>
              <SelectContent>
                {MIME_PATTERNS.map(m => (
                  <SelectItem key={m.value} value={m.value}>{m.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
            {mimePattern === '' && !isEdit && (
              <Input className="mt-1" value={customMime}
                onChange={e => setCustomMime(e.target.value)}
                placeholder="application/x-custom" />
            )}
          </div>
          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.extension')}</Label>
            <Input value={extension} onChange={e => setExtension(e.target.value)} placeholder="pdf" />
          </div>
          <div className="grid gap-2">
            <Label>{t('projects.settings.storage.max_size')}</Label>
            <Input type="number" value={maxSize} onChange={e => setMaxSize(e.target.value)} placeholder="10485760" />
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
          <Button onClick={handleSubmit} disabled={saving || !profileUuid}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
