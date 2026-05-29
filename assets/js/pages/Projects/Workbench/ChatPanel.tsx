import { useStore } from '@nanostores/react';
import { useRef, useState, useEffect } from 'react';
import {
    messagesStore, statusStore, addMessage, appendToLastAssistantMessage, upsertFile, frameworkStore,
} from '@/stores/workbench';
import { MessageParser, consumeSseStream } from '@/lib/messageParser';
import { writeFile } from '@/lib/webcontainer';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Send, Loader2, FileCode2 } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

interface Props { projectUuid: string; frameworks: Array<{ id: string; label: string }>; }

export default function ChatPanel({ projectUuid }: Props) {
    const t = useTranslation();
    const messages = useStore(messagesStore);
    const status = useStore(statusStore);
    const framework = useStore(frameworkStore);
    const [input, setInput] = useState('');
    const bottomRef = useRef<HTMLDivElement>(null);
    const isGenerating = status === 'generating';

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    const handleSend = async () => {
        const prompt = input.trim();
        if (!prompt || isGenerating) return;
        setInput('');

        addMessage({ role: 'user', content: prompt });
        statusStore.set('generating');

        const parser = new MessageParser({
            onTextChunk: (text) => appendToLastAssistantMessage(text),
            onFileOpen: (path) => appendToLastAssistantMessage(`\n\n📄 **${path}**\n`),
            onFileChunk: () => {},
            onFileClose: (path, content) => {
                upsertFile(path, content);
                writeFile(path, content).catch(console.warn);
            },
            onDone: () => {
                const currentStatus = statusStore.get();
                // Only change status if WebContainer hasn't taken over
                if (currentStatus === 'generating') {
                    statusStore.set('idle');
                }
            },
            onError: (err) => {
                appendToLastAssistantMessage(`\n\n❌ Erreur: ${err}`);
                statusStore.set('error');
            },
        });

        try {
            const response = await fetch(`/api/projects/${projectUuid}/workbench/generate`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ prompt, framework }),
            });

            if (!response.ok) {
                const err = await response.json() as { error?: string };
                throw new Error(err.error ?? 'Erreur serveur');
            }

            addMessage({ role: 'assistant', content: '' });
            await consumeSseStream(response, parser);
        } catch (err: unknown) {
            const msg = err instanceof Error ? err.message : 'Erreur inconnue';
            appendToLastAssistantMessage('\n\n❌ ' + msg);
            statusStore.set('error');
        }
    };

    return (
        <div className="flex flex-col h-full bg-background border-r border-border">
            <div className="flex items-center justify-between px-4 py-2 border-b border-border">
                <span className="text-sm font-semibold">Chat IA</span>
                {isGenerating && (
                    <Badge variant="secondary" className="gap-1 text-xs">
                        <Loader2 className="w-3 h-3 animate-spin" />
                        {t('workbench.generating')}
                    </Badge>
                )}
            </div>

            <div className="flex-1 overflow-y-auto p-4 space-y-4">
                {messages.map(msg => (
                    <div key={msg.id} className={msg.role === 'user' ? 'flex justify-end' : ''}>
                        <div className={`max-w-[90%] rounded-lg px-3 py-2 text-sm whitespace-pre-wrap ${
                            msg.role === 'user' ? 'bg-primary text-primary-foreground' : 'bg-muted'
                        }`}>
                            <MessageContent content={msg.content} />
                        </div>
                    </div>
                ))}
                <div ref={bottomRef} />
            </div>

            <div className="p-3 border-t border-border">
                <div className="flex gap-2">
                    <Textarea
                        value={input}
                        onChange={e => setInput(e.target.value)}
                        onKeyDown={e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); handleSend(); } }}
                        placeholder={t('workbench.chat_placeholder')}
                        className="min-h-[60px] max-h-[120px] text-sm resize-none"
                        disabled={isGenerating}
                    />
                    <Button size="icon" onClick={handleSend} disabled={isGenerating || !input.trim()} className="shrink-0 self-end">
                        {isGenerating ? <Loader2 className="w-4 h-4 animate-spin" /> : <Send className="w-4 h-4" />}
                    </Button>
                </div>
            </div>
        </div>
    );
}

function MessageContent({ content }: { content: string }) {
    const parts = content.split(/(\*\*[^*]+\*\*)/g);
    return (
        <span>
            {parts.map((part, i) => {
                if (part.startsWith('**') && part.endsWith('**')) {
                    const path = part.slice(2, -2);
                    return (
                        <span key={i} className="inline-flex items-center gap-1 bg-background/50 border border-border rounded px-1.5 py-0.5 text-xs font-mono my-0.5">
                            <FileCode2 className="w-3 h-3 text-violet-400" />
                            {path}
                        </span>
                    );
                }
                return <span key={i}>{part}</span>;
            })}
        </span>
    );
}
