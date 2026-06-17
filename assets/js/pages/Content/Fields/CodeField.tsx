import FieldBase, { FieldProps } from './FieldBase';

export default function CodeField({ field, value, onChange, processing, errors }: FieldProps) {
    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <textarea
                id={field.slug}
                required={field.required}
                value={value || ''}
                onChange={(e) => onChange(field, e.target.value)}
                disabled={processing}
                placeholder={field.placeholder}
                rows={10}
                spellCheck={false}
                className="flex w-full rounded-md border border-input bg-slate-950 text-slate-100 px-3 py-2 text-sm font-mono ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:cursor-not-allowed disabled:opacity-50"
            />
        </FieldBase>
    );
}
