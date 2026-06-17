import React, { useEffect, useRef, useState } from 'react';
import { EditorState, Compartment } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { syntaxHighlighting, defaultHighlightStyle, bracketMatching, indentOnInput } from '@codemirror/language';
import { oneDark } from '@codemirror/theme-one-dark';
import { javascript } from '@codemirror/lang-javascript';
import { json } from '@codemirror/lang-json';
import { html } from '@codemirror/lang-html';
import { css } from '@codemirror/lang-css';
import { sql } from '@codemirror/lang-sql';
import { php } from '@codemirror/lang-php';
import { python } from '@codemirror/lang-python';
import { markdown } from '@codemirror/lang-markdown';
import { xml } from '@codemirror/lang-xml';
import { StreamLanguage } from '@codemirror/language';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { yaml as yamlMode } from '@codemirror/legacy-modes/mode/yaml';

export type CodeLanguage = 'javascript' | 'typescript' | 'json' | 'html' | 'css' | 'sql' | 'php' | 'python' | 'markdown' | 'xml' | 'yaml' | 'shell' | 'plaintext';

interface CodeMirrorEditorProps {
    value: string;
    onChange: (value: string) => void;
    language?: CodeLanguage;
    readonly?: boolean;
    height?: string;
}

const langCompartment = new Compartment();

function getLanguageExtension(lang: CodeLanguage) {
    switch (lang) {
        case 'javascript': return javascript();
        case 'typescript': return javascript({ typescript: true });
        case 'json': return json();
        case 'html': return html();
        case 'css': return css();
        case 'sql': return sql();
        case 'php': return php();
        case 'python': return python();
        case 'markdown': return markdown();
        case 'xml': return xml();
        case 'shell': return StreamLanguage.define(shell);
        case 'yaml': return StreamLanguage.define(yamlMode);
        case 'plaintext': default: return [];
    }
}

export default function CodeMirrorEditor({ value, onChange, language = 'plaintext', readonly = false, height = '300px' }: CodeMirrorEditorProps) {
    const containerRef = useRef<HTMLDivElement>(null);
    const viewRef = useRef<EditorView | null>(null);
    const [lang, setLang] = useState<CodeLanguage>(language);

    useEffect(() => {
        if (!containerRef.current || viewRef.current) return;

        const updateListener = EditorView.updateListener.of(update => {
            if (update.docChanged) {
                onChange(update.state.doc.toString());
            }
        });

        const extensions = [
            lineNumbers(),
            highlightActiveLine(),
            history(),
            bracketMatching(),
            indentOnInput(),
            syntaxHighlighting(defaultHighlightStyle),
            keymap.of([...defaultKeymap, ...historyKeymap]),
            oneDark,
            langCompartment.of(getLanguageExtension(lang)),
            updateListener,
            EditorView.editable.of(!readonly),
            EditorState.readOnly.of(readonly),
            EditorView.theme({ '&': { height }, '.cm-scroller': { overflow: 'auto' } }),
        ];

        const state = EditorState.create({ doc: value, extensions });
        viewRef.current = new EditorView({ state, parent: containerRef.current });

        return () => { viewRef.current?.destroy(); viewRef.current = null; };
    }, []);

    // Sync external value changes
    useEffect(() => {
        const view = viewRef.current;
        if (!view) return;
        const current = view.state.doc.toString();
        if (value !== current) {
            view.dispatch({
                changes: { from: 0, to: current.length, insert: value }
            });
        }
    }, [value]);

    // Sync language changes
    useEffect(() => {
        const view = viewRef.current;
        if (!view) return;
        view.dispatch({
            effects: langCompartment.reconfigure(getLanguageExtension(language))
        });
        setLang(language);
    }, [language]);

    return <div ref={containerRef} className="border rounded-md overflow-hidden" />;
}
