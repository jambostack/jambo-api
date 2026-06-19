import { Zap, GitBranch, Play, Globe, Sparkles, Database, File, ArrowLeftRight, Wrench } from 'lucide-react';

export const categoryStyles: Record<string, { bg: string; border: string; text: string; icon: string }> = {
    trigger:   { bg: '#eff6ff', border: '#3b82f6', text: '#1d4ed8', icon: 'Zap' },
    logic:     { bg: '#fff7ed', border: '#f97316', text: '#c2410c', icon: 'GitBranch' },
    action:    { bg: '#faf5ff', border: '#8b5cf6', text: '#6d28d9', icon: 'Play' },
    http:      { bg: '#f0fdf4', border: '#22c55e', text: '#15803d', icon: 'Globe' },
    ai:        { bg: '#fdf2f8', border: '#ec4899', text: '#be185d', icon: 'Sparkles' },
    db:        { bg: '#fefce8', border: '#eab308', text: '#a16207', icon: 'Database' },
    file:      { bg: '#f9fafb', border: '#6b7280', text: '#374151', icon: 'File' },
    transform: { bg: '#ecfeff', border: '#06b6d4', text: '#0e7490', icon: 'ArrowLeftRight' },
    util:      { bg: '#f8fafc', border: '#475569', text: '#1e293b', icon: 'Wrench' },
};

export const iconComponents: Record<string, React.ComponentType<any>> = {
    Zap, GitBranch, Play, Globe, Sparkles, Database, File, ArrowLeftRight, Wrench,
};

export function getCategoryFromType(type: string): string {
    return type.split('.')[0] ?? 'util';
}
