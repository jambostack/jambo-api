import { useState, useEffect, useCallback } from 'react';
import { toast } from 'sonner';
import axios from 'axios';
import { Folder, FolderOpen, Plus, MoreHorizontal, Check, X, GripVertical } from 'lucide-react';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import type { MediaFolder } from '@/types';

interface MediaFolderTreeProps {
    projectUuid: string;
    selectedFolderId: number | null;
    onSelectFolder: (folderId: number | null) => void;
}

export default function MediaFolderTree({ projectUuid, selectedFolderId, onSelectFolder }: MediaFolderTreeProps) {
    const [folders, setFolders] = useState<MediaFolder[]>([]);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [editName, setEditName] = useState('');
    const [showNewInput, setShowNewInput] = useState<number | null | undefined>(undefined); // undefined=caché, null=racine, number=enfant de
    const [newName, setNewName] = useState('');

    const loadTree = useCallback(async () => {
        try {
            const res = await axios.get(route('assets.folders.tree', projectUuid));
            setFolders(res.data.data || []);
        } catch {
            // Silencieux — l'arbre se recharge au prochain montage
        }
    }, [projectUuid]);

    useEffect(() => {
        loadTree();
    }, [loadTree]);

    const handleCreate = async (parentId: number | null) => {
        const name = newName.trim();
        if (!name) return;
        try {
            await axios.post(route('assets.folders.store', projectUuid), { name, parent_id: parentId });
            setShowNewInput(null);
            setNewName('');
            loadTree();
            toast.success('Dossier créé');
        } catch {
            toast.error('Échec de la création du dossier');
        }
    };

    const handleRename = async (folderId: number) => {
        const name = editName.trim();
        if (!name) return;
        try {
            await axios.put(route('assets.folders.update', [projectUuid, folderId]), { name });
            setEditingId(null);
            loadTree();
            toast.success('Dossier renommé');
        } catch {
            toast.error('Échec du renommage');
        }
    };

    const handleDelete = async (folderId: number) => {
        if (!confirm('Supprimer ce dossier ? Les fichiers qu\'il contient ne seront pas supprimés.')) return;
        try {
            await axios.delete(route('assets.folders.destroy', [projectUuid, folderId]));
            if (selectedFolderId === folderId) onSelectFolder(null);
            loadTree();
            toast.success('Dossier supprimé');
        } catch {
            toast.error('Échec de la suppression');
        }
    };

    const renderFolder = (folder: MediaFolder, depth: number = 0) => {
        const isSelected = selectedFolderId === folder.id;
        const isEditing = editingId === folder.id;

        return (
            <div key={folder.id}>
                <div
                    className={`flex items-center gap-1.5 px-2 py-1.5 cursor-pointer rounded-md text-sm transition-colors group
                        ${isSelected ? 'bg-primary/10 text-primary font-medium' : 'hover:bg-muted'}`}
                    style={{ paddingLeft: `${8 + depth * 16}px` }}
                    onClick={() => onSelectFolder(isSelected ? null : folder.id)}
                >
                    {isSelected
                        ? <FolderOpen className="h-4 w-4 text-primary flex-shrink-0" />
                        : <Folder className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                    }

                    {isEditing ? (
                        <div className="flex items-center gap-1 flex-1 min-w-0">
                            <input
                                className="flex-1 min-w-0 bg-background border rounded px-1.5 py-0.5 text-xs outline-none focus:border-primary"
                                value={editName}
                                onChange={e => setEditName(e.target.value)}
                                onKeyDown={e => {
                                    if (e.key === 'Enter') handleRename(folder.id);
                                    if (e.key === 'Escape') setEditingId(null);
                                }}
                                autoFocus
                                onClick={e => e.stopPropagation()}
                            />
                            <Check className="h-3 w-3 text-green-500 cursor-pointer" onClick={e => { e.stopPropagation(); handleRename(folder.id); }} />
                            <X className="h-3 w-3 text-muted-foreground cursor-pointer" onClick={e => { e.stopPropagation(); setEditingId(null); }} />
                        </div>
                    ) : (
                        <span className="truncate flex-1 min-w-0">{folder.name}</span>
                    )}

                    {/* Menu contextuel */}
                    {!isEditing && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild onClick={e => e.stopPropagation()}>
                                <button className="md:opacity-0 md:group-hover:opacity-100 p-0.5 rounded hover:bg-muted-foreground/10 transition-opacity">
                                    <MoreHorizontal className="h-3.5 w-3.5 text-muted-foreground" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="w-36">
                                <DropdownMenuItem onClick={e => { e.stopPropagation(); setShowNewInput(folder.id); }}>
                                    <Plus className="h-3.5 w-3.5 mr-2" />
                                    Sous-dossier
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={e => {
                                    e.stopPropagation();
                                    setEditName(folder.name);
                                    setEditingId(folder.id);
                                }}>
                                    Renommer
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    className="text-destructive"
                                    onClick={e => { e.stopPropagation(); handleDelete(folder.id); }}
                                >
                                    Supprimer
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                </div>

                {/* Input pour nouveau sous-dossier */}
                {showNewInput === folder.id && (
                    <div className="flex items-center gap-1 px-2 py-1" style={{ paddingLeft: `${8 + (depth + 1) * 16}px` }}>
                        <input
                            className="flex-1 bg-background border rounded px-1.5 py-0.5 text-xs outline-none focus:border-primary"
                            placeholder="Nom du dossier..."
                            value={newName}
                            onChange={e => setNewName(e.target.value)}
                            onKeyDown={e => {
                                if (e.key === 'Enter') handleCreate(folder.id);
                                if (e.key === 'Escape') { setShowNewInput(null); setNewName(''); }
                            }}
                            autoFocus
                        />
                        <Check className="h-3 w-3 text-green-500 cursor-pointer" onClick={() => handleCreate(folder.id)} />
                        <X className="h-3 w-3 text-muted-foreground cursor-pointer" onClick={() => { setShowNewInput(null); setNewName(''); }} />
                    </div>
                )}

                {/* Enfants récursifs */}
                {folder.children && folder.children.length > 0 && (
                    <div>
                        {folder.children.map(child => renderFolder(child, depth + 1))}
                    </div>
                )}
            </div>
        );
    };

    return (
        <div className="space-y-0.5">
            {/* Dossier racine (tous les fichiers) */}
            <div
                className={`flex items-center gap-2 px-2 py-1.5 cursor-pointer rounded-md text-sm font-medium transition-colors
                    ${selectedFolderId === null ? 'bg-primary/10 text-primary' : 'hover:bg-muted'}`}
                onClick={() => onSelectFolder(null)}
            >
                {selectedFolderId === null
                    ? <FolderOpen className="h-4 w-4 text-primary" />
                    : <Folder className="h-4 w-4 text-muted-foreground" />
                }
                <span>Tous les fichiers</span>
            </div>

            {/* Arbre des dossiers */}
            {folders.map(folder => renderFolder(folder))}

            {/* Input nouveau dossier racine */}
            {showNewInput === null ? (
                <button
                    className="flex items-center gap-1.5 px-2 py-1.5 text-xs text-muted-foreground hover:text-foreground hover:bg-muted rounded-md w-full transition-colors mt-1"
                    onClick={() => setShowNewInput(null)}
                >
                    <Plus className="h-3.5 w-3.5" />
                    Nouveau dossier
                </button>
            ) : (
                <div className="flex items-center gap-1 px-2 py-1 mt-1">
                    <Folder className="h-4 w-4 text-muted-foreground flex-shrink-0" />
                    <input
                        className="flex-1 bg-background border rounded px-1.5 py-0.5 text-xs outline-none focus:border-primary"
                        placeholder="Nom du dossier..."
                        value={newName}
                        onChange={e => setNewName(e.target.value)}
                        onKeyDown={e => {
                            if (e.key === 'Enter') handleCreate(null);
                            if (e.key === 'Escape') { setShowNewInput(undefined!); setNewName(''); }
                        }}
                        autoFocus
                    />
                    <Check className="h-3 w-3 text-green-500 cursor-pointer" onClick={() => handleCreate(null)} />
                    <X className="h-3 w-3 text-muted-foreground cursor-pointer" onClick={() => { setShowNewInput(undefined); setNewName(''); }} />
                </div>
            )}
        </div>
    );
}
