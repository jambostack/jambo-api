import FieldBase, { FieldProps } from './FieldBase';
import { Input } from "@/components/ui/input";
import * as Icons from 'lucide-react';

export default function IconField({ field, value, onChange, processing, errors }: FieldProps) {
    const Preview = (value && (Icons as any)[value]) || null;

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="flex items-center gap-2">
                <span className="inline-flex items-center justify-center h-9 w-9 rounded-md border border-input bg-muted text-muted-foreground">
                    {Preview ? <Preview className="h-4 w-4" /> : <Icons.HelpCircle className="h-4 w-4 opacity-40" />}
                </span>
                <Input
                    id={field.slug}
                    type="text"
                    required={field.required}
                    value={value || ''}
                    onChange={(e) => onChange(field, e.target.value)}
                    disabled={processing}
                    placeholder={field.placeholder || 'Ex: Star, Heart, Globe'}
                    className="flex-1"
                />
            </div>
        </FieldBase>
    );
}
