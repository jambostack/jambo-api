// assets/js/stores/workbench.ts
import { atom, map } from 'nanostores';

export type WorkbenchStatus = 'idle' | 'generating' | 'wc-booting' | 'wc-installing' | 'wc-running' | 'error';

export interface WorkbenchMessage {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    files?: string[];
    timestamp: number;
}

export interface WorkbenchFile {
    path: string;
    content: string;
    language: string;
    isDirty: boolean;
}

// Map of filepath → WorkbenchFile
export const filesStore = map<Record<string, WorkbenchFile>>({});

export const messagesStore = atom<WorkbenchMessage[]>([]);

export const frameworkStore = atom<string>('nextjs');

export const previewUrlStore = atom<string | null>(null);

export const statusStore = atom<WorkbenchStatus>('idle');

export const selectedFileStore = atom<string | null>(null);

export const activeTabStore = atom<'preview' | 'files' | 'code'>('preview');

export function addMessage(msg: Omit<WorkbenchMessage, 'id' | 'timestamp'>): WorkbenchMessage {
    const full: WorkbenchMessage = { ...msg, id: crypto.randomUUID(), timestamp: Date.now() };
    messagesStore.set([...messagesStore.get(), full]);
    return full;
}

export function upsertFile(path: string, content: string): void {
    const ext = path.split('.').pop() ?? '';
    const languageMap: Record<string, string> = {
        ts: 'typescript', tsx: 'typescript', js: 'javascript', jsx: 'javascript',
        css: 'css', scss: 'css', html: 'html', json: 'json', vue: 'html', svelte: 'html',
        astro: 'html', md: 'markdown', mdx: 'markdown',
    };
    filesStore.setKey(path, {
        path, content,
        language: languageMap[ext] ?? 'plaintext',
        isDirty: false,
    });
}

export function appendToLastAssistantMessage(chunk: string): void {
    const msgs = messagesStore.get();
    if (msgs.length === 0 || msgs[msgs.length - 1].role !== 'assistant') {
        addMessage({ role: 'assistant', content: chunk });
        return;
    }
    const updated = [...msgs];
    updated[updated.length - 1] = {
        ...updated[updated.length - 1],
        content: updated[updated.length - 1].content + chunk,
    };
    messagesStore.set(updated);
}
