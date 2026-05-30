import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';
import PublishPanel from './PublishPanel';

interface Props {
    open: boolean;
    onClose: () => void;
    projectUuid: string;
    workbenchUuid?: string;
    publishedAt?: string | null;
}

export default function DeployDrawer({ open, onClose, projectUuid, workbenchUuid, publishedAt }: Props) {
    const t = useTranslation();

    const handleExport = async () => {
        if (!workbenchUuid) { toast.error(t('workbench.deploy.no_files')); return; }
        try {
            const res = await fetch(`/api/projects/${projectUuid}/workbench/${workbenchUuid}/export`);
            if (!res.ok) throw new Error('Export failed');
            const blob = await res.blob();
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = res.headers.get('Content-Disposition')?.match(/filename="?([^"]+)"?/)?.[1] ?? 'export.zip';
            a.click();
            URL.revokeObjectURL(url);
        } catch {
            toast.error(t('workbench.sites.publish_error'));
        }
    };

    return (
        <Sheet open={open} onOpenChange={val => !val && onClose()}>
            <SheetContent side="right" className="w-[420px] flex flex-col gap-0 p-0">
                <SheetHeader className="px-5 pt-5 pb-3 border-b border-border">
                    <SheetTitle>{t('workbench.deploy.title')}</SheetTitle>
                    <SheetDescription>{t('workbench.deploy.subtitle')}</SheetDescription>
                </SheetHeader>

                <Tabs defaultValue="publish" className="flex-1 flex flex-col overflow-hidden">
                    <TabsList className="w-full rounded-none border-b border-border bg-transparent h-10 px-5">
                        <TabsTrigger value="publish" className="flex-1 text-xs">{t('workbench.sites.publish')}</TabsTrigger>
                        <TabsTrigger value="export" className="flex-1 text-xs">{t('workbench.deploy.export_tab')}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="publish" className="flex-1 overflow-y-auto p-5 mt-0">
                        <PublishPanel
                            projectUuid={projectUuid}
                            workbenchUuid={workbenchUuid}
                            publishedAt={publishedAt}
                        />
                    </TabsContent>

                    <TabsContent value="export" className="p-5 space-y-3 mt-0">
                        <p className="text-sm text-muted-foreground">{t('workbench.deploy.export_desc')}</p>
                        <Button variant="outline" className="w-full justify-start gap-2" onClick={handleExport} disabled={!workbenchUuid}>
                            <Download className="w-4 h-4" />
                            {t('workbench.deploy.download_zip')}
                        </Button>
                    </TabsContent>
                </Tabs>
            </SheetContent>
        </Sheet>
    );
}
