import { Head, Link } from '@inertiajs/react';
import { useState, useEffect } from 'react';
import { useStore } from '@nanostores/react';
import AppLayout from '@/layouts/app-layout';
import ChatPanel from './ChatPanel';
import PreviewPanel from './PreviewPanel';
import FileTreePanel from './FileTreePanel';
import CodeEditorPanel from './CodeEditorPanel';
import FrameworkSelector from './FrameworkSelector';
import DeployDrawer from './DeployDrawer';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Button } from '@/components/ui/button';
import { frameworkStore, activeTabStore, filesStore, upsertFile } from '@/stores/workbench';
import { route } from '@/lib/route';
import { ArrowLeft, Rocket } from 'lucide-react';
import type { BreadcrumbItem } from '@/types';

interface WorkbenchProjectData {
    uuid: string; name: string; framework: string;
    files: Record<string, string>; deploy_status: string;
    published_at: string | null;
}

interface Props {
    project: { id: number; uuid: string; name: string };
    collections: Array<{ id: number; uuid: string; name: string; slug: string; fields: unknown[] }>;
    workbenchProjects: WorkbenchProjectData[];
    frameworks: Array<{ id: string; label: string }>;
    userCan: Record<string, boolean>;
    starterFilesByFramework: Record<string, Record<string, string>>;
}

export default function WorkbenchPage({ project, workbenchProjects, frameworks, starterFilesByFramework }: Props) {
    const framework = useStore(frameworkStore);
    const activeTab = useStore(activeTabStore);
    const files = useStore(filesStore);
    const [deployOpen, setDeployOpen] = useState(false);
    const [activeWorkbenchUuid, setActiveWorkbenchUuid] = useState<string | undefined>(
        workbenchProjects[0]?.uuid
    );

    // Force le thème sombre sur le Studio (inspiration bolt.diy).
    // Appliqué sur <html> pour que les composants rendus via portail (Sheet, Dialog)
    // héritent aussi du thème. Restauré au démontage.
    useEffect(() => {
        const html = document.documentElement;
        const hadDark = html.classList.contains('dark');
        if (!hadDark) html.classList.add('dark');
        return () => { if (!hadDark) html.classList.remove('dark'); };
    }, []);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: project.name, href: route('projects.show', project.id) },
        { title: 'Workbench', href: route('projects.workbench', project.id) },
    ];

    useEffect(() => {
        const first = workbenchProjects[0];
        if (!first) return;
        frameworkStore.set(first.framework);
        Object.entries(first.files).forEach(([path, content]) => upsertFile(path, content as string));
    }, []);

    const devCommandMap: Record<string, string> = {
        nextjs: 'npm run dev', nuxt: 'npm run dev',
        astro: 'npm run dev -- --host 0.0.0.0', sveltekit: 'npm run dev -- --host 0.0.0.0',
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Workbench — ${project.name}`} />

            <div className="flex items-center justify-between px-4 py-2 border-b border-border bg-background">
                <div className="flex items-center gap-3">
                    <Button asChild variant="ghost" size="sm">
                        <Link href={route('projects.show', project.id)}>
                            <ArrowLeft className="w-4 h-4 mr-1" />{project.name}
                        </Link>
                    </Button>
                    <span className="text-muted-foreground">/</span>
                    <span className="text-sm font-medium">Workbench</span>
                </div>
                <div className="flex items-center gap-2">
                    <FrameworkSelector frameworks={frameworks} />
                    <Button size="sm" onClick={() => setDeployOpen(true)} className="gap-1.5">
                        <Rocket className="w-4 h-4" />Deploy
                    </Button>
                </div>
            </div>

            <div className="flex h-[calc(100vh-108px)]">
                <div className="w-[38%] shrink-0 border-r border-border overflow-hidden">
                    <ChatPanel projectUuid={project.uuid} frameworks={frameworks} />
                </div>

                <div className="flex-1 overflow-hidden">
                    <Tabs value={activeTab} onValueChange={val => activeTabStore.set(val as 'preview' | 'files' | 'code')} className="h-full flex flex-col">
                        <TabsList className="m-2 w-fit">
                            <TabsTrigger value="preview" className="text-xs">Preview</TabsTrigger>
                            <TabsTrigger value="files" className="text-xs">
                                Fichiers
                                {Object.keys(files).length > 0 && (
                                    <span className="ml-1.5 bg-muted rounded-full px-1.5 text-[10px]">{Object.keys(files).length}</span>
                                )}
                            </TabsTrigger>
                            <TabsTrigger value="code" className="text-xs">Code</TabsTrigger>
                        </TabsList>

                        <TabsContent value="preview" className="flex-1 m-0 overflow-hidden">
                            <PreviewPanel
                                    starterFiles={starterFilesByFramework[framework] ?? {}}
                                    installCommand="npm install --legacy-peer-deps"
                                    devCommand={devCommandMap[framework] ?? 'npm run dev'}
                                />
                        </TabsContent>
                        <TabsContent value="files" className="flex-1 m-0 overflow-hidden">
                            <FileTreePanel />
                        </TabsContent>
                        <TabsContent value="code" className="flex-1 m-0 overflow-hidden">
                            <CodeEditorPanel />
                        </TabsContent>
                    </Tabs>
                </div>
            </div>

            <DeployDrawer
                open={deployOpen}
                onClose={() => setDeployOpen(false)}
                projectUuid={project.uuid}
                workbenchUuid={activeWorkbenchUuid}
                publishedAt={workbenchProjects.find(w => w.uuid === activeWorkbenchUuid)?.published_at}
            />
        </AppLayout>
    );
}
