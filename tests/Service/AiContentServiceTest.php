<?php

namespace App\Tests\Service;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Repository\AppSettingsRepository;
use App\Service\AiContentService;
use App\Service\AuditService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class AiContentServiceTest extends TestCase
{
    /**
     * Crée un service avec des providers IA configurés ou non.
     */
    private function createService(bool $withProvider = true): AiContentService
    {
        $appSettingsRepo = $this->createMock(AppSettingsRepository::class);
        $appSettings = new \App\Entity\AppSettings();

        if ($withProvider) {
            $appSettings->aiProviders = [
                'openai' => [
                    'enabled' => true,
                    'key'     => 'sk-test-key',
                    'model'   => 'gpt-4o',
                    'url'     => '',
                ],
            ];
        } else {
            $appSettings->aiProviders = [
                'openai' => [
                    'enabled' => false,
                    'key'     => '',
                    'model'   => '',
                    'url'     => '',
                ],
            ];
        }

        $appSettingsRepo->method('getOrCreate')->willReturn($appSettings);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $audit = $this->createMock(AuditService::class);
        $security = $this->createMock(Security::class);

        return new AiContentService($appSettingsRepo, $httpClient, $audit, $security);
    }

    public function testIsConfiguredReturnsFalseWithoutProvider(): void
    {
        $service = $this->createService(false);

        // En appelant getCapabilities, on vérifie que text/available sont false
        $caps = $service->getCapabilities();

        $this->assertFalse($caps['text'], 'Sans provider, la capacite text doit etre false');
        $this->assertFalse($caps['available'], 'Sans provider, available doit etre false');
    }

    public function testGetAvailableProviders(): void
    {
        $service = $this->createService(true);

        $caps = $service->getCapabilities();

        $this->assertTrue($caps['text'], 'Avec un provider openai, text doit etre true');
        $this->assertTrue($caps['available'], 'Avec un provider, available doit etre true');
        $this->assertSame('openai', $caps['provider']);
        $this->assertSame('gpt-4o', $caps['model']);
    }

    public function testGenerateContentWithNoProvider(): void
    {
        $service = $this->createService(false);

        // getAvailableModels() est un getter statique, il retourne toujours la liste
        $models = $service->getAvailableModels();
        $this->assertArrayHasKey('providers', $models);
        $this->assertArrayHasKey('defaults', $models);
        $this->assertNotEmpty($models['providers']);

        // generateContent lance une RuntimeException si aucun provider n'est actif
        $project = new Project();
        $project->name = 'Test';
        $project->defaultLocale = 'fr';

        $collection = new Collection();
        $collection->name = 'Articles';
        $collection->slug = 'articles';
        $collection->project = $project;
        $collection->fields = new ArrayCollection();

        $field = new Field();
        $field->name = 'Titre';
        $field->slug = 'title';
        $field->type = 'text';
        $field->collection = $collection;
        $collection->fields->add($field);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Aucun fournisseur IA activ');

        $service->generateContent('Un article de blog', $collection);
    }

    public function testGetAvailableModelsReturnsExpectedStructure(): void
    {
        $service = $this->createService(false);

        $models = $service->getAvailableModels();

        $this->assertIsArray($models);
        $this->assertArrayHasKey('providers', $models);
        $this->assertArrayHasKey('defaults', $models);

        // Au moins un fournisseur connu
        $this->assertArrayHasKey('openai', $models['providers']);
        $this->assertArrayHasKey('anthropic', $models['providers']);

        // Les modèles par défaut incluent fast/smart/local
        $this->assertArrayHasKey('fast', $models['defaults']);
        $this->assertArrayHasKey('smart', $models['defaults']);
        $this->assertArrayHasKey('local', $models['defaults']);
    }

    public function testGeneratePlaceholderReturnsSvg(): void
    {
        $service = $this->createService(false);

        $svg = $service->generatePlaceholder('Mon image', '400', '300');

        $this->assertStringStartsWith('data:image/svg+xml,', $svg);
        $this->assertStringContainsString('Mon%20image', $svg);
        $this->assertStringContainsString('width%3D%22400%22', $svg);
        $this->assertStringContainsString('height%3D%22300%22', $svg);
    }

    public function testGenerateImageReturnsNullWithoutImageProvider(): void
    {
        // Seulement un provider openai (texte), pas de capacité image sans clé réelle
        $service = $this->createService(true);

        $result = $service->generateImage('A test prompt');

        // Retourne null car l'appel HTTP vers OpenAI échouera (mock non configuré)
        // Mais la méthode passe par getCapabilities qui vérifie images=true pour openai,
        // puis tente l'appel HTTP qui va lancer une exception capturée → null
        $this->assertNull($result);
    }
}
