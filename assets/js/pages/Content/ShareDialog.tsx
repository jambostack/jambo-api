import { useEffect, useState } from 'react';
import axios from 'axios';
import { useTranslation } from '@/lib/i18n';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Share2, Copy, Trash } from 'lucide-react';

interface ShareItem {
    id: number;
    expiresAt: string | null;
    revokedAt: string | null;
    lastAccessedAt: string | null;
    viewCount: number;
    createdAt: string;
    isValid: boolean;
}

interface Props {
    projectUuid: string;
    entryUuid: string;
}

export default function ShareDialog({ projectUuid, entryUuid }: Props) {
    const t = useTranslation();
    const [open, setOpen] = useState(false);
    const [duration, setDuration] = useState('7d');
    const [shares, setShares] = useState<ShareItem[]>([]);
    const [createdUrl, setCreatedUrl] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);

    const load = () => {
        axios
            .get(route('shares.index', projectUuid), { params: { entryUuid } })
            .then((r) => setShares(r.data.data))
            .catch(() => {});
    };

    useEffect(() => {
        if (open) {
            load();
            setCreatedUrl(null);
        }
    }, [open]);

    const create = () => {
        setLoading(true);
        axios
            .post(route('shares.store', projectUuid), { entryUuid, duration })
            .then((r) => {
                setCreatedUrl(r.data.data.url);
                load();
            })
            .catch(() => {})
            .finally(() => setLoading(false));
    };

    const revoke = (id: number) => {
        axios.delete(route('shares.destroy', [projectUuid, id])).then(load).catch(() => {});
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="gap-1.5">
                    <Share2 className="h-4 w-4" />
                    {t('shares.button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('shares.title')}</DialogTitle>
                </DialogHeader>

                <div className="flex items-end gap-2">
                    <div className="flex-1">
                        <label className="text-xs text-muted-foreground">{t('shares.duration')}</label>
                        <Select value={duration} onValueChange={setDuration}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="1h">{t('shares.dur_1h')}</SelectItem>
                                <SelectItem value="24h">{t('shares.dur_24h')}</SelectItem>
                                <SelectItem value="7d">{t('shares.dur_7d')}</SelectItem>
                                <SelectItem value="30d">{t('shares.dur_30d')}</SelectItem>
                                <SelectItem value="never">{t('shares.dur_never')}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <Button onClick={create} disabled={loading}>{t('shares.create')}</Button>
                </div>

                {createdUrl && (
                    <div className="rounded-md border border-border bg-muted/40 p-3">
                        <p className="text-xs text-muted-foreground mb-1">{t('shares.copy_now')}</p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 truncate text-xs">{createdUrl}</code>
                            <Button variant="ghost" size="sm" onClick={() => navigator.clipboard.writeText(createdUrl)}>
                                <Copy className="h-4 w-4" />
                            </Button>
                        </div>
                    </div>
                )}

                <div className="space-y-2">
                    {shares.length === 0 && <p className="text-sm text-muted-foreground">{t('shares.empty')}</p>}
                    {shares.map((s) => (
                        <div key={s.id} className="flex items-center justify-between gap-2 text-sm border-b border-border pb-2">
                            <span className={s.isValid ? '' : 'line-through text-muted-foreground'}>
                                {s.expiresAt ? t('shares.expires_on', { date: new Date(s.expiresAt).toLocaleString() }) : t('shares.dur_never')}
                                {' · '}
                                {t('shares.views', { count: String(s.viewCount) })}
                            </span>
                            {s.isValid && (
                                <Button variant="ghost" size="sm" className="text-destructive" onClick={() => revoke(s.id)}>
                                    <Trash className="h-4 w-4" />
                                </Button>
                            )}
                        </div>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}
