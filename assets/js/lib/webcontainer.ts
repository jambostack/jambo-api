import type { WebContainer, FileSystemTree } from '@webcontainer/api';
import { statusStore, previewUrlStore, buildErrorsStore } from '../stores/workbench';

// ── ANSI / terminal sanitization ────────────────────────────────────────────

/**
 * Strips ANSI escape sequences (CSI, OSC, color codes) and normalizes
 * carriage-return artifacts (npm spinners) from raw terminal output.
 *
 * Handles:
 *  - CSI sequences:   ESC [ ... m (SGR), ESC [ ... K (erase), ESC [ ... G (cursor), etc.
 *  - OSC sequences:   ESC ] ... BEL  or  ESC ] ... ST
 *  - Isolated ESC / BEL characters
 *  - Carriage-return spinner patterns: lines that end with \\r are spinner
 *    overwrites; we keep only the *last* segment before a \\n.
 */
function sanitizeTerminalOutput(raw: string): string {
    return raw
        // Full ANSI CSI sequences: ESC [ digits/semicolons + letter
        .replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '')
        // OSC terminated by BEL: ESC ] ... BEL
        .replace(/\x1b\][^\x07]*\x07/g, '')
        // OSC terminated by ST: ESC ] ... ESC \
        .replace(/\x1b\][^\x1b]*\x1b\\/g, '')
        // Any remaining isolated ESC or BEL
        .replace(/\x1b/g, '')
        .replace(/\x07/g, '')
        // Collapse CRLF → LF, then split on LF to handle spinner lines
        .replace(/\r\n/g, '\n')
        .split('\n')
        .map(line => {
            // A line containing bare \\r is a spinner animation: multiple
            // frames were written on the same terminal row. Keep only the
            // last frame (text after the final \\r).
            const lastCr = line.lastIndexOf('\r');
            if (lastCr >= 0) {
                const cleaned = line.slice(lastCr + 1).trimEnd();
                return cleaned || null;  // return null for empty lines to filter out
            }
            return line;
        })
        .filter((line): line is string => line !== null && line !== '')
        .join('\n');
}

/**
 * Ensures a chunk of data from a WebContainer process output stream is a
 * readable string, then sanitises ANSI / control characters.
 */
function processOutputChunk(data: string | Uint8Array): string {
    const raw = typeof data === 'string' ? data : new TextDecoder().decode(data);
    return sanitizeTerminalOutput(raw);
}

let wcInstance: WebContainer | null = null;
let bootPromise: Promise<WebContainer> | null = null;
let activeInstallProcess: Awaited<ReturnType<WebContainer['spawn']>> | null = null;
let activeDevProcess: Awaited<ReturnType<WebContainer['spawn']>> | null = null;
let cleanupServerReady: (() => void) | null = null;

export function resetWebContainer(): void {
    // Tuer les processus en cours avant de reset
    try { activeInstallProcess?.kill(); } catch { /* ignore */ }
    try { activeDevProcess?.kill(); } catch { /* ignore */ }
    activeInstallProcess = null;
    activeDevProcess = null;

    // Nettoyer le listener server-ready
    if (cleanupServerReady) {
        cleanupServerReady();
        cleanupServerReady = null;
    }

    wcInstance = null;
    bootPromise = null;
    previewUrlStore.set(null);
    buildErrorsStore.set([]);
}

export function isWebContainerSupported(): boolean {
    return typeof SharedArrayBuffer !== 'undefined';
}

export async function bootWebContainer(): Promise<WebContainer> {
    if (wcInstance) return wcInstance;
    if (bootPromise) return bootPromise;

    const { WebContainer: WC } = await import('@webcontainer/api');
    statusStore.set('wc-booting');

    bootPromise = WC.boot().then(wc => {
        wcInstance = wc;
        bootPromise = null;
        return wc;
    }).catch(err => {
        bootPromise = null;
        throw err;
    });

    return bootPromise;
}

export function toFileSystemTree(files: Record<string, string>): FileSystemTree {
    const tree: FileSystemTree = {};

    for (const [filePath, content] of Object.entries(files)) {
        const parts = filePath.split('/');
        let node: FileSystemTree = tree;

        for (let i = 0; i < parts.length - 1; i++) {
            const part = parts[i];
            if (!node[part]) {
                node[part] = { directory: {} };
            }
            node = (node[part] as { directory: FileSystemTree }).directory;
        }

        const fileName = parts[parts.length - 1];
        node[fileName] = { file: { contents: content } };
    }

    return tree;
}

const ERROR_PATTERNS = [
    /Error:\s*(.+)/gi,
    /Module not found:\s*(.+)/gi,
    /Cannot find module\s+(.+)/gi,
    /SyntaxError:\s*(.+)/gi,
    /TypeError:\s*(.+)/gi,
    /Failed to compile/i,
    /BUILD FAILED/i,
    /Compilation failed/i,
    /Unexpected token/i,
    /is not a function/i,
    /is not defined/i,
];

function detectBuildErrors(output: string): string[] {
    const errors: string[] = [];
    const lines = output.split('\n');
    for (const line of lines) {
        for (const pattern of ERROR_PATTERNS) {
            // Reset lastIndex pour les regex avec flag /g (évite les faux négatifs)
            pattern.lastIndex = 0;
            if (pattern.test(line)) {
                errors.push(line.trim());
                break;
            }
        }
    }
    return errors;
}

export async function mountAndRun(
    files: Record<string, string>,
    installCommand: string,
    devCommand: string,
    onServerReady: (url: string) => void,
    onOutput: (line: string) => void,
): Promise<void> {
    const wc = await bootWebContainer();
    buildErrorsStore.set([]);

    await wc.mount(toFileSystemTree(files));

    statusStore.set('wc-installing');
    onOutput('$ ' + installCommand);

    const [cmd, ...args] = installCommand.split(' ');
    const installProcess = await wc.spawn(cmd, args);
    activeInstallProcess = installProcess;

    let installOutput = '';
    installProcess.output.pipeTo(new WritableStream({
        write(data) {
            const clean = processOutputChunk(data);
            installOutput += clean;
            if (clean) onOutput(clean);
        }
    })).catch(err => {
        console.warn('[WebContainer] install pipeTo error:', err);
        onOutput(`[stream error] ${(err as Error).message}`);
    });

    // Timeout de sécurité : 5 minutes max pour l'install
    const installTimeout = setTimeout(() => {
        console.warn('[WebContainer] install timeout — killing process');
        try { installProcess.kill(); } catch { /* déjà mort */ }
    }, 300_000);

    const installExitCode = await installProcess.exit;
    clearTimeout(installTimeout);
    activeInstallProcess = null;
    const installErrors = detectBuildErrors(installOutput);
    if (installErrors.length > 0) {
        buildErrorsStore.set(installErrors);
    }
    if (installExitCode !== 0) {
        statusStore.set('error');
        throw new Error(`npm install failed with exit code ${installExitCode}`);
    }

    statusStore.set('wc-running');
    onOutput('$ ' + devCommand);

    const [devCmd, ...devArgs] = devCommand.split(' ');
    const devProcess = await wc.spawn(devCmd, devArgs);
    activeDevProcess = devProcess;

    devProcess.output.pipeTo(new WritableStream({
        write(data) {
            const clean = processOutputChunk(data);
            if (clean) onOutput(clean);
            const errors = detectBuildErrors(clean);
            if (errors.length > 0) {
                const current = buildErrorsStore.get();
                buildErrorsStore.set([...current, ...errors].slice(-20));
            }
        }
    })).catch(err => {
        console.warn('[WebContainer] dev pipeTo error:', err);
        onOutput(`[stream error] ${(err as Error).message}`);
    });

    // Nettoyer l'ancien listener avant d'en ajouter un nouveau
    if (cleanupServerReady) cleanupServerReady();
    wc.on('server-ready', (_port: number, url: string) => {
        previewUrlStore.set(url);
        statusStore.set('wc-running');
        buildErrorsStore.set([]);
        onServerReady(url);
    });

    // Écouter les erreurs JS dans l'iframe (inspiré de bolt.diy)
    wc.on('preview-message', (message) => {
        if (
            message.type === 'PREVIEW_UNCAUGHT_EXCEPTION' ||
            message.type === 'PREVIEW_UNHANDLED_REJECTION'
        ) {
            const title = message.type === 'PREVIEW_UNHANDLED_REJECTION'
                ? 'Unhandled Promise Rejection'
                : 'Uncaught Exception';
            const errMsg = 'message' in message
                ? (message as { message: string }).message
                : 'Unknown error';
            onOutput(`[preview] ${title}: ${errMsg}`);
        }
    });
    cleanupServerReady = () => {
        cleanupServerReady = null;
    };

    devProcess.exit.then(code => {
        activeDevProcess = null;
        if (code !== 0 && code !== null) {
            const current = buildErrorsStore.get();
            if (current.length === 0) {
                buildErrorsStore.set([`Dev server exited with code ${code}`]);
            }
        }
    });
}

export async function writeFile(path: string, content: string): Promise<void> {
    const wc = await bootWebContainer();
    const parts = path.split('/');
    const dir = parts.slice(0, -1).join('/');

    if (dir) {
        await wc.fs.mkdir(dir, { recursive: true });
    }
    await wc.fs.writeFile(path, content);
}
