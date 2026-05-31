// assets/js/lib/messageParser.ts

export interface ParserCallbacks {
    onTextChunk: (text: string) => void;
    onFileOpen: (path: string) => void;
    onFileChunk: (path: string, chunk: string) => void;
    onFileClose: (path: string, fullContent: string) => void;
    onDone: () => void;
    onError: (error: string) => void;
}

type ParseState = 'text' | 'in-file';

// Buffer de sécurité pour éviter de tronquer les balises <jamboFile path="...">
// avec des chemins longs (jusqu'à ~200 caractères)
const SAFE_MARGIN = 200;

export class MessageParser {
    private state: ParseState = 'text';
    private buffer = '';
    private currentFilePath = '';
    private currentFileContent = '';

    constructor(private readonly callbacks: ParserCallbacks) {}

    feed(raw: string): void {
        if (raw === '[DONE]') {
            this.flush();
            this.callbacks.onDone();
            return;
        }

        this.buffer += raw;
        this.processBuffer();
    }

    private processBuffer(): void {
        while (this.buffer.length > 0) {
            if (this.state === 'text') {
                const openIdx = this.buffer.indexOf('<jamboFile ');
                if (openIdx === -1) {
                    if (this.buffer.length > SAFE_MARGIN) {
                        const safe = this.buffer.slice(0, -SAFE_MARGIN);
                        this.callbacks.onTextChunk(safe);
                        this.buffer = this.buffer.slice(-SAFE_MARGIN);
                    }
                    break;
                }
                if (openIdx > 0) {
                    this.callbacks.onTextChunk(this.buffer.slice(0, openIdx));
                }
                this.buffer = this.buffer.slice(openIdx);

                const tagEndIdx = this.buffer.indexOf('>');
                if (tagEndIdx === -1) break;

                const tag = this.buffer.slice(0, tagEndIdx + 1);
                const pathMatch = tag.match(/path="([^"]+)"/);
                if (!pathMatch) {
                    this.buffer = this.buffer.slice(tagEndIdx + 1);
                    continue;
                }

                this.currentFilePath = pathMatch[1];
                this.currentFileContent = '';
                this.state = 'in-file';
                this.callbacks.onFileOpen(this.currentFilePath);
                this.buffer = this.buffer.slice(tagEndIdx + 1);

            } else {
                const closeTag = `</jamboFile>`;
                const closeIdx = this.buffer.indexOf(closeTag);
                if (closeIdx === -1) {
                    const safe = this.buffer.slice(0, -(closeTag.length + SAFE_MARGIN));
                    if (safe.length > 0) {
                        this.currentFileContent += safe;
                        this.callbacks.onFileChunk(this.currentFilePath, safe);
                        this.buffer = this.buffer.slice(safe.length);
                    }
                    break;
                }
                const lastChunk = this.buffer.slice(0, closeIdx);
                if (lastChunk.length > 0) {
                    this.currentFileContent += lastChunk;
                    this.callbacks.onFileChunk(this.currentFilePath, lastChunk);
                }
                this.callbacks.onFileClose(this.currentFilePath, this.currentFileContent.trim());
                this.state = 'text';
                this.currentFilePath = '';
                this.currentFileContent = '';
                this.buffer = this.buffer.slice(closeIdx + closeTag.length);
            }
        }
    }

    private flush(): void {
        // Si on est encore dans un fichier (stream coupé avant la balise fermante),
        // on publie le contenu partiel au lieu de le perdre.
        if (this.state === 'in-file' && this.currentFileContent.length > 0) {
            this.callbacks.onFileClose(this.currentFilePath, this.currentFileContent.trim());
            this.state = 'text';
            this.currentFilePath = '';
            this.currentFileContent = '';
        }
        if (this.buffer.trim().length > 0) {
            this.callbacks.onTextChunk(this.buffer);
            this.buffer = '';
        }
    }

    reset(): void {
        this.state = 'text';
        this.buffer = '';
        this.currentFilePath = '';
        this.currentFileContent = '';
    }
}

export async function consumeSseStream(
    response: Response,
    parser: MessageParser,
): Promise<void> {
    const reader = response.body?.getReader();
    if (!reader) throw new Error('No readable stream');
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();
            if (done) break;

            buffer += decoder.decode(value, { stream: true });
            const lines = buffer.split('\n');
            buffer = lines.pop() ?? '';

            for (const line of lines) {
                if (!line.startsWith('data: ')) continue;
                const payload = line.slice(6).trim();
                if (!payload) continue;
                try {
                    const parsed = JSON.parse(payload) as { content?: string };
                    if (parsed.content !== undefined) parser.feed(parsed.content);
                } catch {
                    if (payload === '[DONE]') parser.feed('[DONE]');
                    else console.warn('[SSE] Failed to parse payload:', payload.slice(0, 100));
                }
            }
        }

        if (buffer.startsWith('data: ')) {
            const payload = buffer.slice(6).trim();
            if (payload && payload !== '[DONE]') {
                try {
                    const parsed = JSON.parse(payload) as { content?: string };
                    if (parsed.content !== undefined) parser.feed(parsed.content);
                } catch { /* ignore */ }
            }
        }
    } finally {
        reader.releaseLock();
        parser.feed('[DONE]');
    }
}
