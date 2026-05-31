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

export const themeStore = atom<'system' | 'light' | 'dark'>(
    (typeof localStorage !== 'undefined' && (localStorage.getItem('workbench-theme') as 'system' | 'light' | 'dark')) || 'dark'
);

export const selectedProviderStore = atom<string | null>(null);

export const chatContextStore = atom<{ type: 'file'; path: string } | null>(null);

export const buildErrorsStore = atom<string[]>([]);

export const autoFixStore = atom<boolean>(true);

export const undoStackStore = atom<Array<{ path: string; before: string; after: string; timestamp: number }>>([]);

const MAX_MESSAGES = 200;

export function recordUndoableChange(path: string, before: string, after: string): void {
    const stack = undoStackStore.get();
    const updated = [...stack, { path, before, after, timestamp: Date.now() }];
    if (updated.length > 50) {
        undoStackStore.set(updated.slice(-50));
    } else {
        undoStackStore.set(updated);
    }
}

export function popUndoChange(): { path: string; before: string; after: string } | null {
    const stack = undoStackStore.get();
    if (stack.length === 0) return null;
    const last = stack[stack.length - 1];
    undoStackStore.set(stack.slice(0, -1));
    return last;
}

function generateId(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    // Fallback for non-secure origins (HTTP) — UUID v4
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = (Math.random() * 16) | 0;
        const v = c === 'x' ? r : (r & 0x3) | 0x8;
        return v.toString(16);
    });
}

export function addMessage(msg: Omit<WorkbenchMessage, 'id' | 'timestamp'>): WorkbenchMessage {
    const full: WorkbenchMessage = { ...msg, id: generateId(), timestamp: Date.now() };
    const msgs = messagesStore.get();
    const updated = [...msgs, full];
    if (updated.length > MAX_MESSAGES) {
        messagesStore.set(updated.slice(-MAX_MESSAGES));
    } else {
        messagesStore.set(updated);
    }
    return full;
}

export function upsertFile(path: string, content: string, opts?: { markDirty?: boolean }): void {
    const ext = path.split('.').pop() ?? '';
    const languageMap: Record<string, string> = {
        ts: 'typescript', tsx: 'typescript', js: 'javascript', jsx: 'javascript',
        css: 'css', scss: 'css', html: 'html', json: 'json', vue: 'html', svelte: 'html',
        astro: 'html', md: 'markdown', mdx: 'markdown',
    };
    const existing = filesStore.get()[path];
    // Conserve isDirty si le fichier était déjà marqué comme modifié et qu'on
    // ne demande pas explicitement de le réinitialiser.
    const isDirty = opts?.markDirty === true
        ? true
        : (existing?.isDirty === true ? true : false);
    filesStore.setKey(path, {
        path, content,
        language: languageMap[ext] ?? 'plaintext',
        isDirty,
    });
}

let pendingChunk = '';
let batchTimeout: ReturnType<typeof setTimeout> | null = null;
let streamingMessageId: string | null = null;

/** Appelé avant de commencer le stream — crée le message assistant vide et traque son ID. */
export function startStreamingMessage(): string {
    const msg = addMessage({ role: 'assistant', content: '' });
    streamingMessageId = msg.id;
    return msg.id;
}

/** Appelé quand le stream est terminé (succès ou erreur) — flush les chunks restants. */
export function endStreamingMessage(): void {
    if (pendingChunk && streamingMessageId) {
        const msgs = messagesStore.get();
        const idx = msgs.findIndex(m => m.id === streamingMessageId);
        if (idx >= 0) {
            const updated = [...msgs];
            updated[idx] = { ...updated[idx], content: updated[idx].content + pendingChunk };
            messagesStore.set(updated);
        }
        pendingChunk = '';
    }
    if (batchTimeout) clearTimeout(batchTimeout);
    batchTimeout = null;
    streamingMessageId = null;
}

export function appendToLastAssistantMessage(chunk: string): void {
    pendingChunk += chunk;
    if (batchTimeout) clearTimeout(batchTimeout);
    batchTimeout = setTimeout(() => {
        _flushPending();
    }, 16);
}

function _flushPending(): void {
    if (pendingChunk === '') return;
    if (batchTimeout) {
        clearTimeout(batchTimeout);
        batchTimeout = null;
    }
    const msgs = messagesStore.get();
    // Cible prioritaire : le message assistant en cours de streaming
    const targetIdx = streamingMessageId
        ? msgs.findIndex(m => m.id === streamingMessageId)
        : -1;

    if (targetIdx >= 0) {
        const updated = [...msgs];
        updated[targetIdx] = {
            ...updated[targetIdx],
            content: updated[targetIdx].content + pendingChunk,
        };
        messagesStore.set(updated);
    } else if (msgs.length > 0 && msgs[msgs.length - 1].role === 'assistant') {
        // Fallback si pas de streamingMessageId (legacy / rechargement)
        const updated = [...msgs];
        updated[updated.length - 1] = {
            ...updated[updated.length - 1],
            content: updated[updated.length - 1].content + pendingChunk,
        };
        messagesStore.set(updated);
    } else {
        // Aucun message assistant trouvé — on en crée un
        const msg = addMessage({ role: 'assistant', content: pendingChunk });
        streamingMessageId = msg.id;
    }
    pendingChunk = '';
}

/** Vide immédiatement le buffer de chunks en attente et annule le timer.
 *  A appeler dans le cleanup du composant pour eviter les zombie writers. */
export function flushPendingChunks(): void {
    _flushPending();
}
