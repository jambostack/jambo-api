<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;

/**
 * Generates a Ziggy-compatible route manifest from Symfony's RouterInterface.
 * The manifest is shared via Inertia shared props (key: "ziggy") on initial page loads,
 * enabling the ziggy-js npm package to generate URLs on the frontend.
 */
class FrontendRouteManifestService
{
    /**
     * Maps frontend dot-notation route names → Symfony internal route names.
     * Multiple frontend names may map to the same Symfony route (different HTTP methods, same URL).
     */
    private const ALIASES = [
        // Auth / session
        'dashboard'                             => 'dashboard',
        'login'                                 => 'app_login',
        'logout'                                => 'app_logout',
        'register'                              => 'app_register',
        'password.request'                      => 'app_forgot_password',
        'password.email'                        => 'app_forgot_password',
        'password.confirm'                      => 'app_confirm_password',
        'password.store'                        => 'app_reset_password_store',

        // User settings
        'profile.edit'                          => 'settings_profile',
        'profile.update'                        => 'settings_profile',
        'profile.destroy'                       => 'settings_profile',
        'password.update'                       => 'settings_password',
        'settings.appearance'                   => 'settings_appearance',

        // Projects — page navigation
        'projects.show'                         => 'projects_show',
        'projects.destroy'                      => 'projects_show',
        'projects.update'                       => 'projects_settings_project',
        'projects.store'                        => 'api_project_create',
        'project-templates.index'               => 'api_project_template_index',

        // Assets — page navigation
        'assets.index'                          => 'assets_index',

        // Assets — API calls
        'assets.api.index'                      => 'api_media_index',
        'assets.api.show'                       => 'api_media_show',
        'assets.api.update'                     => 'api_media_update',
        'assets.api.destroy'                    => 'api_media_delete',
        'assets.destroy'                        => 'api_media_delete',
        'assets.bulk-destroy'                   => 'api_media_bulk_destroy',
        'assets.crop'                           => 'api_media_crop',

        // Project pages
        'projects.workbench'                    => 'workbench_page',

        // Project settings — pages
        'projects.settings.project'             => 'projects_settings_project',
        'projects.settings.localization'        => 'projects_settings_localization',
        'projects.settings.user-access'         => 'projects_settings_user_access',
        'projects.settings.api-access'          => 'projects_settings_api_access',
        'projects.settings.api-docs'            => 'projects_settings_api_docs',
        'projects.settings.mcp-access'          => 'projects_settings_mcp_access',
        'projects.settings.webhooks'            => 'projects_settings_webhooks',
        'projects.settings.webhook-logs'        => 'projects_settings_webhook_logs',
        'projects.settings.storage'             => 'projects_settings_storage',
        'projects.settings.mailer'              => 'projects_settings_mailer',
        'projects.settings.jwt-ttl'             => 'projects_settings_jwt_ttl',

        // Webhooks — API
        'projects.settings.webhooks.index'      => 'api_webhook_index',
        'projects.settings.webhooks.store'      => 'api_webhook_create',
        'projects.settings.webhooks.update'     => 'api_webhook_update',
        'projects.settings.webhooks.destroy'    => 'api_webhook_delete',

        // Members — API
        'projects.settings.members.add'         => 'api_project_members_add',
        'projects.settings.members.remove'      => 'api_project_members_remove',

        // Locales — API
        'projects.settings.locales.add'         => 'api_project_settings_locale_add',
        'projects.settings.locales.default'     => 'api_project_settings_locale_set_default',
        'projects.settings.locales.delete'      => 'api_project_settings_locale_delete',

        // Collections — pages
        'projects.collections.show'             => 'projects_collections_show',
        'projects.collections.edit'             => 'projects_collections_edit',
        'projects.collections.content.create'   => 'projects_collections_content_create',
        'projects.collections.content.edit'     => 'projects_collections_content_edit',
        'projects.collections.content.trash'    => 'projects_collections_content_trash',

        // Content — API
        'projects.collections.content.destroy'              => 'api_content_delete',
        'projects.collections.content.restore'              => 'api_content_restore',
        'projects.collections.content.forceDestroy'         => 'api_content_force_delete',
        'projects.collections.content.find'                 => 'api_content_index',
        'projects.collections.content.getRelationCollection' => 'api_collection_show',

        // Collections — reorder API
        'projects.collections.reorder'          => 'api_collection_reorder',

        // End Users — pages
        'projects.settings.end-users'           => 'projects_settings_end_users',
        'projects.settings.end-users.schema'   => 'projects_settings_end_users_schema',
        'projects.settings.end-users.create'    => 'projects_settings_end_users_create',
        'projects.settings.end-users.show'      => 'projects_settings_end_users_show',
        'projects.settings.end-users.edit'      => 'projects_settings_end_users_edit',

        // End Users — API (POST/PATCH/DELETE)
        'projects.settings.end-users.store'     => 'projects_settings_end_users_store',
        'projects.settings.end-users.update'    => 'projects_settings_end_users_update',
        'projects.settings.end-users.destroy'   => 'projects_settings_end_users_destroy',
        'projects.settings.end-users.status'    => 'projects_settings_end_users_status',

        // Users
        'users.index'                           => 'users_index',
        'users.roles'                           => 'users_roles',
        'users.permissions'                     => 'users_permissions',

        // UI locale switcher
        'settings_locale_update'                => 'settings_locale_update',
    ];

    /**
     * Custom URI entries for routes that have no single matching Symfony route,
     * or need a URI pattern that differs from the Symfony route pattern.
     */
    private const CUSTOM_URIS = [
        'verification.send'               => 'email/verify',
        'projects.settings.webhooks.logs' => 'projects/{projectId}/settings/webhook-logs',
    ];

    public function __construct(private readonly RouterInterface $router) {}

    public function buildManifest(Request $request): array
    {
        $symfonyRoutes = $this->router->getRouteCollection();
        $routes = [];

        foreach (self::ALIASES as $frontendName => $symfonyName) {
            $symfonyRoute = $symfonyRoutes->get($symfonyName);
            if ($symfonyRoute === null) {
                continue;
            }

            $uri = ltrim($symfonyRoute->getPath(), '/');
            $methods = $symfonyRoute->getMethods() ?: ['GET'];

            preg_match_all('/\{([^}]+)\}/', $uri, $matches);

            $routes[$frontendName] = [
                'uri'        => $uri,
                'methods'    => $methods,
                'parameters' => $matches[1],
                'bindings'   => (object) [],
            ];
        }

        foreach (self::CUSTOM_URIS as $frontendName => $uri) {
            preg_match_all('/\{([^}]+)\}/', $uri, $matches);
            $routes[$frontendName] = [
                'uri'        => $uri,
                'methods'    => ['GET'],
                'parameters' => $matches[1],
                'bindings'   => (object) [],
            ];
        }

        return [
            'url'      => $request->getSchemeAndHttpHost(),
            'port'     => null,
            'defaults' => (object) [],
            'routes'   => $routes,
        ];
    }
}
