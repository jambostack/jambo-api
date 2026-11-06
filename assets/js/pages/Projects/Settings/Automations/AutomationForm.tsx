import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Plus, Trash, Play, ArrowLeft, ArrowRight } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface Automation {
    id?: number;
    name: string;
    trigger_type: string;
    trigger_config: Record<string, any> | null;
    conditions: Array<{field: string; operator: string; value: any}>;
    action_type: string;
    action_config: Record<string, any> | null;
    is_active: boolean;
    debug_mode: boolean;
}

interface Props {
    projectUuid: string;
    automation: Automation | null;
    onClose: () => void;
    onSaved: () => void;
}

const TRIGGER_TYPES = [
    { value: 'content.created', labelKey: 'automations.trigger.content_created', hasCollectionFilter: true },
    { value: 'content.updated', labelKey: 'automations.trigger.content_updated', hasCollectionFilter: true },
    { value: 'content.deleted', labelKey: 'automations.trigger.content_deleted', hasCollectionFilter: true },
    { value: 'content.status_changed', labelKey: 'automations.trigger.status_changed', hasCollectionFilter: true },
    { value: 'schedule.cron', labelKey: 'automations.trigger.cron', hasCollectionFilter: false },
];

const OPERATORS = [
    { value: 'eq', labelKey: 'automations.operator.eq' },
    { value: 'neq', labelKey: 'automations.operator.neq' },
    { value: 'in', labelKey: 'automations.operator.in' },
    { value: 'contains', labelKey: 'automations.operator.contains' },
    { value: 'gt', label: '>' },
    { value: 'gte', label: '>=' },
    { value: 'lt', label: '<' },
    { value: 'lte', label: '<=' },
    { value: 'empty', labelKey: 'automations.operator.empty' },
    { value: 'notEmpty', labelKey: 'automations.operator.not_empty' },
];

const ACTION_TYPES = [
    { value: 'send_email', labelKey: 'automations.action.send_email' },
    { value: 'call_webhook', labelKey: 'automations.action.call_webhook' },
    { value: 'create_entry', labelKey: 'automations.action.create_entry' },
    { value: 'update_entry', labelKey: 'automations.action.update_entry' },
    { value: 'send_notification', labelKey: 'automations.action.send_notification' },
];

const emptyAutomation: Automation = {
    name: '',
    trigger_type: 'content.created',
    trigger_config: null,
    conditions: [],
    action_type: 'send_email',
    action_config: null,
    is_active: true,
    debug_mode: false,
};

export default function AutomationForm({ projectUuid, automation, onClose, onSaved }: Props) {
    const t = useTranslation();
    const [step, setStep] = useState(0);
    const [form, setForm] = useState<Automation>(automation || emptyAutomation);
    const [saving, setSaving] = useState(false);

    const update = (patch: Partial<Automation>) => setForm(f => ({ ...f, ...patch }));

    /** Construit un flowGraph minimal à partir du formulaire legacy */
    const buildFlowGraph = (): Record<string, any> => {
        const nodes: any[] = [];
        const edges: any[] = [];
        let prevId = '';

        // 1. Nœud trigger
        const triggerFullType = form.trigger_type === 'schedule.cron'
            ? 'trigger.schedule.cron'
            : `trigger.content.${form.trigger_type.replace('content.', '')}`;

        nodes.push({
            id: 'trigger_1',
            type: triggerFullType,
            data: { label: TRIGGER_TYPES.find(tObj => tObj.value === form.trigger_type) ? t(TRIGGER_TYPES.find(tObj => tObj.value === form.trigger_type)!.labelKey) : t('automations.step_trigger'), config: form.trigger_config || {} },
        });
        prevId = 'trigger_1';

        // 2. Nœuds de condition (en chaîne)
        form.conditions.forEach((cond, i) => {
            const condId = `condition_${i + 1}`;
            nodes.push({
                id: condId,
                type: 'logic.condition',
                data: { label: `${cond.field} ${cond.operator} ${cond.value}`, config: { field: cond.field, operator: cond.operator, value: cond.value } },
            });
            edges.push({
                id: `e_${prevId}_${condId}`,
                source: prevId,
                target: condId,
                sourceBranch: 'true',
            });
            prevId = condId;
        });

        // 3. Nœud action
        const actionFullType = `action.${form.action_type}`;
        nodes.push({
            id: 'action_1',
            type: actionFullType,
            data: { label: ACTION_TYPES.find(a => a.value === form.action_type)?.label || 'Action', config: form.action_config || {} },
        });
        edges.push({
            id: `e_${prevId}_action_1`,
            source: prevId,
            target: 'action_1',
            sourceBranch: prevId !== 'trigger_1' ? 'true' : undefined,
        });

        return { nodes, edges, variables: {} };
    };

    const handleSave = async () => {
        setSaving(true);
        try {
            const payload: Record<string, any> = {
                name: form.name,
                flow_graph: buildFlowGraph(),
                is_active: form.is_active,
                debug_mode: form.debug_mode,
            };
            if (automation?.id) {
                await axios.put(`/api/projects/${projectUuid}/automations/${automation.id}`, payload);
                toast.success(t('automations.updated_success'));
            } else {
                await axios.post(`/api/projects/${projectUuid}/automations`, payload);
                toast.success(t('automations.created_success'));
            }
            onSaved();
        } catch {
            toast.error(t('automations.save_error'));
        } finally {
            setSaving(false);
        }
    };

    const handleTest = async () => {
        if (!automation?.id) return;
        try {
            const r = await axios.post(`/api/projects/${projectUuid}/automations/${automation.id}/test`);
            const d = r.data;
            toast[d.conditions_pass ? 'success' : 'warning'](
                d.conditions_pass ? 'Conditions OK ✓' : 'Conditions échouées ✗',
                { description: `Résultat : ${JSON.stringify(d.condition_detail)}`, duration: 6000 }
            );
        } catch {
            toast.error(t('automations.test_failed'));
        }
    };

    const isSchedule = form.trigger_type === 'schedule.cron';

    return (
        <Dialog open onOpenChange={() => onClose()} modal>
            <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{automation?.id ? t('flow.edit_title') : t('flow.new_title')}</DialogTitle>
                </DialogHeader>

                <div className="flex items-center gap-2 mb-4">
                    {[t('automations.step_trigger'), t('automations.step_conditions'), t('automations.step_action'), t('automations.step_summary')].map((label, i) => (
                        <div key={i} className={`flex-1 h-1.5 rounded-full ${i <= step ? 'bg-primary' : 'bg-muted'}`} />
                    ))}
                </div>

                {/* Étape 1 : Déclencheur */}
                {step === 0 && (
                    <div className="space-y-4">
                        <div>
                            <Label>{t('common.name')}</Label>
                            <Input value={form.name} onChange={e => update({ name: e.target.value })} placeholder="Ex: Notifier publication" />
                        </div>
                        <div>
                            <Label>{t('automations.step_trigger')}</Label>
                            <select
                                className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                value={form.trigger_type}
                                onChange={e => {
                                    update({ trigger_type: e.target.value, trigger_config: null });
                                }}
                            >
                                {TRIGGER_TYPES.map(trig => (
                                    <option key={trig.value} value={trig.value}>{t(trig.labelKey)}</option>
                                ))}
                            </select>
                        </div>
                        {!isSchedule && (
                            <div>
                                <Label>Filtrer par collections (optionnel)</Label>
                                <Input
                                    placeholder={t('automations.collections_placeholder')}
                                    value={(form.trigger_config?.collection_slugs || []).join(', ')}
                                    onChange={e => update({
                                        trigger_config: {
                                            ...form.trigger_config,
                                            collection_slugs: e.target.value.split(',').map(s => s.trim()).filter(Boolean),
                                        }
                                    })}
                                />
                            </div>
                        )}
                        {isSchedule && (
                            <div>
                                <Label>{t('automations.cron_expression')}</Label>
                                <Input
                                    placeholder={t('automations.cron_placeholder')}
                                    value={form.trigger_config?.schedule || ''}
                                    onChange={e => update({ trigger_config: { schedule: e.target.value } })}
                                />
                                <p className="text-xs text-muted-foreground mt-1">{t('automations.cron_hint')}</p>
                            </div>
                        )}
                    </div>
                )}

                {/* Étape 2 : Conditions */}
                {step === 1 && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">{t('automations.conditions_help')}</p>
                        {form.conditions.map((cond, i) => (
                            <div key={i} className="flex items-center gap-2">
                                <Input
                                    className="flex-1"
                                    placeholder="Champ (ex: entry.status)"
                                    value={cond.field}
                                    onChange={e => {
                                        const c = [...form.conditions];
                                        c[i] = { ...c[i], field: e.target.value };
                                        update({ conditions: c });
                                    }}
                                />
                                <select
                                    className="border rounded-md px-2 py-1.5 text-sm bg-background w-32"
                                    value={cond.operator}
                                    onChange={e => {
                                        const c = [...form.conditions];
                                        c[i] = { ...c[i], operator: e.target.value };
                                        update({ conditions: c });
                                    }}
                                >
                                    {OPERATORS.map(o => <option key={o.value} value={o.value}>{o.labelKey ? t(o.labelKey) : o.label}</option>)}
                                </select>
                                <Input
                                    className="flex-1"
                                    placeholder="Valeur"
                                    value={typeof cond.value === 'string' ? cond.value : JSON.stringify(cond.value)}
                                    onChange={e => {
                                        const c = [...form.conditions];
                                        c[i] = { ...c[i], value: e.target.value };
                                        update({ conditions: c });
                                    }}
                                />
                                <Button variant="ghost" size="icon" className="h-8 w-8 text-destructive" onClick={() => {
                                    update({ conditions: form.conditions.filter((_, j) => j !== i) });
                                }}>
                                    <Trash className="h-3.5 w-3.5" />
                                </Button>
                            </div>
                        ))}
                        <Button variant="outline" size="sm" onClick={() => {
                            update({ conditions: [...form.conditions, { field: '', operator: 'eq', value: '' }] });
                        }}>
                            <Plus className="h-3.5 w-3.5 mr-1" /> {t('automations.add_condition')}
                        </Button>
                    </div>
                )}

                {/* Étape 3 : Action */}
                {step === 2 && (
                    <div className="space-y-4">
                        <div>
                            <Label>{t('automations.step_action')}</Label>
                            <select
                                className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                value={form.action_type}
                                onChange={e => update({ action_type: e.target.value, action_config: null })}
                            >
                                {ACTION_TYPES.map(a => <option key={a.value} value={a.value}>{t(a.labelKey)}</option>)}
                            </select>
                        </div>

                        {form.action_type === 'send_email' && (
                            <div className="space-y-3">
                                <div><Label>Destinataire (to)</Label><Input placeholder="{{ user.email }}" value={form.action_config?.to || ''} onChange={e => update({ action_config: { ...form.action_config, to: e.target.value } })} /></div>
                                <div><Label>Sujet</Label><Input placeholder="Notification : {{ entry.title }}" value={form.action_config?.subject || ''} onChange={e => update({ action_config: { ...form.action_config, subject: e.target.value } })} /></div>
                                <div><Label>Corps</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[100px]" placeholder="L'article {{ entry.title }} a été publié." value={form.action_config?.body || ''} onChange={e => update({ action_config: { ...form.action_config, body: e.target.value } })} /></div>
                            </div>
                        )}

                        {form.action_type === 'call_webhook' && (
                            <div className="space-y-3">
                                <div><Label>URL</Label><Input placeholder="https://example.com/hook" value={form.action_config?.url || ''} onChange={e => update({ action_config: { ...form.action_config, url: e.target.value } })} /></div>
                                <div className="flex gap-2">
                                    <div className="flex-1">
                                        <Label>{t('automations.method')}</Label>
                                        <select className="border rounded-md px-2 py-1.5 text-sm bg-background w-full" value={form.action_config?.method || 'POST'} onChange={e => update({ action_config: { ...form.action_config, method: e.target.value } })}>
                                            <option>POST</option><option>GET</option><option>PUT</option><option>PATCH</option>
                                        </select>
                                    </div>
                                </div>
                                <div><Label>Corps (JSON)</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[80px]" placeholder='{"title": "{{ entry.title }}"}' value={form.action_config?.body || ''} onChange={e => update({ action_config: { ...form.action_config, body: e.target.value } })} /></div>
                            </div>
                        )}

                        {form.action_type === 'create_entry' && (
                            <div className="space-y-3">
                                <div><Label>Collection (slug)</Label><Input placeholder="articles" value={form.action_config?.collection_slug || ''} onChange={e => update({ action_config: { ...form.action_config, collection_slug: e.target.value } })} /></div>
                                <div><Label>Champs</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[80px]" placeholder='{"title": "{{ entry.title }}", "status": "draft"}' value={form.action_config?.fields ? JSON.stringify(form.action_config.fields, null, 2) : ''} onChange={e => { try { update({ action_config: { ...form.action_config, fields: JSON.parse(e.target.value) } }); } catch {} }} /></div>
                            </div>
                        )}

                        {form.action_type === 'update_entry' && (
                            <div className="space-y-3">
                                <div><Label>{t('automations.entry_uuid')}</Label><Input placeholder="{{ entry.uuid }}" value={form.action_config?.entry_uuid || ''} onChange={e => update({ action_config: { ...form.action_config, entry_uuid: e.target.value } })} /></div>
                                <div><Label>{t('automations.fields_to_modify')}</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[80px]" placeholder='{"status": "published"}' value={form.action_config?.fields ? JSON.stringify(form.action_config.fields, null, 2) : ''} onChange={e => { try { update({ action_config: { ...form.action_config, fields: JSON.parse(e.target.value) } }); } catch {} }} /></div>
                            </div>
                        )}

                        {form.action_type === 'send_notification' && (
                            <div className="space-y-3">
                                <div><Label>{t('common.name')}</Label><Input placeholder={t('automations.action_title_placeholder')} value={form.action_config?.title || ''} onChange={e => update({ action_config: { ...form.action_config, title: e.target.value } })} /></div>
                                <div><Label>Message</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[80px]" placeholder="{{ entry.title }} traité." value={form.action_config?.body || ''} onChange={e => update({ action_config: { ...form.action_config, body: e.target.value } })} /></div>
                            </div>
                        )}
                    </div>
                )}

                {/* Étape 4 : Résumé */}
                {step === 3 && (
                    <div className="space-y-3 text-sm">
                        <div className="border rounded-md p-3"><span className="font-medium">{t('common.name')} :</span> {form.name || t('flow.unnamed')}</div>
                        <div className="border rounded-md p-3"><span className="font-medium">{t('automations.step_trigger')} :</span> {TRIGGER_TYPES.find(trig => trig.value === form.trigger_type) ? t(TRIGGER_TYPES.find(trig => trig.value === form.trigger_type)!.labelKey) : form.trigger_type}</div>
                        <div className="border rounded-md p-3"><span className="font-medium">{t('automations.step_conditions')} :</span> {form.conditions.length > 0 ? form.conditions.map(c => `${c.field} ${c.operator} ${c.value}`).join(' ET ') : 'Aucune'}</div>
                        <div className="border rounded-md p-3"><span className="font-medium">{t('automations.step_action')} :</span> {ACTION_TYPES.find(a => a.value === form.action_type) ? t(ACTION_TYPES.find(a => a.value === form.action_type)!.labelKey) : form.action_type}</div>
                        <div className="flex items-center gap-4 mt-2">
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={e => update({ is_active: e.target.checked })} /> {t('flow.active_label')}</label>
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.debug_mode} onChange={e => update({ debug_mode: e.target.checked })} /> {t('flow.debug_label')}</label>
                        </div>
                    </div>
                )}

                {/* Navigation */}
                <div className="flex items-center justify-between mt-4 pt-4 border-t">
                    <div className="flex gap-2">
                        {step > 0 && (
                            <Button variant="outline" size="sm" onClick={() => setStep(step - 1)}>
                                <ArrowLeft className="h-3.5 w-3.5 mr-1" /> {t('common.pagination_previous')}
                            </Button>
                        )}
                        {step < 3 && (
                            <Button size="sm" onClick={() => setStep(step + 1)}>
                                {t('common.pagination_next')} <ArrowRight className="h-3.5 w-3.5 ml-1" />
                            </Button>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {automation?.id && (
                            <Button variant="outline" size="sm" onClick={handleTest}>
                                <Play className="h-3.5 w-3.5 mr-1" /> {t('automations.test_btn')}
                            </Button>
                        )}
                        {step === 3 && (
                            <Button size="sm" onClick={handleSave} disabled={saving}>
                                {saving ? t('flow.saving_btn') : t('flow.save_btn')}
                            </Button>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
