import AppLogoIcon from './app-logo-icon';

export default function AppLogo() {
    const appName = import.meta.env.APP_NAME ?? 'JamboApi';

    return (
        <>
            <div>
                <AppLogoIcon className="size-7 group-has-data-[state=collapsed]/sidebar-wrapper:size-8 text-primary block mx-auto" />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm group-has-data-[state=collapsed]/sidebar-wrapper:hidden">
                <span className="mb-0.5 truncate leading-none font-semibold">
                    {appName}
                </span>
            </div>
        </>
    );
}
