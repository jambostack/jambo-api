import React, { useState } from 'react';
import { Button } from '@/components/ui/button';
import { MessageCircle, CheckCircle2 } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';
import CommentForm from './CommentForm';
import moment from 'moment';

function formatRelative(date: string): string {
    return moment(date).fromNow();
}

export default function CommentItem({ comment, entryId, onRefresh }: {
    comment: any; entryId: number; onRefresh: () => void;
}) {
    const t = useTranslation();
    const [showReply, setShowReply] = useState(false);
    const isResolved = comment.status === 'resolved';

    return (
        <div className={`${isResolved ? 'opacity-60' : ''}`}>
            <div className="flex items-start gap-2">
                <div className="flex-1">
                    <div className="flex items-center gap-2">
                        <span className="text-xs font-semibold">{comment.author?.name}</span>
                        <span className="text-xs text-muted-foreground">· {formatRelative(comment.created_at)}</span>
                        {isResolved && <CheckCircle2 className="h-3 w-3 text-green-500" />}
                    </div>
                    <p className="text-sm mt-1 whitespace-pre-wrap">{comment.body}</p>
                    <div className="flex gap-2 mt-1">
                        <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => setShowReply(!showReply)}>
                            <MessageCircle className="h-3 w-3 inline mr-1" />{t('comments.reply')}
                        </button>
                        <button type="button" className="text-xs text-muted-foreground hover:text-foreground"
                            onClick={() => fetch(`/api/comments/${comment.id}/resolve`, {
                                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as any)?.content },
                                body: JSON.stringify({ status: isResolved ? 'open' : 'resolved' })
                            }).then(() => onRefresh())}>
                            {isResolved ? t('comments.reopen') : t('comments.resolve')}
                        </button>
                    </div>
                    {showReply && <div className="mt-2 ml-4"><CommentForm entryId={entryId} parentId={comment.id} onDone={() => { setShowReply(false); onRefresh(); }} /></div>}
                </div>
            </div>
            {comment.children?.length > 0 && (
                <div className="ml-6 mt-2 border-l-2 border-muted pl-4 space-y-3">
                    {comment.children.map((child: any) => (
                        <div key={child.id}>
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-semibold">{child.author?.name}</span>
                                <span className="text-xs text-muted-foreground">· {formatRelative(child.created_at)}</span>
                            </div>
                            <p className="text-sm mt-1 whitespace-pre-wrap">{child.body}</p>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
