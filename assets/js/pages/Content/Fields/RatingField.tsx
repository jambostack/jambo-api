import FieldBase, { FieldProps } from './FieldBase';
import { Star } from 'lucide-react';

export default function RatingField({ field, value, onChange, processing, errors }: FieldProps) {
    const max = Number(field.options?.max) > 0 ? Number(field.options?.max) : 5;
    const current = Number(value) || 0;

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex items-center gap-1">
                {Array.from({ length: max }, (_, i) => i + 1).map((n) => (
                    <button
                        key={n}
                        type="button"
                        onClick={() => onChange(field, n === current ? 0 : n)}
                        disabled={processing}
                        className="text-amber-500 disabled:opacity-50"
                        title={`${n}/${max}`}
                    >
                        <Star className="h-5 w-5" fill={n <= current ? 'currentColor' : 'none'} />
                    </button>
                ))}
                <span className="ml-2 text-sm text-muted-foreground">{current}/{max}</span>
            </div>
        </FieldBase>
    );
}
