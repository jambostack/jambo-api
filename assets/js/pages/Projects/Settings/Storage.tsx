import { Head } from '@inertiajs/react'
import { useState, useEffect } from 'react'
import axios from 'axios'
import type { Project, BreadcrumbItem } from '@/types/index.d'
import AppLayout from '@/layouts/app-layout'
import ProjectSettingsLayout from './layout'
import HeadingSmall from '@/components/heading-small'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent } from '@/components/ui/card'
import { CheckCircle, XCircle, Loader2, Plus, Pencil, Trash2, HardDrive, Cloud, ArrowRight } from 'lucide-react'
import { useTranslation } from '@/lib/i18n'
import StorageProfileForm from './StorageProfileForm'
import StorageRuleForm from './StorageRuleForm'

interface Props { project: Project }

interface StorageProfile {
  id: number; uuid: string; name: string; driver: string
  priority: number; enabled: boolean; is_default: boolean
  s3_key?: string; s3_region?: string; s3_bucket?: string
  s3_endpoint?: string; s3_use_path_style: boolean
  base_url?: string; root_path?: string
}

interface StorageRule {
  id: number; profile_uuid: string
  mime_type_pattern?: string; extension?: string; max_size?: number
  priority: number
}

export default function StorageSettingsPage({ project }: Props) {
  const t = useTranslation()

  const [strategy, setStrategy] = useState('default_only')
  const [profiles, setProfiles] = useState<StorageProfile[]>([])
  const [rules, setRules] = useState<StorageRule[]>([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [toast, setToast] = useState<{ type: 'ok' | 'err'; msg: string } | null>(null)

  const [profileFormOpen, setProfileFormOpen] = useState(false)
  const [editingProfile, setEditingProfile] = useState<StorageProfile | null>(null)
  const [ruleFormOpen, setRuleFormOpen] = useState(false)
  const [editingRule, setEditingRule] = useState<StorageRule | null>(null)

  const breadcrumbs: BreadcrumbItem[] = [
    { title: project.name, href: route('projects.show', project.id) },
    { title: t('projects.settings.title'), href: route('projects.settings.project', project.id) },
    { title: t('projects.settings.nav_storage'), href: route('projects.settings.storage', project.id) },
  ]

  useEffect(() => { fetchConfig() }, [project.uuid])

  async function fetchConfig() {
    try {
      const r = await axios.get(`/api/admin/projects/${project.uuid}/storage`)
      setStrategy(r.data.strategy)
      setProfiles(r.data.profiles)
      setRules(r.data.rules)
    } catch { /* */ }
    finally { setLoading(false) }
  }

  async function saveStrategy() {
    setSaving(true)
    try {
      await axios.put(`/api/admin/projects/${project.uuid}/storage`, { strategy })
      showToast('ok', t('projects.settings.storage.saved'))
    } catch (e: any) {
      showToast('err', e.response?.data?.error || t('common.error'))
    } finally { setSaving(false) }
  }

  function showToast(type: 'ok' | 'err', msg: string) {
    setToast({ type, msg }); setTimeout(() => setToast(null), 4000)
  }

  function openNewProfile() { setEditingProfile(null); setProfileFormOpen(true) }
  function openEditProfile(p: StorageProfile) { setEditingProfile(p); setProfileFormOpen(true) }

  async function deleteProfile(id: number) {
    try {
      await axios.delete(`/api/admin/projects/${project.uuid}/storage/profiles/${id}`)
      fetchConfig()
    } catch (e: any) {
      showToast('err', e.response?.data?.error || t('common.error'))
    }
  }

  function openNewRule() { setEditingRule(null); setRuleFormOpen(true) }
  function openEditRule(r: StorageRule) { setEditingRule(r); setRuleFormOpen(true) }

  async function deleteRule(id: number) {
    try {
      await axios.delete(`/api/admin/projects/${project.uuid}/storage/rules/${id}`)
      fetchConfig()
    } catch (e: any) {
      showToast('err', e.response?.data?.error || t('common.error'))
    }
  }

  const driverIcon = (d: string) => d === 'local'
    ? <HardDrive className="h-4 w-4" />
    : <Cloud className="h-4 w-4" />
  const driverLabel = (d: string) => d === 'local'
    ? t('projects.settings.storage.driver_local')
    : t('projects.settings.storage.driver_s3')

  return (
    <AppLayout breadcrumbs={breadcrumbs}>
      <Head title={t('projects.settings.storage.title')} />
      <ProjectSettingsLayout project={project}>
        <div className="space-y-6 max-w-2xl">
          <HeadingSmall title={t('projects.settings.storage.title')} description={t('projects.settings.storage.desc')} />

          {toast && (
            <Alert variant={toast.type === 'ok' ? 'default' : 'destructive'}>
              {toast.type === 'ok' ? <CheckCircle className="h-4 w-4" /> : <XCircle className="h-4 w-4" />}
              <AlertDescription>{toast.msg}</AlertDescription>
            </Alert>
          )}

          {loading && <p className="text-sm text-muted-foreground">{t('common.loading')}</p>}

          {!loading && (
            <div className="space-y-8">
              <div className="space-y-3">
                <Label className="text-base font-medium">{t('projects.settings.storage.strategy')}</Label>
                <Select value={strategy} onValueChange={v => setStrategy(v)}>
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="default_only">{t('projects.settings.storage.strategy_default')}</SelectItem>
                    <SelectItem value="mirror_all">{t('projects.settings.storage.strategy_mirror')}</SelectItem>
                    <SelectItem value="rules">{t('projects.settings.storage.strategy_rules')}</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-3">
                <div className="flex items-center justify-between">
                  <Label className="text-base font-medium">{t('projects.settings.storage.profiles')}</Label>
                  <Button size="sm" onClick={openNewProfile}>
                    <Plus className="mr-1 h-4 w-4" /> {t('projects.settings.storage.add_profile')}
                  </Button>
                </div>
                {profiles.map(p => (
                  <Card key={p.id}>
                    <CardContent className="flex items-center justify-between p-4">
                      <div className="flex items-center gap-3">
                        {driverIcon(p.driver)}
                        <div>
                          <p className="font-medium text-sm">{p.name}</p>
                          <p className="text-xs text-muted-foreground">
                            {driverLabel(p.driver)}
                            {p.is_default && ' · default'}
                            {p.s3_bucket && ' · ' + p.s3_bucket}
                          </p>
                        </div>
                      </div>
                      <div className="flex items-center gap-2">
                        {p.enabled && <Badge variant="outline" className="text-xs">on</Badge>}
                        <Button size="icon" variant="ghost" onClick={() => openEditProfile(p)}>
                          <Pencil className="h-3.5 w-3.5" />
                        </Button>
                        <Button size="icon" variant="ghost" onClick={() => deleteProfile(p.id)}>
                          <Trash2 className="h-3.5 w-3.5" />
                        </Button>
                      </div>
                    </CardContent>
                  </Card>
                ))}
              </div>

              {strategy === 'rules' && (
                <div className="space-y-3">
                  <div className="flex items-center justify-between">
                    <Label className="text-base font-medium">{t('projects.settings.storage.rules')}</Label>
                    <Button size="sm" onClick={openNewRule}>
                      <Plus className="mr-1 h-4 w-4" /> {t('projects.settings.storage.add_rule')}
                    </Button>
                  </div>
                  {rules.length === 0 && (
                    <p className="text-sm text-muted-foreground">{t('projects.settings.storage.no_rules')}</p>
                  )}
                  {rules.map(r => (
                    <Card key={r.id}>
                      <CardContent className="flex items-center justify-between p-4">
                        <div className="flex items-center gap-2">
                          <span className="text-sm font-medium">{r.mime_type_pattern || r.extension || '*'}</span>
                          <ArrowRight className="h-3 w-3 text-muted-foreground" />
                          <span className="text-sm text-muted-foreground">
                            {profiles.find(p => p.uuid === r.profile_uuid)?.name || r.profile_uuid}
                          </span>
                        </div>
                        <div className="flex items-center gap-1">
                          <Button size="icon" variant="ghost" onClick={() => openEditRule(r)}>
                            <Pencil className="h-3.5 w-3.5" />
                          </Button>
                          <Button size="icon" variant="ghost" onClick={() => deleteRule(r.id)}>
                            <Trash2 className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                      </CardContent>
                    </Card>
                  ))}
                </div>
              )}

              <Button onClick={saveStrategy} disabled={saving}>
                {saving && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
                {saving ? t('projects.settings.mailer.saving') : t('common.save')}
              </Button>
            </div>
          )}
        </div>

        <StorageProfileForm
          open={profileFormOpen}
          onClose={() => setProfileFormOpen(false)}
          onSaved={() => { setProfileFormOpen(false); fetchConfig() }}
          projectUuid={project.uuid}
          editProfile={editingProfile}
        />
        <StorageRuleForm
          open={ruleFormOpen}
          onClose={() => setRuleFormOpen(false)}
          onSaved={() => { setRuleFormOpen(false); fetchConfig() }}
          projectUuid={project.uuid}
          profiles={profiles}
          editRule={editingRule}
        />
      </ProjectSettingsLayout>
    </AppLayout>
  )
}
