import React, { useEffect, useState, useCallback } from 'react';
import { useTranslation } from '@/lib/i18n';
import CommentItem from './CommentItem';
import CommentForm from './CommentForm';

export default function CommentThread({ projectUuid, collectionSlug, entryId }: {
    projectUuid: string;
    collectionSlug: string;
    entryId: number;
}) {
    const t = useTranslation();
    const [comments, setComments] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);

    const baseUrl = `/api/projects/${projectUuid}/collections/${collectionSlug}/content/${entryId}/comments`;

    const fetchComments = useCallback(async () => {
        const res = await fetch(`${baseUrl}?per_page=50`);
        if (res.ok) { const json = await res.json(); setComments(json.data ?? []); }
        setLoading(false);
    }, [baseUrl]);

    useEffect(() => { fetchComments(); }, [fetchComments]);

    return (
        <div className="space-y-4">
            <h4 className="text-sm font-semibold">{t('comments.title')} ({comments.length})</h4>
            {loading ? <p className="text-xs text-muted-foreground">Loading...</p> : (
                <div className="space-y-4 max-h-96 overflow-y-auto">
                    {comments.map((c: any) => <CommentItem key={c.id} comment={c} baseUrl={baseUrl} onRefresh={fetchComments} />)}
                </div>
            )}
            <CommentForm baseUrl={baseUrl} onDone={fetchComments} />
        </div>
    );
}
