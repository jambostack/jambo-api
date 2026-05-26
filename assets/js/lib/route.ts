import { route as ziggyRoute } from 'ziggy-js';

/**
 * URL generator backed by the Ziggy route manifest injected by PHP on the initial page load.
 * Falls back to '#' with a console warning if the route name is not in the manifest,
 * matching the previous polyfill behaviour so no runtime crashes occur.
 */
export function route(name: string, params?: any, absolute?: boolean): string {
    try {
        return ziggyRoute(name, params, absolute, window.Ziggy);
    } catch {
        console.warn(`[route] Unknown route: "${name}"`);
        return '#';
    }
}
