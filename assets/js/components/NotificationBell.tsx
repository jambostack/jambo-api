import React, { useEffect, useState } from 'react';
import { Bell } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Badge } from '@/components/ui/badge';
import { router } from '@inertiajs/react';
import moment from 'moment';

function formatRelative(date: string): string {
    return moment(date).fromNow();
}

export default function NotificationBell() {
    const [notifs, setNotifs] = useState<any[]>([]);
    const [count, setCount] = useState(0);

    const fetchData = async () => {
        try {
            const [listRes, countRes] = await Promise.all([
                fetch('/api/notifications?per_page=10').then(r => r.json()),
                fetch('/api/notifications/unread-count').then(r => r.json()),
            ]);
            setNotifs(listRes.data ?? []);
            setCount(countRes.count ?? listRes.meta?.unread_count ?? 0);
        } catch {
            // Silencieux — les notifications ne sont pas critiques
        }
    };

    useEffect(() => {
        fetchData();
        const timer = setInterval(fetchData, 30000);
        return () => clearInterval(timer);
    }, []);

    const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';

    const markAsRead = async (id: number, link: string) => {
        await fetch(`/api/notifications/${id}/read`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        setCount(c => Math.max(0, c - 1));
        router.visit(link);
    };

    const markAllAsRead = async () => {
        await fetch('/api/notifications/read-all', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf() },
        });
        setCount(0);
        setNotifs(prev => prev.map(n => ({ ...n, read_at: new Date().toISOString() })));
    };

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button variant="ghost" size="icon" className="relative h-9 w-9">
                    <Bell className="!size-5 opacity-80" />
                    {count > 0 && (
                        <Badge variant="destructive" className="absolute -top-1 -right-1 h-4 w-4 flex items-center justify-center p-0 text-[10px]">
                            {count > 9 ? '9+' : count}
                        </Badge>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-80 p-0" align="end">
                <div className="p-2 border-b flex items-center justify-between">
                    <span className="text-xs font-semibold">Notifications</span>
                    {count > 0 && (
                        <button
                            type="button"
                            className="text-xs text-primary hover:underline"
                            onClick={markAllAsRead}
                        >
                            Tout marquer comme lu
                        </button>
                    )}
                </div>
                <div className="max-h-80 overflow-y-auto">
                    {notifs.length === 0 ? (
                        <p className="text-xs text-muted-foreground p-4 text-center">
                            Aucune notification
                        </p>
                    ) : (
                        notifs.map(n => (
                            <button
                                key={n.id}
                                type="button"
                                className={`w-full text-left p-3 hover:bg-accent border-b last:border-0 flex gap-2 ${!n.read_at ? 'bg-blue-50 dark:bg-blue-950/20' : ''}`}
                                onClick={() => markAsRead(n.id, n.link)}
                            >
                                {!n.read_at && (
                                    <span className="w-2 h-2 rounded-full bg-blue-500 mt-1.5 shrink-0" />
                                )}
                                <div>
                                    <p className="text-xs font-medium">{n.title}</p>
                                    {n.body && (
                                        <p className="text-xs text-muted-foreground mt-0.5 line-clamp-2">{n.body}</p>
                                    )}
                                    <p className="text-[10px] text-muted-foreground mt-1">{formatRelative(n.created_at)}</p>
                                </div>
                            </button>
                        ))
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}
