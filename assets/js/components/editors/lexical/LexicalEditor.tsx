import { useCallback, useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';

import { LexicalComposer } from '@lexical/react/LexicalComposer';
import { RichTextPlugin } from '@lexical/react/LexicalRichTextPlugin';
import { ContentEditable } from '@lexical/react/LexicalContentEditable';
import { HistoryPlugin } from '@lexical/react/LexicalHistoryPlugin';
import { ListPlugin } from '@lexical/react/LexicalListPlugin';
import { LinkPlugin } from '@lexical/react/LexicalLinkPlugin';
import { OnChangePlugin } from '@lexical/react/LexicalOnChangePlugin';
import { useLexicalComposerContext } from '@lexical/react/LexicalComposerContext';
import { LexicalErrorBoundary } from '@lexical/react/LexicalErrorBoundary';

import {
    $getRoot, $getSelection, $isRangeSelection, $createParagraphNode, $isElementNode,
    FORMAT_TEXT_COMMAND, UNDO_COMMAND, REDO_COMMAND, EditorState,
} from 'lexical';
import { $generateHtmlFromNodes, $generateNodesFromDOM } from '@lexical/html';
import { HeadingNode, QuoteNode, $createHeadingNode } from '@lexical/rich-text';
import { ListNode, ListItemNode, INSERT_ORDERED_LIST_COMMAND, INSERT_UNORDERED_LIST_COMMAND } from '@lexical/list';
import { LinkNode, TOGGLE_LINK_COMMAND } from '@lexical/link';
import { CodeNode, CodeHighlightNode } from '@lexical/code';
import { CalloutNode } from './nodes/CalloutNode';
import { $setBlocksType } from '@lexical/selection';
import { $isListNode } from '@lexical/list';
import { $isHeadingNode } from '@lexical/rich-text';

import type { Project, Asset } from '@/types';
import { useAppearance } from '@/hooks/use-appearance';
import { useTranslation } from '@/lib/i18n';
import { MediaLibraryModal } from '@/pages/Assets/MediaFieldSelectModal';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';

import {
    Bold, Italic, Underline, Strikethrough, Link2, List, ListOrdered,
    Undo, Redo, Code, Image as ImageIcon, Heading1, Heading2, Heading3, X,
} from 'lucide-react';

interface LexicalEditorProps {
    value?: string;
    onChange?: (value: string) => void;
}

// Plugin: synchronise external HTML value <-> editor, export HTML on change.
// Réagit aux changements externes de `value` (chargement initial, remplissage IA,
// restauration de version) tout en évitant les boucles avec ses propres émissions.
function HtmlPlugin({ value, onChange }: { value: string; onChange?: (html: string) => void }) {
    const [editor] = useLexicalComposerContext();
    const lastEmitted = useRef<string | null>(null);

    useEffect(() => {
        const incoming = value ?? '';
        // Ne pas recharger si la valeur correspond à ce que l'éditeur vient d'émettre
        if (incoming === lastEmitted.current) return;

        editor.update(() => {
            const root = $getRoot();
            root.clear();

            if (incoming.trim()) {
                const dom = new DOMParser().parseFromString(incoming, 'text/html');
                const nodes = $generateNodesFromDOM(editor, dom);
                nodes.forEach((node) => {
                    // Les nœuds non-blocs (texte brut) doivent être encapsulés dans un paragraphe
                    if ($isElementNode(node)) {
                        root.append(node);
                    } else {
                        const paragraph = $createParagraphNode();
                        paragraph.append(node);
                        root.append(paragraph);
                    }
                });
            }

            if (root.getChildrenSize() === 0) {
                root.append($createParagraphNode());
            }
        });

        lastEmitted.current = incoming;
    }, [value, editor]);

    const handleChange = useCallback((state: EditorState) => {
        state.read(() => {
            const html = $generateHtmlFromNodes(editor, null);
            lastEmitted.current = html;
            onChange?.(html);
        });
    }, [editor, onChange]);

    return <OnChangePlugin onChange={handleChange} />;
}

// Inline link popover — replaces window.prompt()
function LinkPopover({ onConfirm, onCancel }: { onConfirm: (url: string) => void; onCancel: () => void }) {
    const [url, setUrl] = useState('');
    const inputRef = useRef<HTMLInputElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);

    useEffect(() => { inputRef.current?.focus(); }, []);

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
                onCancel();
            }
        };
        document.addEventListener('mousedown', handler);
        return () => document.removeEventListener('mousedown', handler);
    }, [onCancel]);

    const confirm = () => { if (url.trim()) onConfirm(url.trim()); };

    return (
        <div ref={containerRef} className="absolute z-50 top-full left-0 mt-1 flex items-center gap-1 bg-popover border rounded-md shadow-md p-1.5">
            <Input
                ref={inputRef}
                value={url}
                onChange={e => setUrl(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') confirm(); if (e.key === 'Escape') onCancel(); }}
                placeholder="https://"
                className="h-7 w-48 text-sm"
            />
            <Button type="button" size="sm" className="h-7 px-2 text-xs" onClick={confirm} disabled={!url.trim()}>OK</Button>
            <button type="button" className="p-1 hover:bg-accent rounded" onClick={onCancel}><X size={13} /></button>
        </div>
    );
}

// Toolbar component
function Toolbar({ onAssetInsert }: { onAssetInsert: () => void }) {
    const [editor] = useLexicalComposerContext();
    const [activeFormats, setActiveFormats] = useState<Set<string>>(new Set());
    const [blockType, setBlockType] = useState('paragraph');
    const [showLinkPopover, setShowLinkPopover] = useState(false);

    useEffect(() => {
        return editor.registerUpdateListener(({ editorState }) => {
            editorState.read(() => {
                const selection = $getSelection();
                if (!$isRangeSelection(selection)) return;

                const formats = new Set<string>();
                if (selection.hasFormat('bold')) formats.add('bold');
                if (selection.hasFormat('italic')) formats.add('italic');
                if (selection.hasFormat('underline')) formats.add('underline');
                if (selection.hasFormat('strikethrough')) formats.add('strikethrough');
                if (selection.hasFormat('code')) formats.add('code');
                setActiveFormats(formats);

                const anchorNode = selection.anchor.getNode();
                const element = anchorNode.getKey() === 'root'
                    ? anchorNode
                    : anchorNode.getTopLevelElementOrThrow();

                if ($isListNode(element)) {
                    setBlockType(element.getListType());
                } else if ($isHeadingNode(element)) {
                    setBlockType(element.getTag());
                } else {
                    setBlockType('paragraph');
                }
            });
        });
    }, [editor]);

    const formatHeading = (tag: 'h1' | 'h2' | 'h3') => {
        editor.update(() => {
            const selection = $getSelection();
            if ($isRangeSelection(selection)) {
                if (blockType === tag) {
                    $setBlocksType(selection, () => $createParagraphNode());
                } else {
                    $setBlocksType(selection, () => $createHeadingNode(tag));
                }
            }
        });
    };

    const insertLink = (url: string) => {
        editor.dispatchCommand(TOGGLE_LINK_COMMAND, { url, target: '_blank' });
        setShowLinkPopover(false);
    };

    const btn = (active: boolean) =>
        `p-1.5 rounded text-sm transition-colors ${active
            ? 'bg-accent text-accent-foreground'
            : 'hover:bg-accent/50 text-muted-foreground hover:text-foreground'}`;

    return (
        <div className="lexical-toolbar flex flex-wrap items-center gap-0.5 border-b px-2 py-1 bg-muted/30 relative">
            <button type="button" className={btn(false)} onClick={() => editor.dispatchCommand(UNDO_COMMAND, undefined)} title="Undo"><Undo size={15} /></button>
            <button type="button" className={btn(false)} onClick={() => editor.dispatchCommand(REDO_COMMAND, undefined)} title="Redo"><Redo size={15} /></button>
            <div className="w-px h-5 bg-border mx-1" />
            <button type="button" className={btn(blockType === 'h1')} onClick={() => formatHeading('h1')} title="Heading 1"><Heading1 size={15} /></button>
            <button type="button" className={btn(blockType === 'h2')} onClick={() => formatHeading('h2')} title="Heading 2"><Heading2 size={15} /></button>
            <button type="button" className={btn(blockType === 'h3')} onClick={() => formatHeading('h3')} title="Heading 3"><Heading3 size={15} /></button>
            <div className="w-px h-5 bg-border mx-1" />
            <button type="button" className={btn(activeFormats.has('bold'))} onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'bold')} title="Bold"><Bold size={15} /></button>
            <button type="button" className={btn(activeFormats.has('italic'))} onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'italic')} title="Italic"><Italic size={15} /></button>
            <button type="button" className={btn(activeFormats.has('underline'))} onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'underline')} title="Underline"><Underline size={15} /></button>
            <button type="button" className={btn(activeFormats.has('strikethrough'))} onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'strikethrough')} title="Strikethrough"><Strikethrough size={15} /></button>
            <button type="button" className={btn(activeFormats.has('code'))} onClick={() => editor.dispatchCommand(FORMAT_TEXT_COMMAND, 'code')} title="Inline code"><Code size={15} /></button>
            <div className="w-px h-5 bg-border mx-1" />
            <button type="button" className={btn(blockType === 'bullet')} onClick={() => editor.dispatchCommand(INSERT_UNORDERED_LIST_COMMAND, undefined)} title="Bullet list"><List size={15} /></button>
            <button type="button" className={btn(blockType === 'number')} onClick={() => editor.dispatchCommand(INSERT_ORDERED_LIST_COMMAND, undefined)} title="Ordered list"><ListOrdered size={15} /></button>
            <div className="w-px h-5 bg-border mx-1" />
            <div className="relative">
                <button
                    type="button"
                    className={btn(showLinkPopover)}
                    onClick={() => setShowLinkPopover(v => !v)}
                    title="Insert link"
                >
                    <Link2 size={15} />
                </button>
                {showLinkPopover && (
                    <LinkPopover
                        onConfirm={insertLink}
                        onCancel={() => setShowLinkPopover(false)}
                    />
                )}
            </div>
            <button type="button" className={btn(false)} onClick={onAssetInsert} title="Insert image from library"><ImageIcon size={15} /></button>
        </div>
    );
}

const escAttr = (str: string) => str.replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/&/g, '&amp;');

// Plugin: insert image HTML at cursor
function ImageInsertPlugin({ asset, onDone }: { asset: Asset | null; onDone: () => void }) {
    const [editor] = useLexicalComposerContext();

    useEffect(() => {
        if (!asset) return;
        editor.update(() => {
            const selection = $getSelection();
            if ($isRangeSelection(selection)) {
                const alt = escAttr(asset.metadata?.alt_text || asset.original_filename || '');
                const src = escAttr(asset.full_url ?? asset.url ?? '');
                const imgHtml = `<img src="${src}" alt="${alt}" style="max-width:100%" />`;
                const parser = new DOMParser();
                const dom = parser.parseFromString(`<p>${imgHtml}</p>`, 'text/html');
                const nodes = $generateNodesFromDOM(editor, dom);
                nodes.forEach(node => selection.insertNodes([node]));
            }
        }, { onUpdate: onDone });
    }, [asset, onDone]);

    return null;
}

export function LexicalEditor({ value = '', onChange }: LexicalEditorProps) {
    // I3 fix: project is optional — the editor can render outside a project page
    const project = (usePage<any>().props.project ?? null) as Project | null;
    const { appearance } = useAppearance();
    const t = useTranslation();
    const isDark = appearance === 'dark' || (appearance === 'system' && typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches);

    const [isAssetModalOpen, setAssetModalOpen] = useState(false);
    const [pendingAsset, setPendingAsset] = useState<Asset | null>(null);

    const handleSelectAssets = useCallback((assets: Asset[]) => {
        if (assets[0]) setPendingAsset(assets[0]);
        setAssetModalOpen(false);
    }, []);

    const initialConfig = {
        namespace: 'jambo-editor',
        theme: {
            root: 'lexical-root',
            text: {
                bold: 'lexical-bold',
                italic: 'lexical-italic',
                underline: 'lexical-underline',
                strikethrough: 'lexical-strikethrough',
                code: 'lexical-code-inline',
            },
            heading: {
                h1: 'lexical-h1',
                h2: 'lexical-h2',
                h3: 'lexical-h3',
                h4: 'lexical-h4',
            },
            list: {
                ul: 'lexical-ul',
                ol: 'lexical-ol',
                listitem: 'lexical-li',
            },
            link: 'lexical-link',
            code: 'lexical-code-block',
            paragraph: 'lexical-paragraph',
        },
        nodes: [HeadingNode, QuoteNode, ListNode, ListItemNode, LinkNode, CodeNode, CodeHighlightNode, CalloutNode],
        onError: (error: Error) => console.error('[LexicalEditor]', error),
    };

    return (
        <div className={`lexical-editor-wrapper border rounded-md overflow-hidden${isDark ? ' dark' : ''}`}>
            <LexicalComposer initialConfig={initialConfig}>
                <Toolbar onAssetInsert={() => setAssetModalOpen(true)} />
                <div className="lexical-editor-area relative min-h-[240px]">
                    <RichTextPlugin
                        contentEditable={<ContentEditable className="lexical-content-editable outline-none px-4 py-3 min-h-[240px] focus:outline-none" />}
                        placeholder={
                            <div className="lexical-placeholder absolute top-3 left-4 text-muted-foreground pointer-events-none select-none">
                                {t('editor.placeholder')}
                            </div>
                        }
                        ErrorBoundary={LexicalErrorBoundary}
                    />
                    <HistoryPlugin />
                    <ListPlugin />
                    <LinkPlugin />
                    <HtmlPlugin value={value} onChange={onChange} />
                    <ImageInsertPlugin asset={pendingAsset} onDone={() => setPendingAsset(null)} />
                </div>
            </LexicalComposer>

            {project && (
                <MediaLibraryModal
                    isOpen={isAssetModalOpen}
                    onClose={() => setAssetModalOpen(false)}
                    project={project}
                    onSelect={handleSelectAssets}
                    currentlySelected={[]}
                    allowMultiple={false}
                />
            )}
        </div>
    );
}
