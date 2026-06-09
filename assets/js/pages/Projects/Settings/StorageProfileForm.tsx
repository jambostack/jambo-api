import { useState, useEffect } from 'react'
import axios from 'axios'
import { useTranslation } from '@/lib/i18n'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Loader2 } from 'lucide-react'

interface Props {
  open: boolean; onClose: () => void; onSaved: () => void
  projectUuid: string; editProfile: any | null
}

export default function StorageProfileForm({ open, onClose, onSaved, projectUuid, editProfile }: Props) {
  const t = useTranslation()
  const isEdit = editProfile !== null

  const [name, setName] = useState('')
  const [driver, setDriver] = useState<'local' | 's3'>('s3')
  const [s3Key, setS3Key] = useState('')
  const [s3Secret, setS3Secret] = useState('')
  const [s3Region, setS3Region] = useState('')
  const [s3Bucket, setS3Bucket] = useState('')
  const [s3Endpoint, setS3Endpoint] = useState('')
  const [s3PathStyle, setS3PathStyle] = useState(false)
  const [baseUrl, setBaseUrl] = useState('')
  const [rootPath, setRootPath] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const [enabled, setEnabled] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (editProfile) {
      setName(editProfile.name || '')
      setDriver(editProfile.driver || 's3')
      setS3Key(editProfile.s3_key || '')
      setS3Region(editProfile.s3_region || '')
      setS3Bucket(editProfile.s3_bucket || '')
      setS3Endpoint(editProfile.s3_endpoint || '')
      setS3PathStyle(editProfile.s3_use_path_style || false)
      setBaseUrl(editProfile.base_url || '')
      setRootPath(editProfile.root_path || '')
      setIsDefault(editProfile.is_default || false)
      setEnabled(editProfile.enabled !== false)
    } else {
      setName(''); setDriver('s3'); setS3Key(''); setS3Secret(''); setS3Region('')
      setS3Bucket(''); setS3Endpoint(''); setS3PathStyle(false); setBaseUrl('')
      setRootPath(''); setIsDefault(false); setEnabled(true)
    }
  }, [editProfile, open])

  async function handleSubmit() {
    setSaving(true)
    try {
      const body: any = { name, driver, enabled, is_default: isDefault, priority: 0 }
      if (driver === 's3') {
        body.s3_key = s3Key; body.s3_region = s3Region; body.s3_bucket = s3Bucket
        body.s3_endpoint = s3Endpoint; body.s3_use_path_style = s3PathStyle
        body.base_url = baseUrl
        if (s3Secret) body.s3_secret = s3Secret
      } else {
        body.root_path = rootPath
      }
      if (isEdit) {
        await axios.put(`/api/admin/projects/${projectUuid}/storage/profiles/${editProfile.id}`, body)
      } else {
        await axios.post(`/api/admin/projects/${projectUuid}/storage/profiles`, body)
      }
      onSaved()
    } catch { /* toast géré par le parent */ }
    finally { setSaving(false) }
  }

  return (
    <Dialog open={open} onOpenChange={onClose}>
      <DialogContent className="max-w-lg" aria-describedby={undefined}>
        <DialogHeader>
          <DialogTitle>
            {isEdit ? t('projects.settings.storage.edit_profile') : t('projects.settings.storage.add_profile')}
          </DialogTitle>
        </DialogHeader>
        <div className="space-y-4 py-4">
          <div className="grid gap-2">
            <Label>{t('common.name')}</Label>
            <Input value={name} onChange={e => setName(e.target.value)} placeholder="AWS S3 Paris" />
          </div>
          <div className="grid gap-2">
            <Label>Driver</Label>
            <Select value={driver} onValueChange={v => setDriver(v as 'local' | 's3')}>
              <SelectTrigger><SelectValue /></SelectTrigger>
              <SelectContent>
                <SelectItem value="s3">{t('projects.settings.storage.driver_s3')}</SelectItem>
                <SelectItem value="local">{t('projects.settings.storage.driver_local')}</SelectItem>
              </SelectContent>
            </Select>
          </div>
          {driver === 's3' && (
            <>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.s3_key')}</Label>
                <Input value={s3Key} onChange={e => setS3Key(e.target.value)} />
              </div>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.s3_secret')}</Label>
                <Input type="password" value={s3Secret} onChange={e => setS3Secret(e.target.value)}
                  placeholder={isEdit ? t('common.password_keep') : ''} />
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label>{t('projects.settings.storage.s3_region')}</Label>
                  <Input value={s3Region} onChange={e => setS3Region(e.target.value)} placeholder="eu-west-3" />
                </div>
                <div className="grid gap-2">
                  <Label>{t('projects.settings.storage.s3_bucket')}</Label>
                  <Input value={s3Bucket} onChange={e => setS3Bucket(e.target.value)} placeholder="my-bucket" />
                </div>
              </div>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.s3_endpoint')}</Label>
                <Input value={s3Endpoint} onChange={e => setS3Endpoint(e.target.value)}
                  placeholder="https://xxx.r2.cloudflarestorage.com" />
              </div>
              <div className="flex items-center gap-2">
                <Checkbox id="path_style" checked={s3PathStyle} onCheckedChange={v => setS3PathStyle(v === true)} />
                <Label htmlFor="path_style" className="text-sm">{t('projects.settings.storage.s3_path_style')}</Label>
              </div>
              <div className="grid gap-2">
                <Label>{t('projects.settings.storage.cdn_url')}</Label>
                <Input value={baseUrl} onChange={e => setBaseUrl(e.target.value)} placeholder="https://cdn.example.com" />
              </div>
            </>
          )}
          {driver === 'local' && (
            <div className="grid gap-2">
              <Label>Root path</Label>
              <Input value={rootPath} onChange={e => setRootPath(e.target.value)}
                placeholder={`public/uploads/media/${projectUuid}`} />
            </div>
          )}
          <div className="flex items-center gap-2">
            <Checkbox id="is_default" checked={isDefault} onCheckedChange={v => setIsDefault(v === true)} />
            <Label htmlFor="is_default" className="text-sm">Définir comme stockage par défaut</Label>
          </div>
        </div>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
          <Button onClick={handleSubmit} disabled={saving}>
            {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
            {t('common.save')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
