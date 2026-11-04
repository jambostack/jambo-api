import FieldBase, { FieldProps } from './FieldBase';
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { RefreshCw } from 'lucide-react';
import { useTranslation } from '@/lib/i18n';

export default function UuidField({ field, value, onChange, processing, errors }: FieldProps) {
    const t = useTranslation();
    const generate = () => onChange(field, crypto.randomUUID());

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex gap-2">
                <Input
                    id={field.slug}
                    type="text"
                    required={field.required}
                    value={value || ''}
                    onChange={(e) => onChange(field, e.target.value)}
                    disabled={processing}
                    placeholder={field.placeholder || 'UUID'}
                    className="flex-1 font-mono"
                />
                <Button type="button" variant="outline" size="icon" onClick={generate} disabled={processing} title={t('content.generate_uuid')}>
                    <RefreshCw className="h-4 w-4" />
                </Button>
            </div>
        </FieldBase>
    );
}
