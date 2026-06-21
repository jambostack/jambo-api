export interface PreviewContext {
    entryUuid: string;
    collection: string;
    locale: string;
    token: string;
    projectUuid: string;
}
export interface LivePreviewOptions {
    onInit: (ctx: PreviewContext) => Promise<Record<string, any>>;
    onUpdate: (data: Record<string, any>) => void;
    debug?: boolean;
    targetOrigin?: string;
}
export declare function subscribe(options: LivePreviewOptions): () => void;
interface VisualEditingOptions {
    allowedOrigin: string;
    inlineEditEnabled?: boolean;
    debug?: boolean;
}
/**
 * Initialize visual editing: hover/click on [data-jambo-field] elements
 * sends postMessage to the admin. Returns a cleanup function.
 */
export declare function initVisualEditing(options: VisualEditingOptions): () => void;
export {};
