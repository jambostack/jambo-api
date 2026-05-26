import { useState } from 'react';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogFooter } from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/lib/i18n';
import axios from 'axios';
import { toast } from 'sonner';

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectUuid: string;
    projectName: string;
}

export default function ExportModal({ open, onOpenChange, projectUuid, projectName }: Props) {
    const t = useTranslation();
    const [structure, setStructure] = useState(true);
    const [content, setContent] = useState(false);
    const [media, setMedia] = useState(false);
    const [settings, setSettings] = useState(false);
    const [processing, setProcessing] = useState(false);

    const handleExport = async () => {
        setProcessing(true);
        try {
            const params = new URLSearchParams();
            if (structure) params.set('structure', '1');
            if (content) params.set('content', '1');
            if (media) params.set('media', '1');
            if (settings) params.set('settings', '1');

            const response = await axios.get(
                `/api/projects/${projectUuid}/export?${params.toString()}`,
                { responseType: 'blob' },
            );

            const url = window.URL.createObjectURL(new Blob([response.data]));
            const link = document.createElement('a');
            link.href = url;
            const disposition = response.headers['content-disposition'];
            const filename = disposition?.match(/filename="?(.+?)"?$/)?.[1] ?? `export-${projectName}.zip`;
            link.setAttribute('download', filename);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);

            toast.success(t('projects.export.success'));
            onOpenChange(false);
        } catch (e) {
            console.error(e);
            toast.error(t('projects.export.error'));
        } finally {
            setProcessing(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('projects.export.title')} — {projectName}</DialogTitle>
                    <DialogDescription className="sr-only">{t('projects.export.title')}</DialogDescription>
                </DialogHeader>
                <div className="space-y-4 py-4">
                    <div className="flex items-center space-x-2">
                        <Checkbox id="structure" checked={structure} onCheckedChange={(v) => setStructure(!!v)} disabled />
                        <Label htmlFor="structure">{t('projects.export.structure')}</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                        <Checkbox id="content" checked={content} onCheckedChange={(v) => setContent(!!v)} />
                        <Label htmlFor="content">{t('projects.export.content')}</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                        <Checkbox id="media" checked={media} onCheckedChange={(v) => setMedia(!!v)} />
                        <Label htmlFor="media">{t('projects.export.media')}</Label>
                    </div>
                    <div className="flex items-center space-x-2">
                        <Checkbox id="settings" checked={settings} onCheckedChange={(v) => setSettings(!!v)} />
                        <Label htmlFor="settings">{t('projects.export.settings')}</Label>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        {t('common.cancel')}
                    </Button>
                    <Button onClick={handleExport} disabled={processing}>
                        {processing ? t('common.exporting') : t('projects.export.button')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
