import { Search } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/lib/i18n';

interface SearchBarProps {
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    className?: string;
}

export function SearchBar({ value, onChange, placeholder, className = '' }: SearchBarProps) {
    const t = useTranslation();
    return (
        <div className={`relative w-full ${className}`}>
            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                type="search"
                placeholder={placeholder || t('common.search_placeholder')}
                className="w-full pl-9"
                value={value}
                onChange={(e) => onChange(e.target.value)}
            />
        </div>
    );
} 