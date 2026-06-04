<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reçoit les webhooks Jambo et déclenche un rebuild du site statique jambostack.site.
 * Sécurisé par signature HMAC identique à celle émise par SendWebhookMessageHandler.
 */
#[Route('/webhook/rebuild-site', name: 'webhook_rebuild_site', methods: ['POST'])]
class WebhookRebuildController extends AbstractController
{
    public function __construct(
        private readonly string $rebuildSecret,
        private readonly string $rebuildCommand,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $body      = $request->getContent();
        $signature = $request->headers->get('X-JamboApi-Signature', '');

        if (!$this->verifySignature($body, $signature)) {
            return $this->json(['error' => 'Invalid signature'], 401);
        }

        $this->triggerRebuild();

        return $this->json(['status' => 'rebuild triggered']);
    }

    private function verifySignature(string $body, string $signature): bool
    {
        if ($this->rebuildSecret === '' || $this->rebuildSecret === 'disabled') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $body, $this->rebuildSecret);

        return hash_equals($expected, $signature);
    }

    private function triggerRebuild(): void
    {
        if ($this->rebuildCommand === '' || $this->rebuildCommand === 'disabled') {
            return;
        }

        // Écrit un fichier flag lu par un cron toutes les minutes.
        // exec() étant désactivé en PHP-FPM, on ne peut pas lancer directement le build.
        $flagFile = sys_get_temp_dir() . '/jambo-site-rebuild.flag';
        file_put_contents($flagFile, time() . "\n");
    }
}
