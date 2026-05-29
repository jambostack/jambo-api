import { useStore } from '@nanostores/react';
import { useEffect, useRef } from 'react';
import { filesStore, selectedFileStore, upsertFile } from '@/stores/workbench';
import { EditorView, basicSetup } from 'codemirror';
import { javascript } from '@codemirror/lang-javascript';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { json } from '@codemirror/lang-json';
import { oneDark } from '@codemirror/theme-one-dark';
import { EditorState } from '@codemirror/state';
import { FileCode2 } from 'lucide-react';

export default function CodeEditorPanel() {
    const files = useStore(filesStore);
    const selectedFile = useStore(selectedFileStore);
    const editorRef = useRef<HTMLDivElement>(null);
    const viewRef = useRef<EditorView | null>(null);

    const file = selectedFile ? files[selectedFile] : null;

    useEffect(() => {
        if (!editorRef.current) return;

        const extensions = [
            basicSetup,
            oneDark,
            EditorView.lineWrapping,
            EditorView.updateListener.of(update => {
                if (update.docChanged && selectedFile) {
                    upsertFile(selectedFile, update.state.doc.toString());
                }
            }),
            getLanguageExtension(file?.language ?? 'plaintext'),
        ];

        const state = EditorState.create({ doc: file?.content ?? '', extensions });

        viewRef.current?.destroy();
        viewRef.current = new EditorView({ state, parent: editorRef.current });

        return () => { viewRef.current?.destroy(); viewRef.current = null; };
    }, [selectedFile]);

    useEffect(() => {
        const view = viewRef.current;
        if (!view || !file) return;
        const currentContent = view.state.doc.toString();
        if (currentContent !== file.content) {
            view.dispatch({ changes: { from: 0, to: currentContent.length, insert: file.content } });
        }
    }, [file?.content]);

    if (!file) {
        return (
            <div className="flex flex-col items-center justify-center h-full text-muted-foreground text-sm p-6 text-center">
                <FileCode2 className="w-8 h-8 mb-3 opacity-30" />
                <p>Sélectionne un fichier dans l'arbre</p>
            </div>
        );
    }

    return (
        <div className="flex flex-col h-full">
            <div className="flex items-center gap-2 px-3 py-1.5 border-b border-border bg-muted/30 text-xs font-mono text-muted-foreground">
                <FileCode2 className="w-3.5 h-3.5" />{file.path}
            </div>
            <div ref={editorRef} className="flex-1 overflow-auto [&_.cm-editor]:h-full [&_.cm-scroller]:h-full" />
        </div>
    );
}

function getLanguageExtension(language: string) {
    switch (language) {
        case 'typescript': return javascript({ typescript: true, jsx: true });
        case 'javascript': return javascript({ jsx: true });
        case 'css': case 'scss': return css();
        case 'html': case 'vue': case 'svelte': case 'astro': return html();
        case 'json': return json();
        default: return [];
    }
}
