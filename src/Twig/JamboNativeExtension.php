<?php

namespace App\Twig;

use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Repository\CollectionRepository;
use App\Repository\ContentEntryRepository;
use App\Service\EavDataFormatterService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class JamboNativeExtension extends AbstractExtension
{
    public function __construct(
        private readonly CollectionRepository $collectionRepository,
        private readonly ContentEntryRepository $entryRepository,
        private readonly EavDataFormatterService $formatter,
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jambo_collection', [$this, 'getCollection']),
            new TwigFunction('jambo_entry', [$this, 'getEntry']),
            new TwigFunction('jambo_setting', [$this, 'getSetting']),
            new TwigFunction('jambo_url', [$this, 'getUrl']),
            new TwigFunction('jambo_asset', [$this, 'getAsset']),
            new TwigFunction('jambo_locale', [$this, 'getLocale']),
        ];
    }

    /**
     * Retourne les entrées publiées d'une collection, formatées pour le rendu Twig.
     *
     * Usage: {% for post in jambo_collection('blog') %} {{ post.title }} {% endfor %}
     */
    public function getCollection(Project $project, string $collectionSlug, int $limit = 20): array
    {
        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return [];
        }

        $entries = $this->entryRepository->findByCollectionPaginated(
            $collection, 1, $limit, null, 'published'
        );

        return array_map(fn (ContentEntry $e) => $this->formatter->formatEntry($e), $entries);
    }

    /**
     * Retourne une entrée spécifique par son slug EAV.
     *
     * ContentEntry ne possede pas de propriete "slug" directe.
     * Le slug est stocke dans les valeurs EAV (champ de type "slug").
     * On parcourt les entrees publiees de la collection et on filtre
     * par la valeur formatee du champ slug.
     *
     * Usage: {{ jambo_entry('blog', 'mon-article').title }}
     */
    public function getEntry(Project $project, string $collectionSlug, string $entrySlug): ?array
    {
        $collection = $this->collectionRepository->findOneByProjectAndSlug($project, $collectionSlug);
        if ($collection === null) {
            return null;
        }

        // On recupere un lot suffisant d'entrees publiees pour trouver l'entree par slug EAV.
        $entries = $this->entryRepository->findByCollectionPaginated(
            $collection, 1, 100, null, 'published'
        );

        foreach ($entries as $entry) {
            $formatted = $this->formatter->formatEntry($entry);
            if (($formatted['slug'] ?? '') === $entrySlug) {
                return $formatted;
            }
        }

        return null;
    }

    /**
     * Retourne une variable de configuration globale.
     * Pour l'instant, stub -- retourne null.
     */
    public function getSetting(Project $project, string $key): mixed
    {
        // FUTUR: acces a une table de settings par projet
        return null;
    }

    /**
     * Genere une URL relative pour un chemin interne.
     */
    public function getUrl(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /**
     * Genere le chemin vers un asset (fichiers uploades).
     * Pour l'instant, retourne le chemin brut.
     */
    public function getAsset(string $path): string
    {
        return '/assets/' . ltrim($path, '/');
    }

    /**
     * Retourne la locale courante.
     */
    public function getLocale(): string
    {
        return \Locale::getDefault();
    }
}
