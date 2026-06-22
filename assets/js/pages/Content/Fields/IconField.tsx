import { useEffect, useMemo, useRef, useState } from 'react';
import { Icon } from '@iconify/react';
import FieldBase, { FieldProps } from './FieldBase';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ScrollArea } from '@/components/ui/scroll-area';

// Jeux d'icônes proposés dans le filtre (préfixes Iconify).
const ICON_SETS: { prefix: string; label: string }[] = [
    { prefix: '', label: 'Toutes' },
    { prefix: 'lucide', label: 'Lucide' },
    { prefix: 'mdi', label: 'Material' },
    { prefix: 'tabler', label: 'Tabler' },
    { prefix: 'ph', label: 'Phosphor' },
    { prefix: 'heroicons', label: 'Heroicons' },
    { prefix: 'fa6-solid', label: 'Font Awesome' },
    { prefix: 'fa6-brands', label: 'FA Brands' },
    { prefix: 'logos', label: 'Logos' },
    { prefix: 'flag', label: 'Drapeaux' },
];

// Normalise une valeur héritée (sans préfixe) en nom Iconify lucide.
function toIconifyName(value: string): string {
    if (!value) return '';
    if (value.includes(':')) return value;
    // Ancienne valeur lucide PascalCase (ex. "BarChart3") -> "lucide:bar-chart-3"
    const kebab = value
        .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
        .replace(/\s+/g, '-')
        .toLowerCase();
    return `lucide:${kebab}`;
}

export default function IconField({ field, value, onChange, processing, errors }: FieldProps) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [setPrefix, setSetPrefix] = useState('');
    const [results, setResults] = useState<string[]>([]);
    const [loading, setLoading] = useState(false);
    const debounce = useRef<number | undefined>(undefined);

    const preview = useMemo(() => toIconifyName(value || ''), [value]);

    useEffect(() => {
        if (!open) return;
        if (query.trim().length < 2) {
            setResults([]);
            return;
        }
        setLoading(true);
        window.clearTimeout(debounce.current);
        debounce.current = window.setTimeout(async () => {
            try {
                const params = new URLSearchParams({ query: query.trim(), limit: '120' });
                if (setPrefix) params.set('prefixes', setPrefix);
                const r = await fetch(`https://api.iconify.design/search?${params}`);
                const j = await r.json();
                setResults(Array.isArray(j.icons) ? j.icons : []);
            } catch {
                setResults([]);
            } finally {
                setLoading(false);
            }
        }, 300);
        return () => window.clearTimeout(debounce.current);
    }, [query, setPrefix, open]);

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex items-center gap-2">
                <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                        <Button type="button" variant="outline" size="icon" disabled={processing} aria-label="Choisir une icône">
                            {preview
                                ? <Icon icon={preview} className="h-4 w-4" />
                                : <Icon icon="lucide:help-circle" className="h-4 w-4 opacity-40" />}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent className="w-80 p-3" align="start">
                        <div className="flex gap-2">
                            <Input
                                autoFocus
                                placeholder="Rechercher (ex: rocket, user, slack)…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                            <select
                                className="rounded-md border border-input bg-background px-2 text-sm"
                                value={setPrefix}
                                onChange={(e) => setSetPrefix(e.target.value)}
                                aria-label="Librairie"
                            >
                                {ICON_SETS.map((s) => (
                                    <option key={s.prefix} value={s.prefix}>{s.label}</option>
                                ))}
                            </select>
                        </div>
                        <ScrollArea className="mt-3 h-56">
                            {loading && <p className="px-1 py-2 text-xs text-muted-foreground">Recherche…</p>}
                            {!loading && query.trim().length >= 2 && results.length === 0 && (
                                <p className="px-1 py-2 text-xs text-muted-foreground">Aucune icône.</p>
                            )}
                            <div className="grid grid-cols-8 gap-1">
                                {results.map((name) => (
                                    <button
                                        key={name}
                                        type="button"
                                        title={name}
                                        onClick={() => { onChange(field, name); setOpen(false); }}
                                        className="flex h-8 w-8 items-center justify-center rounded hover:bg-muted"
                                    >
                                        <Icon icon={name} className="h-4 w-4" />
                                    </button>
                                ))}
                            </div>
                        </ScrollArea>
                    </PopoverContent>
                </Popover>

                <Input
                    id={field.slug}
                    type="text"
                    required={field.required}
                    value={value || ''}
                    onChange={(e) => onChange(field, e.target.value)}
                    disabled={processing}
                    placeholder={field.placeholder || 'Ex: lucide:star, mdi:rocket, logos:slack'}
                    className="flex-1"
                />
            </div>
        </FieldBase>
    );
}
