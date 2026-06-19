import { useState } from 'react';
import axios from 'axios';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Plus, Trash, Play, ArrowLeft, ArrowRight } from 'lucide-react';

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
    { value: 'content.created', label: 'Contenu créé', hasCollectionFilter: true },
    { value: 'content.updated', label: 'Contenu modifié', hasCollectionFilter: true },
    { value: 'content.deleted', label: 'Contenu supprimé', hasCollectionFilter: true },
    { value: 'content.status_changed', label: 'Statut changé', hasCollectionFilter: true },
    { value: 'schedule.cron', label: 'Planifié (cron)', hasCollectionFilter: false },
];

const OPERATORS = [
    { value: 'eq', label: 'égal' },
    { value: 'neq', label: 'différent' },
    { value: 'in', label: 'dans la liste' },
    { value: 'contains', label: 'contient' },
    { value: 'gt', label: '>' },
    { value: 'gte', label: '>=' },
    { value: 'lt', label: '<' },
    { value: 'lte', label: '<=' },
    { value: 'empty', label: 'est vide' },
    { value: 'notEmpty', label: "n'est pas vide" },
];

const ACTION_TYPES = [
    { value: 'send_email', label: 'Envoyer un email' },
    { value: 'call_webhook', label: 'Appeler un webhook' },
    { value: 'create_entry', label: 'Créer une entrée' },
    { value: 'update_entry', label: 'Modifier une entrée' },
    { value: 'send_notification', label: 'Envoyer une notification' },
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
    const [step, setStep] = useState(0);
    const [form, setForm] = useState<Automation>(automation || emptyAutomation);
    const [saving, setSaving] = useState(false);

    const update = (patch: Partial<Automation>) => setForm(f => ({ ...f, ...patch }));

    const handleSave = async () => {
        setSaving(true);
        try {
            if (automation?.id) {
                await axios.put(`/api/projects/${projectUuid}/automations/${automation.id}`, form);
                toast.success('Automatisation mise à jour');
            } else {
                await axios.post(`/api/projects/${projectUuid}/automations`, form);
                toast.success('Automatisation créée');
            }
            onSaved();
        } catch {
            toast.error("Erreur lors de l'enregistrement");
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
            toast.error('Test échoué');
        }
    };

    const isSchedule = form.trigger_type === 'schedule.cron';

    return (
        <Dialog open onOpenChange={() => onClose()} modal>
            <DialogContent className="sm:max-w-2xl max-h-[85vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{automation?.id ? 'Modifier' : 'Nouvelle'} automatisation</DialogTitle>
                </DialogHeader>

                <div className="flex items-center gap-2 mb-4">
                    {['Déclencheur', 'Conditions', 'Action', 'Résumé'].map((label, i) => (
                        <div key={i} className={`flex-1 h-1.5 rounded-full ${i <= step ? 'bg-primary' : 'bg-muted'}`} />
                    ))}
                </div>

                {/* Étape 1 : Déclencheur */}
                {step === 0 && (
                    <div className="space-y-4">
                        <div>
                            <Label>Nom de l'automatisation</Label>
                            <Input value={form.name} onChange={e => update({ name: e.target.value })} placeholder="Ex: Notifier publication" />
                        </div>
                        <div>
                            <Label>Type de déclencheur</Label>
                            <select
                                className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                value={form.trigger_type}
                                onChange={e => {
                                    update({ trigger_type: e.target.value, trigger_config: null });
                                }}
                            >
                                {TRIGGER_TYPES.map(t => (
                                    <option key={t.value} value={t.value}>{t.label}</option>
                                ))}
                            </select>
                        </div>
                        {!isSchedule && (
                            <div>
                                <Label>Filtrer par collections (optionnel)</Label>
                                <Input
                                    placeholder="articles, pages (slugs séparés par des virgules)"
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
                                <Label>Expression cron</Label>
                                <Input
                                    placeholder="0 9 * * * (tous les jours à 9h)"
                                    value={form.trigger_config?.schedule || ''}
                                    onChange={e => update({ trigger_config: { schedule: e.target.value } })}
                                />
                                <p className="text-xs text-muted-foreground mt-1">Minute Heure Jour Mois JourSemaine</p>
                            </div>
                        )}
                    </div>
                )}

                {/* Étape 2 : Conditions */}
                {step === 1 && (
                    <div className="space-y-3">
                        <p className="text-sm text-muted-foreground">Les conditions sont évaluées en ET. Toutes doivent être vraies.</p>
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
                                    {OPERATORS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
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
                            <Plus className="h-3.5 w-3.5 mr-1" /> Ajouter une condition
                        </Button>
                    </div>
                )}

                {/* Étape 3 : Action */}
                {step === 2 && (
                    <div className="space-y-4">
                        <div>
                            <Label>Type d'action</Label>
                            <select
                                className="w-full border rounded-md px-3 py-2 text-sm bg-background"
                                value={form.action_type}
                                onChange={e => update({ action_type: e.target.value, action_config: null })}
                            >
                                {ACTION_TYPES.map(a => <option key={a.value} value={a.value}>{a.label}</option>)}
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
                                        <Label>Méthode</Label>
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
                                <div><Label>UUID de l'entrée</Label><Input placeholder="{{ entry.uuid }}" value={form.action_config?.entry_uuid || ''} onChange={e => update({ action_config: { ...form.action_config, entry_uuid: e.target.value } })} /></div>
                                <div><Label>Champs à modifier</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[80px]" placeholder='{"status": "published"}' value={form.action_config?.fields ? JSON.stringify(form.action_config.fields, null, 2) : ''} onChange={e => { try { update({ action_config: { ...form.action_config, fields: JSON.parse(e.target.value) } }); } catch {} }} /></div>
                            </div>
                        )}

                        {form.action_type === 'send_notification' && (
                            <div className="space-y-3">
                                <div><Label>Titre</Label><Input placeholder="Automatisation exécutée" value={form.action_config?.title || ''} onChange={e => update({ action_config: { ...form.action_config, title: e.target.value } })} /></div>
                                <div><Label>Message</Label><textarea className="w-full border rounded-md px-3 py-2 text-sm bg-background min-h-[80px]" placeholder="{{ entry.title }} traité." value={form.action_config?.body || ''} onChange={e => update({ action_config: { ...form.action_config, body: e.target.value } })} /></div>
                            </div>
                        )}
                    </div>
                )}

                {/* Étape 4 : Résumé */}
                {step === 3 && (
                    <div className="space-y-3 text-sm">
                        <div className="border rounded-md p-3"><span className="font-medium">Nom :</span> {form.name || 'Sans nom'}</div>
                        <div className="border rounded-md p-3"><span className="font-medium">Déclencheur :</span> {TRIGGER_TYPES.find(t => t.value === form.trigger_type)?.label}</div>
                        <div className="border rounded-md p-3"><span className="font-medium">Conditions :</span> {form.conditions.length > 0 ? form.conditions.map(c => `${c.field} ${c.operator} ${c.value}`).join(' ET ') : 'Aucune'}</div>
                        <div className="border rounded-md p-3"><span className="font-medium">Action :</span> {ACTION_TYPES.find(a => a.value === form.action_type)?.label}</div>
                        <div className="flex items-center gap-4 mt-2">
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.is_active} onChange={e => update({ is_active: e.target.checked })} /> Actif</label>
                            <label className="flex items-center gap-2"><input type="checkbox" checked={form.debug_mode} onChange={e => update({ debug_mode: e.target.checked })} /> Mode debug</label>
                        </div>
                    </div>
                )}

                {/* Navigation */}
                <div className="flex items-center justify-between mt-4 pt-4 border-t">
                    <div className="flex gap-2">
                        {step > 0 && (
                            <Button variant="outline" size="sm" onClick={() => setStep(step - 1)}>
                                <ArrowLeft className="h-3.5 w-3.5 mr-1" /> Précédent
                            </Button>
                        )}
                        {step < 3 && (
                            <Button size="sm" onClick={() => setStep(step + 1)}>
                                Suivant <ArrowRight className="h-3.5 w-3.5 ml-1" />
                            </Button>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {automation?.id && (
                            <Button variant="outline" size="sm" onClick={handleTest}>
                                <Play className="h-3.5 w-3.5 mr-1" /> Tester
                            </Button>
                        )}
                        {step === 3 && (
                            <Button size="sm" onClick={handleSave} disabled={saving}>
                                {saving ? 'Enregistrement...' : 'Enregistrer'}
                            </Button>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
