<?php

namespace App\Controller\Settings;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Spec OpenAPI 3 de l'Admin API (base /admin-api).
 *
 * Servie sous /api/settings (firewall session) afin que le Swagger UI de la page
 * « Jetons d'accès » puisse la charger sans PAT. Les requêtes « Try it out »
 * partent vers /admin-api et sont authentifiées par le Bearer PAT (bouton Authorize).
 */
class AdminApiOpenApiController extends AbstractController
{
    #[Route('/api/settings/admin-api/openapi.json', name: 'api_settings_admin_api_openapi', methods: ['GET'])]
    public function spec(): JsonResponse
    {
        return new JsonResponse($this->buildSpec());
    }

    private function buildSpec(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title'       => 'Jambo — Admin API',
                'description' => "API d'administration globale : projets, collections, champs et jetons. "
                    . "Authentification par **Personal Access Token** (en-tête `Authorization: Bearer <jeton>`). "
                    . "Cliquez sur **Authorize** et collez un jeton créé sur cette page.",
                'version'     => '1.0.0',
            ],
            'servers' => [
                ['url' => '/admin-api', 'description' => 'Base de l\'Admin API'],
            ],
            'components' => [
                'securitySchemes' => [
                    'PersonalAccessToken' => [
                        'type'        => 'http',
                        'scheme'      => 'bearer',
                        'description' => 'Personal Access Token (préfixe jbo_pat_) créé sur la page Jetons d\'accès.',
                    ],
                ],
                'schemas' => $this->schemas(),
            ],
            'security' => [['PersonalAccessToken' => []]],
            'tags' => [
                ['name' => 'Projets',     'description' => 'Création et gestion des projets'],
                ['name' => 'Collections', 'description' => 'Schéma : collections (scope schema:write en écriture)'],
                ['name' => 'Champs',      'description' => 'Schéma : champs des collections (scope schema:write en écriture)'],
                ['name' => 'Jetons',      'description' => 'Gestion de vos Personal Access Tokens'],
            ],
            'paths' => array_merge(
                $this->projectPaths(),
                $this->collectionPaths(),
                $this->fieldPaths(),
                $this->tokenPaths(),
            ),
        ];
    }

    private function schemas(): array
    {
        return [
            'Error' => [
                'type'       => 'object',
                'properties' => ['error' => ['type' => 'string', 'example' => 'Missing scope: schema:write']],
            ],
            'Project' => [
                'type'       => 'object',
                'properties' => [
                    'uuid'          => ['type' => 'string', 'format' => 'uuid'],
                    'name'          => ['type' => 'string', 'example' => 'Projet Demo'],
                    'defaultLocale' => ['type' => 'string', 'example' => 'en'],
                    'locales'       => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['en', 'fr']],
                    'publicApi'     => ['type' => 'boolean', 'example' => true],
                ],
            ],
            'Field' => [
                'type'       => 'object',
                'properties' => [
                    'name'       => ['type' => 'string', 'example' => 'title'],
                    'slug'       => ['type' => 'string', 'example' => 'title'],
                    'type'       => ['type' => 'string', 'example' => 'text'],
                    'isRequired' => ['type' => 'boolean', 'example' => false],
                    'order'      => ['type' => 'integer', 'example' => 0],
                    'options'    => ['type' => 'object', 'nullable' => true],
                ],
            ],
            'Collection' => [
                'type'       => 'object',
                'properties' => [
                    'name'        => ['type' => 'string', 'example' => 'Articles'],
                    'slug'        => ['type' => 'string', 'example' => 'articles'],
                    'description' => ['type' => 'string', 'nullable' => true, 'example' => 'Articles de blog'],
                    'isSingleton' => ['type' => 'boolean', 'example' => false],
                    'order'       => ['type' => 'integer', 'example' => 0],
                    'fields'      => [
                        'type'        => 'array',
                        'items'       => ['$ref' => '#/components/schemas/Field'],
                        'description' => 'Présent uniquement sur GET d\'une collection unique.',
                    ],
                ],
            ],
            'Token' => [
                'type'       => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer', 'example' => 1],
                    'name'   => ['type' => 'string', 'example' => 'Déploiement CI'],
                    'scopes' => ['type' => 'array', 'items' => ['type' => 'string'], 'example' => ['schema:write']],
                ],
            ],
        ];
    }

    // ── Réponses réutilisables ───────────────────────────────────────────────

    private function errResponse(string $desc): array
    {
        return ['description' => $desc, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
    }

    /** Réponses d'erreur communes (auth requise + introuvable). */
    private function commonErrors(bool $withScope = false, bool $withValidation = false): array
    {
        $out = [
            '401' => $this->errResponse('Jeton manquant, invalide ou expiré.'),
            '404' => $this->errResponse('Ressource introuvable.'),
        ];
        if ($withScope) {
            $out['403'] = $this->errResponse('Scope manquant sur le jeton (ex. schema:write).');
        }
        if ($withValidation) {
            $out['422'] = $this->errResponse('Corps invalide (ex. name requis, type inconnu).');
        }
        return $out;
    }

    private function uuidParam(): array
    {
        return ['name' => 'uuid', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid'], 'description' => 'UUID du projet'];
    }

    private function slugParam(): array
    {
        return ['name' => 'slug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Slug de la collection'];
    }

    private function fieldSlugParam(): array
    {
        return ['name' => 'fieldSlug', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'description' => 'Slug du champ'];
    }

    private function jsonBody(array $example, array $required): array
    {
        $props = [];
        foreach ($example as $k => $v) {
            $props[$k] = ['example' => $v];
        }
        return [
            'required' => true,
            'content'  => ['application/json' => ['schema' => [
                'type'       => 'object',
                'required'   => $required,
                'properties' => $props,
            ]]],
        ];
    }

    private function dataResponse(string $ref, string $desc, bool $isArray = false): array
    {
        $schema = $isArray
            ? ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => $ref]]]]
            : ['type' => 'object', 'properties' => ['data' => ['$ref' => $ref]]];
        return ['description' => $desc, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    // ── Chemins ──────────────────────────────────────────────────────────────

    private function projectPaths(): array
    {
        return [
            '/projects' => [
                'get' => [
                    'tags' => ['Projets'], 'summary' => 'Liste les projets dont vous êtes membre',
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Project', 'Liste des projets', true)] + $this->commonErrors(),
                ],
                'post' => [
                    'tags' => ['Projets'], 'summary' => 'Crée un projet', 'description' => 'Scope requis : `projects:write`.',
                    'requestBody' => $this->jsonBody(['name' => 'Mon projet', 'defaultLocale' => 'fr', 'locales' => ['fr', 'en']], ['name']),
                    'responses' => ['201' => $this->dataResponse('#/components/schemas/Project', 'Projet créé')] + $this->commonErrors(withScope: true, withValidation: true),
                ],
            ],
            '/projects/{uuid}' => [
                'get' => [
                    'tags' => ['Projets'], 'summary' => 'Détail d\'un projet', 'parameters' => [$this->uuidParam()],
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Project', 'Projet')] + $this->commonErrors(),
                ],
                'patch' => [
                    'tags' => ['Projets'], 'summary' => 'Met à jour un projet', 'description' => 'Scope requis : `projects:write`.', 'parameters' => [$this->uuidParam()],
                    'requestBody' => $this->jsonBody(['name' => 'Nouveau nom'], []),
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Project', 'Projet mis à jour')] + $this->commonErrors(withScope: true),
                ],
                'delete' => [
                    'tags' => ['Projets'], 'summary' => 'Supprime un projet', 'description' => 'Scope requis : `projects:write`.', 'parameters' => [$this->uuidParam()],
                    'responses' => ['204' => ['description' => 'Supprimé']] + $this->commonErrors(withScope: true),
                ],
            ],
        ];
    }

    private function collectionPaths(): array
    {
        return [
            '/projects/{uuid}/collections' => [
                'get' => [
                    'tags' => ['Collections'], 'summary' => 'Liste les collections du projet', 'parameters' => [$this->uuidParam()],
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Collection', 'Liste des collections', true)] + $this->commonErrors(),
                ],
                'post' => [
                    'tags' => ['Collections'], 'summary' => 'Crée une collection', 'description' => 'Scope requis : `schema:write`.', 'parameters' => [$this->uuidParam()],
                    'requestBody' => $this->jsonBody(['name' => 'Articles', 'isSingleton' => false], ['name']),
                    'responses' => ['201' => $this->dataResponse('#/components/schemas/Collection', 'Collection créée')] + $this->commonErrors(withScope: true, withValidation: true),
                ],
            ],
            '/projects/{uuid}/collections/{slug}' => [
                'get' => [
                    'tags' => ['Collections'], 'summary' => 'Détail d\'une collection (avec ses champs)', 'parameters' => [$this->uuidParam(), $this->slugParam()],
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Collection', 'Collection + champs')] + $this->commonErrors(),
                ],
                'patch' => [
                    'tags' => ['Collections'], 'summary' => 'Met à jour une collection', 'description' => 'Scope requis : `schema:write`.', 'parameters' => [$this->uuidParam(), $this->slugParam()],
                    'requestBody' => $this->jsonBody(['name' => 'Nouveau nom', 'description' => '...'], []),
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Collection', 'Collection mise à jour')] + $this->commonErrors(withScope: true, withValidation: true),
                ],
                'delete' => [
                    'tags' => ['Collections'], 'summary' => 'Supprime une collection', 'description' => 'Scope requis : `schema:write`.', 'parameters' => [$this->uuidParam(), $this->slugParam()],
                    'responses' => ['204' => ['description' => 'Supprimée']] + $this->commonErrors(withScope: true),
                ],
            ],
        ];
    }

    private function fieldPaths(): array
    {
        return [
            '/projects/{uuid}/collections/{slug}/fields' => [
                'get' => [
                    'tags' => ['Champs'], 'summary' => 'Liste les champs d\'une collection', 'parameters' => [$this->uuidParam(), $this->slugParam()],
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Field', 'Liste des champs', true)] + $this->commonErrors(),
                ],
                'post' => [
                    'tags' => ['Champs'], 'summary' => 'Ajoute un champ', 'description' => 'Scope requis : `schema:write`.', 'parameters' => [$this->uuidParam(), $this->slugParam()],
                    'requestBody' => $this->jsonBody(['name' => 'Titre', 'type' => 'text', 'is_required' => true], ['name', 'type']),
                    'responses' => ['201' => $this->dataResponse('#/components/schemas/Field', 'Champ créé')] + $this->commonErrors(withScope: true, withValidation: true),
                ],
            ],
            '/projects/{uuid}/collections/{slug}/fields/{fieldSlug}' => [
                'patch' => [
                    'tags' => ['Champs'], 'summary' => 'Met à jour un champ', 'description' => 'Scope requis : `schema:write`.', 'parameters' => [$this->uuidParam(), $this->slugParam(), $this->fieldSlugParam()],
                    'requestBody' => $this->jsonBody(['is_required' => true, 'type' => 'text'], []),
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Field', 'Champ mis à jour')] + $this->commonErrors(withScope: true, withValidation: true),
                ],
                'delete' => [
                    'tags' => ['Champs'], 'summary' => 'Supprime un champ', 'description' => 'Scope requis : `schema:write`.', 'parameters' => [$this->uuidParam(), $this->slugParam(), $this->fieldSlugParam()],
                    'responses' => ['204' => ['description' => 'Supprimé']] + $this->commonErrors(withScope: true),
                ],
            ],
        ];
    }

    private function tokenPaths(): array
    {
        return [
            '/tokens' => [
                'get' => [
                    'tags' => ['Jetons'], 'summary' => 'Liste vos jetons',
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Token', 'Liste des jetons', true)] + $this->commonErrors(),
                ],
                'post' => [
                    'tags' => ['Jetons'], 'summary' => 'Crée un jeton (valeur affichée une seule fois)',
                    'requestBody' => $this->jsonBody(['name' => 'Déploiement CI', 'scopes' => ['schema:write']], ['name']),
                    'responses' => ['201' => $this->dataResponse('#/components/schemas/Token', 'Jeton créé')] + $this->commonErrors(),
                ],
            ],
            '/tokens/{id}' => [
                'patch' => [
                    'tags' => ['Jetons'], 'summary' => 'Met à jour un jeton',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'requestBody' => $this->jsonBody(['name' => 'Nouveau nom', 'scopes' => ['schema:write', 'projects:write']], []),
                    'responses' => ['200' => $this->dataResponse('#/components/schemas/Token', 'Jeton mis à jour')] + $this->commonErrors(),
                ],
                'delete' => [
                    'tags' => ['Jetons'], 'summary' => 'Révoque un jeton',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => ['204' => ['description' => 'Révoqué']] + $this->commonErrors(),
                ],
            ],
        ];
    }
}
