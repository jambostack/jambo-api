import FieldBase, { FieldProps } from './FieldBase';

import { TinyEditor } from '@/components/editors/tiny/Editor';

export default function RichTextField({ field, value, onChange, processing, errors }: FieldProps) {
    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <div className="rounded-md border border-input">
                <TinyEditor 
                    value={value || ''}
                    onChange={(content) => onChange(field, content)}
                />
            </div>
        </FieldBase>
    );
} 