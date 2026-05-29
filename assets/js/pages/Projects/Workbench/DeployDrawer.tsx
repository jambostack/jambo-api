import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetDescription } from '@/components/ui/sheet';
import { Download, ExternalLink } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';

interface Props { open: boolean; onClose: () => void; projectUuid: string; workbenchUuid?: string; }

export default function DeployDrawer({ open, onClose }: Props) {
    return (
        <Sheet open={open} onOpenChange={val => !val && onClose()}>
            <SheetContent side="right" className="w-[400px]">
                <SheetHeader>
                    <SheetTitle>Deploy</SheetTitle>
                    <SheetDescription>Publie ton application générée</SheetDescription>
                </SheetHeader>
                <div className="mt-6 space-y-3">
                    <Button variant="outline" className="w-full justify-start gap-2" disabled>
                        <Download className="w-4 h-4" />Télécharger ZIP + Dockerfile
                        <Badge variant="secondary" className="ml-auto text-xs">Phase 2</Badge>
                    </Button>
                    <Button variant="outline" className="w-full justify-start gap-2" disabled>
                        <ExternalLink className="w-4 h-4" />Deploy vers Vercel
                        <Badge variant="secondary" className="ml-auto text-xs">Phase 2</Badge>
                    </Button>
                    <Button variant="outline" className="w-full justify-start gap-2" disabled>
                        <ExternalLink className="w-4 h-4" />Deploy vers Netlify
                        <Badge variant="secondary" className="ml-auto text-xs">Phase 2</Badge>
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
