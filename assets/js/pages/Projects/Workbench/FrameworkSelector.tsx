import { useStore } from '@nanostores/react';
import { frameworkStore } from '@/stores/workbench';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useTranslation } from '@/lib/i18n';

interface Framework { id: string; label: string; }
interface Props { frameworks: Framework[]; disabled?: boolean; }

export default function FrameworkSelector({ frameworks, disabled = false }: Props) {
    const t = useTranslation();
    const framework = useStore(frameworkStore);

    return (
        <Select value={framework} onValueChange={val => frameworkStore.set(val)} disabled={disabled}>
            <SelectTrigger className="w-36 h-8 text-xs">
                <SelectValue placeholder={t('workbench.framework_label')} />
            </SelectTrigger>
            <SelectContent>
                {frameworks.map(fw => (
                    <SelectItem key={fw.id} value={fw.id}>{fw.label}</SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
