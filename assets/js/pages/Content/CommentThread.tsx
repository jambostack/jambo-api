import React, { useEffect, useState, useCallback } from 'react';
import { useTranslation } from '@/lib/i18n';
import CommentItem from './CommentItem';
import CommentForm from './CommentForm';

export default function CommentThread({ entryId }: { entryId: number }) {
    const t = useTranslation();
    const [comments, setComments] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);

    const fetchComments = useCallback(async () => {
        const res = await fetch(`/api/comments/${entryId}?per_page=50`);
        if (res.ok) { const json = await res.json(); setComments(json.data ?? []); }
        setLoading(false);
    }, [entryId]);

    useEffect(() => { fetchComments(); }, [fetchComments]);

    return (
        <div className="space-y-4">
            <h4 className="text-sm font-semibold">{t('comments.title')} ({comments.length})</h4>
            {loading ? <p className="text-xs text-muted-foreground">Loading...</p> : (
                <div className="space-y-4 max-h-96 overflow-y-auto">
                    {comments.map((c: any) => <CommentItem key={c.id} comment={c} entryId={entryId} onRefresh={fetchComments} />)}
                </div>
            )}
            <CommentForm entryId={entryId} onDone={fetchComments} />
        </div>
    );
}
