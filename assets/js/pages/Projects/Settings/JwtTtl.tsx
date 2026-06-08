import { Head } from '@inertiajs/react'
import { useState, useEffect, type FormEventHandler } from 'react'
import axios from 'axios'

import type { Project, BreadcrumbItem } from '@/types/index.d'
import AppLayout from '@/layouts/app-layout'
import ProjectSettingsLayout from './layout'
import HeadingSmall from '@/components/heading-small'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { CheckCircle, XCircle, Loader2 } from 'lucide-react'
import { useTranslation } from '@/lib/i18n'

interface Props { project: Project }

/** Format seconds as a human-readable duration. */
function formatTtl(seconds: number): string {
    if (seconds >= 86400) {
        const d = Math.round(seconds / 86400)
        return `= ${d} day${d > 1 ? 's' : ''}`
    }
    if (seconds >= 3600) {
        const h = Math.round(seconds / 3600)
        return `= ${h} hour${h > 1 ? 's' : ''}`
    }
    if (seconds >= 60) {
        const m = Math.round(seconds / 60)
        return `= ${m} min`
    }
    return `= ${seconds}s`
}

export default function JwtTtlSettingsPage({ project }: Props) {
  const t = useTranslation()

  const [accessTtl, setAccessTtl] = useState<string>('')
  const [refreshTtl, setRefreshTtl] = useState<string>('')
  const [defaults, setDefaults] = useState<{ access_ttl: number; refresh_ttl: number; max_ttl: number }>({
    access_ttl: 900, refresh_ttl: 2592000, max_ttl: 31536000,
  })
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [toast, setToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null)

  const breadcrumbs: BreadcrumbItem[] = [
    { title: project.name, href: route('projects.show', project.id) },
    { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
    { title: t('projects.settings.nav_jwt_ttl'), href: route('projects.settings.jwt_ttl', project.id) },
  ]

  useEffect(() => {
    axios.get(`/api/admin/projects/${project.uuid}/jwt-ttl`)
      .then(r => {
        if (r.data.jwt_access_ttl !== null && r.data.jwt_access_ttl !== undefined) {
          setAccessTtl(String(r.data.jwt_access_ttl))
        }
        if (r.data.jwt_refresh_ttl !== null && r.data.jwt_refresh_ttl !== undefined) {
          setRefreshTtl(String(r.data.jwt_refresh_ttl))
        }
        if (r.data.defaults) {
          setDefaults(r.data.defaults)
        }
        setLoading(false)
      })
      .catch(() => setLoading(false))
  }, [project.uuid])

  function showToast(type: 'ok' | 'err', msg: string) {
    setToast({ type, msg })
    setTimeout(() => setToast(null), 4000)
  }

  const submit: FormEventHandler = async (e) => {
    e.preventDefault()
    setSaving(true)
    try {
      const body: Record<string, string> = {}
      if (accessTtl !== '' && accessTtl !== null) body.jwt_access_ttl = accessTtl
      if (refreshTtl !== '' && refreshTtl !== null) body.jwt_refresh_ttl = refreshTtl
      await axios.patch(`/api/admin/projects/${project.uuid}/jwt-ttl`, body)
      showToast('ok', t('projects.settings.saved'))
    } catch (err: any) {
      showToast('err', err.response?.data?.error || t('common.error'))
    } finally { setSaving(false) }
  }

  async function resetToDefaults() {
    setSaving(true)
    try {
      await axios.patch(`/api/admin/projects/${project.uuid}/jwt-ttl`, {
        jwt_access_ttl: null,
        jwt_refresh_ttl: null,
      })
      setAccessTtl('')
      setRefreshTtl('')
      showToast('ok', t('projects.settings.saved'))
    } catch (err: any) {
      showToast('err', err.response?.data?.error || t('common.error'))
    } finally { setSaving(false) }
  }

  const accessValue = accessTtl === '' ? defaults.access_ttl : Number(accessTtl)
  const refreshValue = refreshTtl === '' ? defaults.refresh_ttl : Number(refreshTtl)

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={t('projects.settings.nav_jwt_ttl')} />
      <ProjectSettingsLayout project={project}>
        <div className="space-y-6 max-w-2xl">
          <HeadingSmall
            title={t('projects.settings.jwt_title')}
            description={t('projects.settings.jwt_desc')}
          />

          {toast && (
            <Alert variant={toast.type === 'ok' ? 'default' : 'destructive'}>
              {toast.type === 'ok' ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
              <AlertDescription>{toast.msg}</AlertDescription>
            </Alert>
          )}

          {loading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}

          {!loading && (
            <form onSubmit={submit} className="space-y-6">
              <div className="rounded-lg border p-4 space-y-2 bg-muted/30">
                <p className="text-sm font-medium">{t('projects.settings.jwt_defaults')}</p>
                <div className="grid grid-cols-3 gap-4 text-sm">
                  <div>
                    <span className="text-muted-foreground">{t('projects.settings.jwt_access_ttl')} : </span>
                    <span className="font-mono">{defaults.access_ttl}s</span>
                    <span className="text-muted-foreground ml-1">({formatTtl(defaults.access_ttl)})</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground">{t('projects.settings.jwt_refresh_ttl')} : </span>
                    <span className="font-mono">{defaults.refresh_ttl}s</span>
                    <span className="text-muted-foreground ml-1">({formatTtl(defaults.refresh_ttl)})</span>
                  </div>
                  <div>
                    <span className="text-muted-foreground">Max : </span>
                    <span className="font-mono">{formatTtl(defaults.max_ttl)}</span>
                  </div>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-6">
                <div className="grid gap-2">
                  <Label htmlFor="jwt_access_ttl">{t('projects.settings.jwt_access_ttl')}</Label>
                  <Input
                    id="jwt_access_ttl"
                    type="number"
                    min={60}
                    max={defaults.max_ttl}
                    value={accessTtl}
                    onChange={e => setAccessTtl(e.target.value)}
                    placeholder={String(defaults.access_ttl)}
                  />
                  <p className="text-xs text-muted-foreground">
                    {accessTtl && Number(accessTtl) >= 60
                      ? formatTtl(Number(accessTtl))
                      : accessTtl && Number(accessTtl) > 0
                        ? t('projects.settings.jwt_ttl_min')
                        : t('projects.settings.jwt_access_ttl_hint')}
                  </p>
                </div>
                <div className="grid gap-2">
                  <Label htmlFor="jwt_refresh_ttl">{t('projects.settings.jwt_refresh_ttl')}</Label>
                  <Input
                    id="jwt_refresh_ttl"
                    type="number"
                    min={60}
                    max={defaults.max_ttl}
                    value={refreshTtl}
                    onChange={e => setRefreshTtl(e.target.value)}
                    placeholder={String(defaults.refresh_ttl)}
                  />
                  <p className="text-xs text-muted-foreground">
                    {refreshTtl && Number(refreshTtl) >= 60
                      ? formatTtl(Number(refreshTtl))
                      : refreshTtl && Number(refreshTtl) > 0
                        ? t('projects.settings.jwt_ttl_min')
                        : t('projects.settings.jwt_refresh_ttl_hint')}
                  </p>
                </div>
              </div>

              {/* Validation rules */}
              {accessValue > 0 && refreshValue > 0 && refreshValue < accessValue && (
                <Alert variant="destructive">
                  <XCircle className="h-4 w-4" />
                  <AlertDescription>{t('projects.settings.jwt_refresh_must_be_gte_access')}</AlertDescription>
                </Alert>
              )}

              <div className="flex items-center gap-4">
                <Button type="submit" disabled={saving}>
                  {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                  {saving ? t('projects.settings.mailer.saving') : t('common.save')}
                </Button>
                <Button type="button" variant="outline" onClick={resetToDefaults} disabled={saving}>
                  {t('projects.settings.jwt_reset_defaults')}
                </Button>
              </div>
            </form>
          )}
        </div>
      </ProjectSettingsLayout>
    </AppLayout>
  )
}
