import FieldBase, { FieldProps } from './FieldBase';
import CodeMirrorEditor, { CodeLanguage } from '@/components/editors/CodeMirrorEditor';

export default function CodeField({ field, value, onChange, processing, errors }: FieldProps) {
    const language: CodeLanguage = field.options?.language ?? 'plaintext';

    return (
        <FieldBase field={field} value={value} onChange={onChange} processing={processing} errors={errors}>
            <CodeMirrorEditor
                value={value || ''}
                onChange={(val) => onChange(field, val)}
                language={language}
                readonly={processing}
                height="300px"
            />
        </FieldBase>
    );
}
