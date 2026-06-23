import React from 'react';
import {
    Area, AreaChart, Bar, BarChart, Cell, Pie, PieChart,
    ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { useTranslation } from '@/lib/i18n';

const STATUS_COLORS: Record<string, string> = {
    draft: '#8b949e',
    published: '#3fb950',
    scheduled: '#d2991d',
};
const FALLBACK_COLOR = '#58a6ff';

function Card({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="rounded-2xl border border-border bg-card p-5">
            <h3 className="mb-3 text-sm font-semibold">{title}</h3>
            {children}
        </div>
    );
}

export function ContentAreaChart({ data, title }: { data: { date: string; count: number }[]; title: string }) {
    return (
        <Card title={title}>
            <ResponsiveContainer width="100%" height={220}>
                <AreaChart data={data}>
                    <XAxis dataKey="date" fontSize={11} tickLine={false} />
                    <YAxis allowDecimals={false} fontSize={11} width={28} tickLine={false} />
                    <Tooltip />
                    <Area type="monotone" dataKey="count" stroke={FALLBACK_COLOR} fill={FALLBACK_COLOR} fillOpacity={0.15} />
                </AreaChart>
            </ResponsiveContainer>
        </Card>
    );
}

export function ActivityBarChart({ data, title }: { data: { date: string; count: number }[]; title: string }) {
    return (
        <Card title={title}>
            <ResponsiveContainer width="100%" height={220}>
                <BarChart data={data}>
                    <XAxis dataKey="date" fontSize={11} tickLine={false} />
                    <YAxis allowDecimals={false} fontSize={11} width={28} tickLine={false} />
                    <Tooltip />
                    <Bar dataKey="count" fill={FALLBACK_COLOR} radius={[3, 3, 0, 0]} />
                </BarChart>
            </ResponsiveContainer>
        </Card>
    );
}

export function StatusDonut({ byStatus, title }: { byStatus: Record<string, number>; title: string }) {
    const data = Object.entries(byStatus).map(([name, value]) => ({ name, value }));
    return (
        <Card title={title}>
            <ResponsiveContainer width="100%" height={220}>
                <PieChart>
                    <Pie data={data} dataKey="value" nameKey="name" innerRadius={50} outerRadius={80}>
                        {data.map((entry) => (
                            <Cell key={entry.name} fill={STATUS_COLORS[entry.name] ?? FALLBACK_COLOR} />
                        ))}
                    </Pie>
                    <Tooltip />
                </PieChart>
            </ResponsiveContainer>
        </Card>
    );
}

export function RecentActivityList({ items, title }: { items: { tool: string; status: string; source: string | null; by: string | null; at: string }[]; title: string }) {
    const t = useTranslation();
    return (
        <Card title={title}>
            {items.length === 0 ? (
                <p className="text-sm text-muted-foreground">{t('insights.activity_empty')}</p>
            ) : (
                <ul className="space-y-2">
                    {items.map((item, i) => (
                        <li key={i} className="flex items-center justify-between gap-2 text-sm">
                            <span className="truncate font-mono text-xs">{item.tool}</span>
                            <span className={item.status === 'success' ? 'text-[#3fb950]' : 'text-destructive'}>{item.status}</span>
                            <span className="text-xs text-muted-foreground">{new Date(item.at).toLocaleString()}</span>
                        </li>
                    ))}
                </ul>
            )}
        </Card>
    );
}
