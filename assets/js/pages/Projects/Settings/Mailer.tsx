import { Head } from '@inertiajs/react'
import { useState, useEffect, useRef, type FormEventHandler } from 'react'
import axios from 'axios'

import type { Project, BreadcrumbItem } from '@/types/index.d'
import AppLayout from '@/layouts/app-layout'
import ProjectSettingsLayout from './layout'
import HeadingSmall from '@/components/heading-small'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { useTranslation } from '@/lib/i18n'

interface Props { project: Project }

interface MailerSettings {
  host: string; port: number; username: string
  encryption: string; from_email: string; from_name: string
  enabled: boolean
}

const DEFAULTS: MailerSettings = {
  host: '', port: 587, username: '', encryption: 'tls',
  from_email: '', from_name: '', enabled: false,
}

export default function MailerSettingsPage({ project }: Props) {
  const t = useTranslation()

  const [settings, setSettings] = useState<MailerSettings>({ ...DEFAULTS })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [testing, setTesting] = useState(false)
  const [password, setPassword] = useState('')
  const [toast, setToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null)
  const [testResult, setTestResult] = useState<{ ok: boolean; msg: string } | null>(null)
  const toastTimer = useRef<ReturnType<typeof setTimeout>>()

  const breadcrumbs: BreadcrumbItem[] = [
    { title: project.name, href: route('projects.show', project.id) },
    { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
    { title: t('projects.settings.nav_mailer'), href: route('projects.settings.mailer', project.id) },
  ]

  useEffect(() => {
    axios.get(`/api/admin/projects/${project.uuid}/mailer`)
      .then(r => {
        setSettings(r.data.data ? { ...DEFAULTS, ...r.data.data } : { ...DEFAULTS })
        setLoading(false)
      })
      .catch(() => { setSettings({ ...DEFAULTS }); setLoading(false) })
  }, [project.uuid])

  // Nettoyage du timer de toast au démontage
  useEffect(() => {
    return () => { if (toastTimer.current) clearTimeout(toastTimer.current) }
  }, [])

  function showToast(type: 'ok' | 'err', msg: string) {
    setToast({ type, msg })
    if (toastTimer.current) clearTimeout(toastTimer.current)
    toastTimer.current = setTimeout(() => setToast(null), 4000)
  }

  const submit: FormEventHandler = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      const body: any = { ...settings }
      if (password) body.password = password
      const r = await axios.put(`/api/admin/projects/${project.uuid}/mailer`, body)
      // merge plutôt qu'écraser : protège contre un champ omis par l'API
      setSettings(s => ({ ...s, ...r.data.data }))
      setPassword('')
      showToast('ok', t('projects.settings.mailer.saved'))
    } catch (err: any) {
      showToast('err', err.response?.data?.error || t('common.error'))
    } finally { setSaving(false) }
  }

  async function handleTest() {
    if (!settings.enabled) { showToast('err', t('projects.settings.mailer.not_configured')); return }
    setTesting(true); setTestResult(null)
    try {
      await axios.post(`/api/admin/projects/${project.uuid}/mailer/test`)
      setTestResult({ ok: true, msg: t('projects.settings.mailer.test_ok') })
    } catch (err: any) {
      setTestResult({ ok: false, msg: err.response?.data?.error || t('common.error') })
    } finally { setTesting(false) }
  }

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={t('projects.settings.nav_mailer')} />
      <ProjectSettingsLayout project={project}>
        <div className="space-y-6 max-w-2xl">
          <HeadingSmall
            title={t('projects.settings.mailer.title')}
            description={t('projects.settings.mailer.desc')}
          />

          {toast && (
            <Alert variant={toast.type === 'ok' ? 'default' : 'destructive'}>
              {toast.type === 'ok' ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
              <AlertDescription>{toast.msg}</AlertDescription>
            </Alert>
          )}

          {testResult && (
            <Alert variant={testResult.ok ? 'default' : 'destructive'}>
              {testResult.ok ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
              <AlertDescription>{testResult.msg}</AlertDescription>
            </Alert>
          )}

          {loading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}

          {!loading && (
            <form onSubmit={submit} className="space-y-6">
              <div className="flex items-center justify-between rounded-lg border p-4">
                <div>
                  <Label htmlFor="enabled" className="text-base font-medium">{t('projects.settings.mailer.enabled')}</Label>
                  <p className="text-sm text-muted-foreground">{t('projects.settings.mailer.enable_desc')}</p>
                </div>
                <Switch
                  id="enabled"
                  checked={settings.enabled}
                  onCheckedChange={(checked) => setSettings(s => ({ ...s, enabled: checked }))}
                />
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="host">{t('projects.settings.mailer.host')}</Label>
                  <Input id="host" value={settings.host} onChange={e => setSettings(s => ({ ...s, host: e.target.value }))} required placeholder="smtp.gmail.com" />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="port">{t('projects.settings.mailer.port')}</Label>
                  <Select value={String(settings.port)} onValueChange={v => setSettings(s => ({ ...s, port: parseInt(v) }))}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      <SelectItem value="587">587 (TLS)</SelectItem>
                      <SelectItem value="465">465 (SSL)</SelectItem>
                      <SelectItem value="25">25</SelectItem>
                      <SelectItem value="2525">2525</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="username">{t('projects.settings.mailer.username')}</Label>
                  <Input id="username" value={settings.username} onChange={e => setSettings(s => ({ ...s, username: e.target.value }))} placeholder="apikey" />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="password">{t('projects.settings.mailer.password')}</Label>
                  <Input id="password" type="password" value={password} onChange={e => setPassword(e.target.value)} placeholder="••••••••" />
                </div>
              </div>

              <div className="grid gap-2">
                <Label htmlFor="encryption">{t('projects.settings.mailer.encryption')}</Label>
                <Select value={settings.encryption} onValueChange={v => setSettings(s => ({ ...s, encryption: v }))}>
                  <SelectTrigger className="w-[180px]"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="tls">TLS</SelectItem>
                    <SelectItem value="ssl">SSL</SelectItem>
                    <SelectItem value="none">{t('projects.settings.mailer.encryption_none')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                  <Label htmlFor="from_email">{t('projects.settings.mailer.from_email')}</Label>
                  <Input id="from_email" type="email" value={settings.from_email} onChange={e => setSettings(s => ({ ...s, from_email: e.target.value }))} required placeholder="noreply@example.com" />
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="from_name">{t('projects.settings.mailer.from_name')}</Label>
                  <Input id="from_name" value={settings.from_name} onChange={e => setSettings(s => ({ ...s, from_name: e.target.value }))} placeholder={t('projects.settings.mailer.from_name')} />
                </div>
              </div>

              <div className="flex items-center gap-4">
                <Button type="submit" disabled={saving}>
                  {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  {saving ? t('projects.settings.mailer.saving') : t('common.save')}
                </Button>
                <Button type="button" variant="outline" onClick={handleTest} disabled={testing || !settings.enabled}>
                  {testing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  {testing ? t('projects.settings.mailer.testing') : t('projects.settings.mailer.test')}
                </Button>
              </div>
            </form>
          )}
        </div>
      </ProjectSettingsLayout>
    </AppLayout>
  )
}
