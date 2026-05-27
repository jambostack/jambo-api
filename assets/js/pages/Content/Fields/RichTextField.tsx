import FieldBase, { FieldProps } from './FieldBase';
import { LexicalEditor } from '@/components/editors/lexical/LexicalEditor';

export default function RichTextField({ field, value, onChange, processing, errors }: FieldProps) {
    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <LexicalEditor
                value={value || ''}
                onChange={(content) => onChange(field, content)}
            />
        </FieldBase>
    );
}