import { useRef, useState, useEffect, useCallback } from 'react';
import { Editor } from '@tinymce/tinymce-react';
import { usePage } from '@inertiajs/react';
import { PageProps as InertiaPageProps } from '@inertiajs/core';

import type { Project, Asset } from '@/types';
import { useAppearance } from '@/hooks/use-appearance';

import { MediaLibraryModal } from '@/pages/Assets/MediaFieldSelectModal';

interface TinyEditorProps {
    value?: string;
    onChange?: (value: string) => void;
}

export function TinyEditor({ value = '', onChange }: TinyEditorProps) {
    const editorRef = useRef<any>(null);

    // Access current project from Inertia page props (needed for media library modal)
    interface PageProps extends InertiaPageProps {
        project: Project;
    }
    const { project } = usePage<PageProps>().props;

    // Use the appearance hook to properly detect theme including system preference
    const { appearance } = useAppearance();
    const isDark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    
    const [skin, setSkin] = useState(isDark ? 'tinymce-5-dark' : 'tinymce-5');
    const [contentCss, setContentCss] = useState(isDark ? 'dark' : 'default');

    // Asset library modal state
    const [isAssetModalOpen, setAssetModalOpen] = useState(false);

    const handleInsertAssets = useCallback((assets: Asset[]) => {
        if (!editorRef.current) return;

        assets.forEach((asset) => {
            const altText = asset.metadata?.alt_text || asset.original_filename || '';
            editorRef.current.insertContent(`<img src="${asset.full_url ?? asset.url ?? ''}" alt="${altText}" />`);
        });

        setAssetModalOpen(false);
    }, []);

    // Update editor theme when appearance changes
    useEffect(() => {
        const newIsDark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        setSkin(newIsDark ? 'tinymce-5-dark' : 'tinymce-5');
        setContentCss(newIsDark ? 'dark' : 'default');
    }, [appearance]);

    useEffect(() => {
        const style = document.createElement('style');
        style.innerHTML = '.tox-tinymce .tox-promotion { display: none !important; }';
        style.innerHTML += isDark ? '.tox-editor-header { border-bottom: 1px solid !important; }' : '';
        document.head.appendChild(style);

        return () => {
            style.remove();
        };
    }, [isDark]);

    return (
        <>
            <Editor
                tinymceScriptSrc='/js/tinymce/tinymce.min.js'
                licenseKey='gpl'
                onInit={(_evt, editor) => editorRef.current = editor}
                value={value}
                onEditorChange={(content) => onChange?.(content)}
                init={{
                    skin: skin,
                    content_css: contentCss,
                    content_style: 'body { font-family:Helvetica,Arial,sans-serif; font-size:16px }',
                    plugins: [
                        'preview', 'importcss', 'searchreplace', 'autolink', 'directionality', 'code',
                        'visualblocks', 'visualchars', 'fullscreen', 'image', 'link', 'media', 'codesample',
                        'table', 'charmap', 'pagebreak', 'nonbreaking', 'anchor', 'insertdatetime',
                        'advlist', 'lists', 'wordcount', 'help', 'emoticons'
                    ],
                    menubar: 'file edit view insert format tools table help',
                    toolbar: 'code | blocks fontfamily fontsize forecolor backcolor removeformat | bold italic underline | align numlist bullist | link image insertAsset | table media | lineheight outdent indent | charmap emoticons | fullscreen preview | save print | pagebreak anchor codesample | ltr rtl',
                    convert_urls: false,
                    relative_urls: false,
                    remove_script_host: false,
                    setup: (editor: any) => {
                        editor.ui.registry.addButton('insertAsset', {
                            icon: 'edit-image',
                            tooltip: 'Insert image from asset library',
                            onAction: () => {
                                setAssetModalOpen(true);
                            }
                        });
                    },
                }}
            />
            {/* Asset Library Modal for inserting images */}
            {project && (
                <MediaLibraryModal
                    isOpen={isAssetModalOpen}
                    onClose={() => setAssetModalOpen(false)}
                    project={project}
                    onSelect={handleInsertAssets}
                    currentlySelected={[]}
                    allowMultiple={false}
                />
            )}
        </>
    );
}

