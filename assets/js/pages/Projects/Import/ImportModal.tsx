import { useState, useCallback } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { useTranslation } from '@/lib/i18n';
import { useDropzone } from 'react-dropzone';
import { router } from '@inertiajs/react';
import axios from 'axios';
import { toast } from 'sonner';

interface ProjectOption {
    uuid: string;
    name: string;
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projects?: ProjectOption[];
}

interface ConflictItem {
    entity_type: string;
    entity_name: string;
    entity_uuid: string;
    existing_uuid: string;
    suggested_action: string;
    chosen_action: string;
}

export default function ImportModal({ open, onOpenChange, projects = [] }: Props) {
    const t = useTranslation();
    const [file, setFile] = useState<File | null>(null);
    const [mode, setMode] = useState<'new' | 'merge'>('new');
    const [projectName, setProjectName] = useState('');
    const [targetProjectUuid, setTargetProjectUuid] = useState('');
    const [strategy, setStrategy] = useState('skip');
    const [step, setStep] = useState<'idle' | 'analyzed' | 'importing'>('idle');
    const [conflicts, setConflicts] = useState<ConflictItem[]>([]);
    const [manifest, setManifest] = useState<any>(null);
    const [processing, setProcessing] = useState(false);

    const analyzeFile = useCallback(async (f: File) => {
        setProcessing(true);
        try {
            const formData = new FormData();
            formData.append('file', f);
            const { data } = await axios.post('/api/projects/import/preview', formData);
            setManifest(data.data.manifest);
            setConflicts(data.data.conflicts || []);
            // Pré-remplit le nom avec celui du projet source
            const sourceName = data.data.manifest?.project?.name;
            if (sourceName) {
                setProjectName(sourceName);
            }
            setStep('analyzed');
        } catch (e) {
            console.error(e);
            toast.error(t('projects.import.preview_error'));
        } finally {
            setProcessing(false);
        }
    }, [t]);

    const onDrop = useCallback((acceptedFiles: File[]) => {
        if (acceptedFiles.length > 0) {
            const f = acceptedFiles[0];
            setFile(f);
            // Auto-analyze on drop
            analyzeFile(f);
        }
    }, [analyzeFile]);

    const { getRootProps, getInputProps, isDragActive } = useDropzone({
        onDrop,
        accept: { 'application/zip': ['.zip'] },
        maxFiles: 1,
    });

    const handleImport = async () => {
        if (!file) return;

        setProcessing(true);
        setStep('importing');
        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('strategy', strategy);
            formData.append('create_new_project', mode === 'new' ? '1' : '0');
            if (mode === 'new') {
                formData.append('new_project_name', projectName || manifest?.project?.name || 'Imported');
            }

            const url = mode === 'new'
                ? '/api/projects/import'
                : `/api/projects/${targetProjectUuid}/import/merge`;

            const { data } = await axios.post(url, formData);
            toast.success(t('projects.import.success'));
            onOpenChange(false);
            router.visit(`/projects/${data.data.id}`);
        } catch (e) {
            console.error(e);
            toast.error(t('projects.import.error'));
        } finally {
            setProcessing(false);
        }
    };

    const canImport = !!file && !processing
        && (mode === 'merge' ? !!targetProjectUuid : !!projectName);

    const resetForm = () => {
        setFile(null);
        setMode('new');
        setProjectName('');
        setTargetProjectUuid('');
        setStrategy('skip');
        setStep('idle');
        setConflicts([]);
        setManifest(null);
        setProcessing(false);
    };

    const handleOpenChange = (open: boolean) => {
        if (!open) resetForm();
        onOpenChange(open);
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent className="max-w-lg">
                <DialogHeader>
                    <DialogTitle>{t('projects.import.title')}</DialogTitle>
                    <DialogDescription className="sr-only">{t('projects.import.title')}</DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-4">
                    {/* Drop zone — always visible until analyzed */}
                    {step === 'idle' && (
                        <div {...getRootProps()} className="border-2 border-dashed rounded-lg p-8 text-center cursor-pointer hover:bg-muted/50 transition-colors">
                            <input {...getInputProps()} />
                            {isDragActive
                                ? <p>{t('projects.import.drop_here')}</p>
                                : <p>{file ? file.name : t('projects.import.drop_zone')}</p>
                            }
                        </div>
                    )}

                    {/* Processing indicator */}
                    {processing && step === 'idle' && (
                        <p className="text-center text-sm text-muted-foreground">{t('common.analyzing')}</p>
                    )}

                    {/* After analysis: show manifest summary + changed file button */}
                    {step === 'analyzed' && manifest && (
                        <div className="rounded-md border bg-muted/30 p-3 text-sm space-y-1">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('projects.import.source_project')}</span>
                                <span className="font-medium">{manifest.project?.name}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">{t('projects.import.contain')}</span>
                                <span>{(manifest.included || []).join(', ')}</span>
                            </div>
                            {manifest.sections && Object.keys(manifest.sections).length > 0 && (
                                <div className="flex justify-between">
                                    <span className="text-muted-foreground">{t('projects.import.counts')}</span>
                                    <span>
                                        {Object.entries(manifest.sections).map(([k, v]: [string, any]) => (
                                            <span key={k} className="ml-1.5">{v.entityCount} {k}</span>
                                        ))}
                                    </span>
                                </div>
                            )}
                            <button
                                type="button"
                                onClick={() => { setFile(null); setStep('idle'); setManifest(null); }}
                                className="text-xs text-primary hover:underline mt-1"
                            >
                                {t('projects.import.change_file')}
                            </button>
                        </div>
                    )}

                    {/* Mode selector — visible after analysis */}
                    {step === 'analyzed' && (
                        <>
                            <RadioGroup value={mode} onValueChange={(v) => setMode(v as 'new' | 'merge')}>
                                <div className="flex items-center space-x-2">
                                    <RadioGroupItem value="new" id="mode-new" />
                                    <Label htmlFor="mode-new">{t('projects.import.new_project')}</Label>
                                </div>
                                <div className="flex items-center space-x-2">
                                    <RadioGroupItem value="merge" id="mode-merge" />
                                    <Label htmlFor="mode-merge">{t('projects.import.merge')}</Label>
                                </div>
                            </RadioGroup>

                            {mode === 'new' && (
                                <Input
                                    placeholder={t('projects.import.project_name')}
                                    value={projectName}
                                    onChange={(e) => setProjectName(e.target.value)}
                                />
                            )}

                            {mode === 'merge' && projects.length > 0 && (
                                <ProjectSelect
                                    projects={projects}
                                    value={targetProjectUuid}
                                    onChange={setTargetProjectUuid}
                                    placeholder={t('projects.import.select_project')}
                                />
                            )}

                            {mode === 'merge' && projects.length === 0 && (
                                <Input
                                    placeholder={t('projects.import.target_uuid')}
                                    value={targetProjectUuid}
                                    onChange={(e) => setTargetProjectUuid(e.target.value)}
                                />
                            )}

                            {conflicts.length > 0 && (
                                <>
                                    <h4 className="font-medium">{t('projects.import.conflicts_detected')}</h4>
                                    <RadioGroup value={strategy} onValueChange={setStrategy}>
                                        <div className="flex items-center space-x-2">
                                            <RadioGroupItem value="skip" id="strat-skip" />
                                            <Label htmlFor="strat-skip">{t('projects.import.strategy_skip')}</Label>
                                        </div>
                                        <div className="flex items-center space-x-2">
                                            <RadioGroupItem value="overwrite" id="strat-overwrite" />
                                            <Label htmlFor="strat-overwrite">{t('projects.import.strategy_overwrite')}</Label>
                                        </div>
                                        <div className="flex items-center space-x-2">
                                            <RadioGroupItem value="new_uuids" id="strat-new" />
                                            <Label htmlFor="strat-new">{t('projects.import.strategy_new_uuids')}</Label>
                                        </div>
                                    </RadioGroup>
                                    <div className="max-h-48 overflow-y-auto border rounded">
                                        {conflicts.map((c, i) => (
                                            <div key={i} className="flex justify-between px-3 py-1.5 border-b last:border-0 text-sm">
                                                <span className="font-mono text-xs bg-muted px-1 rounded">{c.entity_type}</span>
                                                <span>{c.entity_name}</span>
                                                <span className="text-muted-foreground">{strategy}</span>
                                            </div>
                                        ))}
                                    </div>
                                </>
                            )}

                            {conflicts.length === 0 && (
                                <p className="text-center text-sm text-muted-foreground">
                                    {t('projects.import.no_conflicts')}
                                </p>
                            )}
                        </>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => handleOpenChange(false)} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    {step === 'analyzed' && (
                        <Button onClick={handleImport} disabled={!canImport}>
                            {processing ? t('common.importing') : t('projects.import.button')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ProjectSelect({
    projects,
    value,
    onChange,
    placeholder,
}: {
    projects: { uuid: string; name: string }[];
    value: string;
    onChange: (v: string) => void;
    placeholder: string;
}) {
    const [open, setOpen] = useState(false);
    const selected = projects.find((p) => p.uuid === value);

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                className="border-input bg-background text-foreground flex h-9 w-full items-center rounded-md border px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] outline-none"
            >
                <span className={selected ? '' : 'text-muted-foreground'}>
                    {selected ? selected.name : placeholder}
                </span>
                <svg className="ml-auto h-4 w-4 opacity-50" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                    <path d="M6 9l6 6 6-6" />
                </svg>
            </button>
            {open && (
                <>
                    <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
                    <div className="absolute left-0 right-0 top-full z-20 mt-1 overflow-hidden rounded-md border bg-popover text-popover-foreground shadow-md">
                        <div className="max-h-48 overflow-y-auto p-1">
                            {projects.map((p) => (
                                <button
                                    key={p.uuid}
                                    type="button"
                                    onClick={() => {
                                        onChange(p.uuid);
                                        setOpen(false);
                                    }}
                                    className={`w-full rounded-sm px-3 py-1.5 text-left text-sm hover:bg-accent hover:text-accent-foreground ${
                                        p.uuid === value ? 'bg-accent text-accent-foreground' : ''
                                    }`}
                                >
                                    {p.name}
                                </button>
                            ))}
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
