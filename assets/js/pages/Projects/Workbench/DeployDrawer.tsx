// assets/js/pages/Projects/Workbench/DeployDrawer.tsx
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Download } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/lib/i18n';
import { toast } from 'sonner';

interface Props { open: boolean; onClose: () => void; projectUuid: string; workbenchUuid?: string; }

export default function DeployDrawer({ open, onClose, projectUuid, workbenchUuid }: Props) {
    const t = useTranslation();

    const handleExport = async () => {
        if (!workbenchUuid) { toast.error(t('workbench.deploy.no_files')); return; }
        window.location.href = `/api/projects/${projectUuid}/workbench/${workbenchUuid}/export`;
    };

    return (
        <Sheet open={open} onOpenChange={val => !val && onClose()}>
            <SheetContent side="right" className="w-[420px] flex flex-col">
                <SheetHeader>
                    <SheetTitle>{t('workbench.deploy.title')}</SheetTitle>
                    <SheetDescription>{t('workbench.deploy.subtitle')}</SheetDescription>
                </SheetHeader>

                <Tabs defaultValue="export" className="mt-6 flex-1 flex flex-col">
                    <TabsList className="w-full mb-4">
                        <TabsTrigger value="export" className="flex-1 text-xs">Export</TabsTrigger>
                        <TabsTrigger value="publish" className="flex-1 text-xs">{t('workbench.sites.publish')}</TabsTrigger>
                    </TabsList>

                    <TabsContent value="export" className="space-y-3">
                        <p className="text-sm text-muted-foreground">{t('workbench.deploy.export_desc')}</p>
                        <Button variant="outline" className="w-full justify-start gap-2" onClick={handleExport} disabled={!workbenchUuid}>
                            <Download className="w-4 h-4" />
                            {t('workbench.deploy.download_zip')}
                        </Button>
                    </TabsContent>

                    <TabsContent value="publish" className="flex-1">
                        {/* PublishPanel sera ajoute en Task 7 */}
                        <p className="text-sm text-muted-foreground">{t('workbench.sites.coming_soon')}</p>
                    </TabsContent>
                </Tabs>
            </SheetContent>
        </Sheet>
    );
}
