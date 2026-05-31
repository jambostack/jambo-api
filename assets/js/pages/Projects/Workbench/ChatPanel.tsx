import { useStore } from '@nanostores/react';
import { useRef, useState, useEffect, useCallback } from 'react';
import {
    messagesStore, statusStore, addMessage, startStreamingMessage, endStreamingMessage, appendToLastAssistantMessage, upsertFile, frameworkStore,
    selectedProviderStore, chatContextStore, filesStore, buildErrorsStore, autoFixStore,
    undoStackStore, recordUndoableChange, popUndoChange,
    flushPendingChunks,
} from '@/stores/workbench';
import { MessageParser, consumeSseStream } from '@/lib/messageParser';
import { writeFile, isWebContainerSupported } from '@/lib/webcontainer';
import { getPromptTemplates, enhancePromptWithSchema } from '@/lib/promptTemplates';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { toast } from 'sonner';
import { Send, Loader2, FileCode2, Square, Wand2, X, AlertTriangle, Undo2, Check, RotateCcw, Paperclip, Upload } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import { cn } from '@/lib/utils';

interface ImageAttachment {
    id: string;
    name: string;
    dataUrl: string;
    file: File;
    uploading: boolean;
    uploadedUrl?: string;
}

interface Props { projectUuid: string; workbenchUuid?: string; frameworks: Array<{ id: string; label: string }>; collections?: Array<{ name: string; slug: string; fields: unknown[] }>; }

export default function ChatPanel({ projectUuid, workbenchUuid, collections = [] }: Props) {
    const t = useTranslation();
    const messages = useStore(messagesStore);
    const status = useStore(statusStore);
    const framework = useStore(frameworkStore);
    const selectedProvider = useStore(selectedProviderStore);
    const context = useStore(chatContextStore);
    const buildErrors = useStore(buildErrorsStore);
    const autoFix = useStore(autoFixStore);
    const undoStack = useStore(undoStackStore);
    const [input, setInput] = useState('');
    const [attachments, setAttachments] = useState<ImageAttachment[]>([]);
    const [isDragOver, setIsDragOver] = useState(false);
    const bottomRef = useRef<HTMLDivElement>(null);
    const abortRef = useRef<AbortController | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const mountedRef = useRef(true);
    const savedCountRef = useRef(0); // nombre de messages déjà persistés côté serveur
    const isGenerating = status === 'generating';

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            abortRef.current?.abort();
            flushPendingChunks();
        };
    }, []);

    // Charger l'historique depuis l'API au montage
    useEffect(() => {
        if (!workbenchUuid) return;
        const controller = new AbortController();
        fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/messages`, {
            headers: { 'Content-Type': 'application/json' },
            signal: controller.signal,
        })
            .then(async res => {
                if (!res.ok) return;
                const d = await res.json() as { data?: Array<{ id: string; role: string; content: string; files?: string[]; timestamp: number }> };
                if (controller.signal.aborted || !mountedRef.current) return;
                if (d.data && d.data.length > 0) {
                    const mapped = d.data.map(m => ({
                        id: m.id,
                        role: m.role as 'user' | 'assistant',
                        content: m.content,
                        files: m.files,
                        timestamp: m.timestamp,
                    }));
                    const current = messagesStore.get();
                    if (current.length === 0) {
                        messagesStore.set(mapped);
                    }
                    savedCountRef.current = mapped.length;
                }
            })
            .catch(() => { /* silencieux — l'historique est optionnel */ });
        return () => controller.abort();
    }, [projectUuid, workbenchUuid]);

    // Persister uniquement les nouveaux messages (append-only, pas de suppression destructive)
    const saveHistory = useCallback(async () => {
        if (!workbenchUuid) return;
        const msgs = messagesStore.get();
        const newMsgs = msgs.slice(savedCountRef.current);
        if (newMsgs.length === 0) return;
        const payload = {
            messages: newMsgs.map(m => ({
                role: m.role,
                content: m.content,
                files: m.files ?? [],
            })),
        };
        try {
            await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/messages`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                signal: AbortSignal.timeout(10000),
            });
            savedCountRef.current = msgs.length;
        } catch {
            // Best-effort — ne pas bloquer l'UI
        }
    }, [projectUuid, workbenchUuid]);

    const handleStop = () => {
        abortRef.current?.abort();
        statusStore.set('idle');
    };

    const uploadImage = async (file: File): Promise<string | null> => {
        const formData = new FormData();
        formData.append('image', file);
        try {
            const res = await fetch(`/api/projects/${projectUuid}/workbench/upload`, {
                method: 'POST',
                body: formData,
                signal: AbortSignal.timeout(30000),
            });
            if (!res.ok) return null;
            const data = await res.json() as { data?: { url?: string } };
            return data?.data?.url ?? null;
        } catch {
            return null;
        }
    };

    const addFiles = async (filesArray: File[]) => {
        const imageFiles = filesArray.filter(f => f.type.startsWith('image/'));
        if (imageFiles.length === 0) return;

        const newAttachments: ImageAttachment[] = imageFiles.map(f => ({
            id: crypto.randomUUID?.() ?? Math.random().toString(36).slice(2),
            name: f.name,
            dataUrl: URL.createObjectURL(f),
            file: f,
            uploading: true,
        }));

        setAttachments(prev => [...prev, ...newAttachments]);

        for (const att of newAttachments) {
            const uploadedUrl = await uploadImage(att.file);
            setAttachments(prev => prev.map(a =>
                a.id === att.id ? { ...a, uploading: false, uploadedUrl } : a
            ));
            if (!uploadedUrl) {
                toast.error(t('common.error'));
            }
        }
    };

    const removeAttachment = (id: string) => {
        setAttachments(prev => {
            const att = prev.find(a => a.id === id);
            if (att?.dataUrl && att.dataUrl.startsWith('blob:')) {
                URL.revokeObjectURL(att.dataUrl);
            }
            return prev.filter(a => a.id !== id);
        });
    };

    const handleFileSelect = (e: React.ChangeEvent<HTMLInputElement>) => {
        const files = Array.from(e.target.files ?? []);
        addFiles(files);
        if (fileInputRef.current) fileInputRef.current.value = '';
    };

    const handlePaste = (e: React.ClipboardEvent) => {
        const items = e.clipboardData?.items;
        if (!items) return;
        const files: File[] = [];
        for (let i = 0; i < items.length; i++) {
            const blob = items[i].getAsFile();
            if (blob) files.push(blob);
        }
        if (files.length > 0) {
            e.preventDefault();
            addFiles(files);
        }
    };

    const handleDragOver = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragOver(true);
    };

    const handleDragLeave = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragOver(false);
    };

    const handleDrop = (e: React.DragEvent) => {
        e.preventDefault();
        e.stopPropagation();
        setIsDragOver(false);
        const files = Array.from(e.dataTransfer.files);
        addFiles(files);
    };

    const sendPrompt = useCallback(async (promptText: string, imageUrls: string[]) => {
        if (statusStore.get() === 'generating') return;
        statusStore.set('generating');
        chatContextStore.set(null);

        let fullPrompt = promptText;
        if (imageUrls.length > 0) {
            fullPrompt = `${promptText}\n\n[Images fournies : ${imageUrls.join(', ')}]`;
        }

        const parser = new MessageParser({
            onTextChunk: (text) => appendToLastAssistantMessage(text),
            onFileOpen: (path) => {
                appendToLastAssistantMessage(`\n\n📄 **${path}**\n`);
                const files = filesStore.get();
                const existing = files[path];
                if (existing) {
                    recordUndoableChange(path, existing.content, '');
                }
            },
            onFileChunk: () => {},
            onFileClose: (path, content) => {
                const files = filesStore.get();
                const old = files[path];
                const fullContent = content.trim();
                upsertFile(path, fullContent);
                if (old) {
                    const stack = undoStackStore.get();
                    const idx = stack.findIndex(s => s.path === path && s.before === old.content);
                    if (idx >= 0) {
                        const updated = [...stack];
                        updated[idx] = { ...updated[idx], after: fullContent };
                        undoStackStore.set(updated);
                    }
                }
                if (isWebContainerSupported()) {
                    writeFile(path, fullContent).catch(console.warn);
                }
            },
            onDone: () => {
                endStreamingMessage();
                const currentStatus = statusStore.get();
                if (currentStatus === 'generating') {
                    statusStore.set('idle');
                }
                saveHistory();
            },
            onError: (err) => {
                endStreamingMessage();
                appendToLastAssistantMessage(`\n\n❌ ${err}`);
                statusStore.set('error');
            },
        });

        const controller = new AbortController();
        abortRef.current = controller;

        try {
            const response = await fetchWithTimeout(
                `/api/projects/${projectUuid}/workbench/generate`,
                {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ prompt: fullPrompt, framework, provider: selectedProvider }),
                    signal: controller.signal,
                },
                300000,
            );

            if (!response.ok) {
                const err = await safeJson(response);
                throw new Error(err?.error ?? t('common.error'));
            }

            startStreamingMessage();
            await consumeSseStream(response, parser);
        } catch (err: unknown) {
            endStreamingMessage();
            if (err instanceof DOMException && err.name === 'AbortError') {
                if (mountedRef.current) {
                    appendToLastAssistantMessage(t('workbench.chat_interrupted'));
                    statusStore.set('idle');
                }
            } else {
                const msg = err instanceof Error ? err.message : t('common.error');
                if (mountedRef.current) {
                    appendToLastAssistantMessage('\n\n❌ ' + msg);
                    statusStore.set('error');
                }
            }
            saveHistory();
        } finally {
            abortRef.current = null;
        }
    }, [projectUuid, framework, selectedProvider, t, saveHistory]);

    const handleSend = async () => {
        const prompt = input.trim();
        if (!prompt && attachments.length === 0) return;
        if (statusStore.get() === 'generating') return;

        const uploadedUrls = attachments
            .filter(a => a.uploadedUrl)
            .map(a => a.uploadedUrl!);

        const hasPendingUploads = attachments.some(a => a.uploading);
        if (hasPendingUploads) {
            toast(t('workbench.upload_pending'));
            return;
        }

        setInput('');
        setAttachments([]);

        let enrichedPrompt = prompt || '';
        if (context) {
            const files = filesStore.get();
            const file = files[context.path];
            if (file) {
                enrichedPrompt = `Améliore le fichier \`${context.path}\`\n\nContenu actuel :\n\`\`\`\n${file.content}\n\`\`\`\n\nInstruction : ${prompt}`;
            }
        }
        enrichedPrompt = enhancePromptWithSchema(enrichedPrompt, collections);
        addMessage({ role: 'user', content: prompt || '[Image]' });
        await sendPrompt(enrichedPrompt, uploadedUrls);
    };

    const handleFixErrors = () => {
        const errors = buildErrorsStore.get();
        if (errors.length === 0) return;
        const errorText = errors.join('\n');
        const fixPrompt = `Corrige les erreurs de build suivantes :\n\`\`\`\n${errorText}\n\`\`\`\nNe régénère que les fichiers nécessaires.`;
        addMessage({ role: 'user', content: '🔧 Corriger les erreurs de build' });
        sendPrompt(enhancePromptWithSchema(fixPrompt, collections), []);
    };

    const handleUndo = () => {
        const change = popUndoChange();
        if (!change) return;
        upsertFile(change.path, change.before);
        if (isWebContainerSupported()) {
            writeFile(change.path, change.before).catch(console.warn);
        }
        const undoLabel = t('workbench.undo_file');
        appendToLastAssistantMessage(`\n\n${undoLabel} \`${change.path}\``);
    };

    const handleEnhancePrompt = () => {
        if (!input.trim()) return;
        setInput(prev => enhancePromptWithSchema(prev, collections));
    };

    const handleTemplateClick = (templateId: string) => {
        const templates = getPromptTemplates(t);
        const tmpl = templates.find(tmpl => tmpl.id === templateId);
        if (tmpl) {
            setInput(enhancePromptWithSchema(tmpl.prompt, collections));
        }
    };

    const templates = getPromptTemplates(t);

    return (
        <div
            className="flex flex-col h-full bg-background border-r border-border relative"
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
        >
            {isDragOver && (
                <div className="absolute inset-0 z-50 flex items-center justify-center bg-violet-500/20 backdrop-blur-sm border-2 border-dashed border-violet-400 rounded-lg pointer-events-none">
                    <div className="flex flex-col items-center gap-2 text-violet-400">
                        <Upload className="w-10 h-10" />
                        <p className="text-sm font-medium">{t('workbench.upload_drop')}</p>
                    </div>
                </div>
            )}

            <div className="flex items-center justify-between px-4 py-2 border-b border-border flex-wrap gap-1">
                <span className="text-sm font-semibold">{t('workbench.chat_title')}</span>
                <div className="flex items-center gap-2">
                    {undoStack.length > 0 && (
                        <button onClick={handleUndo} className="flex items-center gap-1 text-[10px] text-muted-foreground hover:text-amber-400 transition-colors" title={t('workbench.undo_hint')}>
                            <Undo2 className="w-3 h-3" />
                            {undoStack.length}
                        </button>
                    )}
                    <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
                        <Switch checked={autoFix} onCheckedChange={autoFixStore.set} className="h-4 w-7" />
                        <span className="hidden sm:inline">{t('workbench.autofix')}</span>
                    </div>
                    {isGenerating && (
                        <Badge variant="secondary" className="gap-1 text-xs">
                            <Loader2 className="w-3 h-3 animate-spin" />
                            {t('workbench.generating')}
                        </Badge>
                    )}
                </div>
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-4">
                {buildErrors.length > 0 && (
                    <div className="rounded-lg border border-amber-500/30 bg-amber-500/5 p-3 text-xs">
                        <div className="flex items-center justify-between mb-2">
                            <span className="flex items-center gap-1.5 text-amber-400 font-medium">
                                <AlertTriangle className="w-3.5 h-3.5" />
                                {buildErrors.length} {t('workbench.build_errors')}
                            </span>
                            <Button variant="outline" size="sm" className="h-6 text-[10px] gap-1" onClick={handleFixErrors} disabled={isGenerating}>
                                <Wand2 className="w-3 h-3" />
                                {t('workbench.fix_errors')}
                            </Button>
                        </div>
                        <div className="space-y-1 max-h-32 overflow-y-auto font-mono text-amber-300/80">
                            {buildErrors.slice(0, 5).map((e, i) => (
                                <div key={i} className="truncate">{e}</div>
                            ))}
                            {buildErrors.length > 5 && <div className="text-amber-500">...{buildErrors.length - 5} more</div>}
                        </div>
                    </div>
                )}
                {messages.length === 0 && attachments.length === 0 && (
                    <div className="text-center text-muted-foreground text-xs py-8">
                        <p>{t('workbench.chat_empty_title')}</p>
                        <p className="mt-1">{t('workbench.chat_empty_subtitle')}</p>
                    </div>
                )}
                {messages.map(msg => (
                    <div key={msg.id} className={msg.role === 'user' ? 'flex justify-end' : ''}>
                        <div className={cn('max-w-[90%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap', msg.role === 'user' ? 'bg-primary text-primary-foreground' : 'bg-muted')}>
                            <MessageContent content={msg.content} onApplyFile={(path) => {
                                const files = filesStore.get();
                                const file = files[path];
                                if (file) {
                                    recordUndoableChange(path, '', file.content);
                                    if (isWebContainerSupported()) writeFile(path, file.content).catch(console.warn);
                                }
                            }} onRejectFile={(path) => {
                                const undoChange = undoStackStore.get().find(s => s.path === path && s.after !== '');
                                const beforeContent = undoChange?.before ?? '';
                                upsertFile(path, beforeContent);
                                if (isWebContainerSupported()) {
                                    writeFile(path, beforeContent).catch(console.warn);
                                }
                            }} />
                        </div>
                    </div>
                ))}
                <div ref={bottomRef} />
            </div>

            {context && (
                <div className="flex items-center gap-2 px-3 py-1.5 border-t border-border bg-muted/30 text-xs">
                    <FileCode2 className="w-3.5 h-3.5 text-violet-400" />
                    <span className="font-mono truncate flex-1">{t('workbench.chat_target_file')} {context.path}</span>
                    <button onClick={() => chatContextStore.set(null)} className="p-0.5 hover:bg-muted rounded">
                        <X className="w-3 h-3" />
                    </button>
                </div>
            )}

            {attachments.length > 0 && (
                <div className="flex gap-2 px-3 py-2 border-t border-border bg-muted/20 overflow-x-auto">
                    {attachments.map(att => (
                        <div key={att.id} className="relative shrink-0 w-16 h-16 rounded-md overflow-hidden border border-border group">
                            <img src={att.dataUrl} alt={att.name} className="w-full h-full object-cover" />
                            {att.uploading && (
                                <div className="absolute inset-0 bg-black/50 flex items-center justify-center">
                                    <Loader2 className="w-4 h-4 animate-spin text-white" />
                                </div>
                            )}
                            {att.uploadedUrl && (
                                <div className="absolute bottom-0 right-0 p-0.5 bg-emerald-500 rounded-tl">
                                    <Check className="w-2.5 h-2.5 text-white" />
                                </div>
                            )}
                            <button
                                onClick={() => removeAttachment(att.id)}
                                className="absolute top-0 right-0 p-0.5 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity"
                            >
                                <X className="w-3 h-3 text-white" />
                            </button>
                        </div>
                    ))}
                </div>
            )}

            <div className="px-3 pb-1">
                <div className="flex flex-wrap gap-1 py-1.5">
                    {templates.slice(0, 4).map(tmpl => (
                        <button key={tmpl.id} onClick={() => handleTemplateClick(tmpl.id)} disabled={isGenerating}
                            className="text-[10px] px-2 py-0.5 rounded-full border border-border bg-muted/30 text-muted-foreground hover:bg-muted hover:text-foreground transition-colors disabled:opacity-50">
                            {tmpl.label}
                        </button>
                    ))}
                </div>
            </div>

            <div className="p-3 border-t border-border">
                <div className="flex gap-2">
                    <div className="flex-1 relative">
                        <Textarea value={input} onChange={e => setInput(e.target.value)}
                            onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); } }}
                            onPaste={handlePaste}
                            placeholder={t('workbench.chat_placeholder')}
                            className="min-h-[60px] max-h-[120px] text-sm resize-none"
                            disabled={isGenerating}
                        />
                        {input.trim().length > 0 && (
                            <button onClick={handleEnhancePrompt} disabled={isGenerating}
                                className="absolute right-2 top-2 p-1 text-muted-foreground hover:text-violet-400 transition-colors disabled:opacity-50"
                                title={t('workbench.chat_enhance')}>
                                <Wand2 className="w-3.5 h-3.5" />
                            </button>
                        )}
                    </div>
                    <input
                        ref={fileInputRef}
                        type="file"
                        accept="image/*"
                        multiple
                        className="hidden"
                        onChange={handleFileSelect}
                    />
                    <Button size="icon" variant="ghost" className="shrink-0 self-end" onClick={() => fileInputRef.current?.click()} disabled={isGenerating} title={t('workbench.upload_title')}>
                        <Paperclip className="w-4 h-4" />
                    </Button>
                    {isGenerating ? (
                        <Button size="icon" variant="destructive" onClick={handleStop} className="shrink-0 self-end" aria-label={t('workbench.chat_stop')}>
                            <Square className="w-4 h-4" />
                        </Button>
                    ) : (
                        <Button size="icon" onClick={handleSend} disabled={!input.trim() && attachments.length === 0} className="shrink-0 self-end">
                            <Send className="w-4 h-4" />
                        </Button>
                    )}
                </div>
            </div>
        </div>
    );
}

function MessageContent({ content, onApplyFile, onRejectFile }: { content: string; onApplyFile: (path: string) => void; onRejectFile: (path: string) => void }) {
    const parts = content.split(/(\*\*[^*]+\*\*)/g);
    const t = useTranslation();
    return (
        <span>
            {parts.map((part, i) => {
                if (part.startsWith('**') && part.endsWith('**')) {
                    const path = part.slice(2, -2);
                    return (
                        <span key={i} className="inline-flex items-center gap-1 bg-background/50 border border-border rounded px-1.5 py-0.5 text-xs font-mono my-0.5 group">
                            <FileCode2 className="w-3 h-3 text-violet-400" />
                            {path}
                            <button onClick={() => onApplyFile(path)} className="opacity-0 group-hover:opacity-100 ml-0.5 p-0.5 hover:bg-muted rounded" title={t('workbench.file_apply')}>
                                <Check className="w-3 h-3 text-emerald-400" />
                            </button>
                            <button onClick={() => onRejectFile(path)} className="opacity-0 group-hover:opacity-100 p-0.5 hover:bg-muted rounded" title={t('workbench.file_reject')}>
                                <RotateCcw className="w-3 h-3 text-red-400" />
                            </button>
                        </span>
                    );
                }
                return <span key={i}>{part}</span>;
            })}
        </span>
    );
}

async function fetchWithTimeout(url: string, options: RequestInit & { timeout?: number } = {}, timeout: number = 60000): Promise<Response> {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), timeout);
    const originalSignal = options.signal;
    if (originalSignal) {
        originalSignal.addEventListener('abort', () => controller.abort());
    }
    try {
        return await fetch(url, { ...options, signal: controller.signal });
    } finally {
        clearTimeout(timeoutId);
    }
}

async function safeJson(res: Response): Promise<{ error?: string } | null> {
    try { const text = await res.text(); return JSON.parse(text); } catch { return null; }
}
