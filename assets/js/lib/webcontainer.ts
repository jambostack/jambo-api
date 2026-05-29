// assets/js/lib/webcontainer.ts
import type { WebContainer, FileSystemTree } from '@webcontainer/api';
import { statusStore, previewUrlStore } from '../stores/workbench';

let wcInstance: WebContainer | null = null;
let bootPromise: Promise<WebContainer> | null = null;

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

export async function mountAndRun(
    files: Record<string, string>,
    installCommand: string,
    devCommand: string,
    onServerReady: (url: string) => void,
    onOutput: (line: string) => void,
): Promise<void> {
    const wc = await bootWebContainer();

    await wc.mount(toFileSystemTree(files));

    statusStore.set('wc-installing');
    onOutput('$ ' + installCommand);

    const [cmd, ...args] = installCommand.split(' ');
    const installProcess = await wc.spawn(cmd, args);

    installProcess.output.pipeTo(new WritableStream({
        write(data) { onOutput(data); }
    }));

    const exitCode = await installProcess.exit;
    if (exitCode !== 0) {
        statusStore.set('error');
        throw new Error(`npm install failed with exit code ${exitCode}`);
    }

    statusStore.set('wc-running');
    onOutput('$ ' + devCommand);

    const [devCmd, ...devArgs] = devCommand.split(' ');
    const devProcess = await wc.spawn(devCmd, devArgs);

    devProcess.output.pipeTo(new WritableStream({
        write(data) { onOutput(data); }
    }));

    wc.on('server-ready', (_port: number, url: string) => {
        previewUrlStore.set(url);
        statusStore.set('wc-running');
        onServerReady(url);
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
