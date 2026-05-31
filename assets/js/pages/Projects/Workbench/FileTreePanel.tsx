import { useMemo } from 'react';
import { useStore } from '@nanostores/react';
import { filesStore, selectedFileStore, activeTabStore, chatContextStore } from '@/stores/workbench';
import { FileCode2, FolderOpen, Sparkles } from 'lucide-react';
import { cn } from '@/lib/utils';
import { useTranslation } from '@/lib/i18n';

export default function FileTreePanel() {
    const t = useTranslation();
    const files = useStore(filesStore);
    const selectedFile = useStore(selectedFileStore);
    const filePaths = Object.keys(files).sort();
    const tree = useMemo(() => buildTree(filePaths), [filePaths]);

    const handleFileClick = (path: string) => {
        selectedFileStore.set(path);
        activeTabStore.set('code');
    };

    const handleImproveFile = (path: string) => {
        chatContextStore.set({ type: 'file', path });
        activeTabStore.set('code');
    };

    if (filePaths.length === 0) {
        return (
            <div className="flex flex-col items-center justify-center h-full text-muted-foreground text-sm p-6 text-center">
                <FileCode2 className="w-8 h-8 mb-3 opacity-30" />
                <p>{t('workbench.files_empty')}</p>
            </div>
        );
    }

    return (
        <div className="h-full overflow-y-auto p-2 text-sm font-mono">
            <TreeNode
                node={tree}
                depth={0}
                selectedFile={selectedFile}
                onFileClick={handleFileClick}
                onImproveFile={handleImproveFile}
                t={t}
            />
        </div>
    );
}

interface TreeNodeData { name: string; path: string; isDir: boolean; children: TreeNodeData[]; }

function buildTree(paths: string[]): TreeNodeData {
    const root: TreeNodeData = { name: '/', path: '', isDir: true, children: [] };
    for (const filePath of paths) {
        const parts = filePath.split('/');
        let current = root;
        for (let i = 0; i < parts.length; i++) {
            const part = parts[i];
            const isLast = i === parts.length - 1;
            const existing = current.children.find(c => c.name === part);
            if (existing) { current = existing; }
            else {
                const newNode: TreeNodeData = { name: part, path: parts.slice(0, i + 1).join('/'), isDir: !isLast, children: [] };
                current.children.push(newNode);
                current = newNode;
            }
        }
    }
    return root;
}

function TreeNode({ node, depth, selectedFile, onFileClick, onImproveFile, t }: {
    node: TreeNodeData; depth: number; selectedFile: string | null;
    onFileClick: (path: string) => void; onImproveFile: (path: string) => void;
    t: (key: string) => string;
}) {
    const isSelected = !node.isDir && node.path === selectedFile;
    const improveLabel = t('workbench.files_improve');
    return (
        <>
            {node.name !== '/' && (
                <div
                    className={cn(
                        'flex items-center gap-1.5 px-2 py-2 rounded cursor-pointer hover:bg-muted/50 group min-h-[44px]',
                        isSelected && 'bg-muted text-accent-foreground',
                    )}
                    style={{ paddingLeft: `${depth * 12 + 8}px` }}
                    onClick={() => !node.isDir && onFileClick(node.path)}
                    role="treeitem"
                    aria-selected={isSelected}
                    aria-label={node.name}
                    tabIndex={0}
                    onKeyDown={e => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            if (!node.isDir) onFileClick(node.path);
                        }
                    }}
                >
                    {node.isDir
                        ? <FolderOpen className="w-3.5 h-3.5 text-yellow-500 shrink-0" />
                        : <FileCode2 className="w-3.5 h-3.5 text-blue-400 shrink-0" />}
                    <span className={cn('truncate text-xs', node.isDir ? 'text-muted-foreground' : '')}>{node.name}</span>
                    {!node.isDir && (
                        <button
                            onClick={e => { e.stopPropagation(); onImproveFile(node.path); }}
                            className="ml-auto opacity-0 group-hover:opacity-100 p-1 hover:bg-muted rounded transition-opacity"
                            title={`${improveLabel} ${node.name}`}
                            aria-label={`${improveLabel} ${node.name}`}
                        >
                            <Sparkles className="w-3 h-3 text-violet-400" />
                        </button>
                    )}
                </div>
            )}
            {node.children.sort((a, b) => { if (a.isDir !== b.isDir) return a.isDir ? -1 : 1; return a.name.localeCompare(b.name); })
                .map(child => (
                    <TreeNode key={child.path} node={child} depth={node.name === '/' ? depth : depth + 1}
                        selectedFile={selectedFile} onFileClick={onFileClick} onImproveFile={onImproveFile} t={t} />
                ))}
        </>
    );
}
