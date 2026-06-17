import FieldBase, { FieldProps } from './FieldBase';
import { Input } from "@/components/ui/input";

export default function UrlField({ field, value, onChange, processing, errors }: FieldProps) {
    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <Input
                id={field.slug}
                type="url"
                inputMode="url"
                required={field.required}
                value={value || ''}
                onChange={(e) => onChange(field, e.target.value)}
                disabled={processing}
                placeholder={field.placeholder || 'https://...'}
            />
        </FieldBase>
    );
}
