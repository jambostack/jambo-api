import type { route as ZiggyRoute, Config as ZiggyConfig } from 'ziggy-js';

declare global {
    const route: typeof ZiggyRoute;
    interface Window {
        route: typeof ZiggyRoute;
        Ziggy: ZiggyConfig;
    }
}
