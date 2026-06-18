import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Send } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

export default function CommentForm({ baseUrl, parentId, onDone }: { baseUrl: string; parentId?: number; onDone: () => void }) {
    const t = useTranslation();
    const [body, setBody] = useState('');
    const [sending, setSending] = useState(false);

    const submit = async () => {
        if (!body.trim()) return;
        setSending(true);
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
        await fetch(baseUrl, {
            method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ body, parent_id: parentId ?? null }),
        });
        setBody(''); setSending(false); onDone();
    };

    return (
        <div className="flex gap-2">
            <Textarea value={body} onChange={e => setBody(e.target.value)} disabled={sending}
                placeholder={t('comments.write_placeholder')} rows={2} className="text-sm flex-1" />
            <Button type="button" size="sm" onClick={submit} disabled={sending} className="shrink-0">
                <Send className="h-4 w-4" />
            </Button>
        </div>
    );
}
