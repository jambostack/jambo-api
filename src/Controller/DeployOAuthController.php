<?php
// src/Controller/DeployOAuthController.php
namespace App\Controller;

use App\Entity\DeployToken;
use App\Service\Deploy\DeployService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/api/deploy/oauth', name: 'deploy_oauth_')]
class DeployOAuthController extends AbstractController
{
    public function __construct(
        private readonly DeployService $deployService,
    ) {}

    /**
     * Redirect user to provider's OAuth consent page.
     * state = base64(returnUrl) so we know where to redirect after callback.
     */
    #[Route('/connect/{provider}', name: 'connect', methods: ['GET'])]
    public function connect(string $provider, Request $request): Response
    {
        if (!in_array($provider, DeployToken::PROVIDERS, true)) {
            throw $this->createNotFoundException("Unknown provider: {$provider}");
        }

        $p = $this->deployService->findProvider($provider);
        if ($p === null) {
            throw $this->createNotFoundException("Provider not configured: {$provider}");
        }

        $returnUrl   = $request->query->get('return', '/');
        $state       = base64_encode(json_encode(['return' => $returnUrl, 'provider' => $provider]));
        $callbackUrl = $this->generateUrl('deploy_oauth_callback', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL);

        return new RedirectResponse($p->getOAuthUrl($callbackUrl, $state));
    }

    /**
     * Provider redirects back here with ?code=... and ?state=...
     */
    #[Route('/callback/{provider}', name: 'callback', methods: ['GET'])]
    public function callback(string $provider, Request $request): Response
    {
        if (!in_array($provider, DeployToken::PROVIDERS, true)) {
            throw $this->createNotFoundException();
        }

        $p = $this->deployService->findProvider($provider);
        if ($p === null) throw $this->createNotFoundException();

        $code  = $request->query->get('code', '');
        $state = $request->query->get('state', '');
        $error = $request->query->get('error', '');

        if ($error !== '') {
            return $this->redirect('/#deploy-error=' . urlencode($error));
        }

        if ($code === '') {
            return $this->redirect('/#deploy-error=no_code');
        }

        $callbackUrl = $this->generateUrl('deploy_oauth_callback', ['provider' => $provider], UrlGeneratorInterface::ABSOLUTE_URL);

        try {
            $tokenData  = $p->exchangeCode($code, $callbackUrl);
            $plainToken = $tokenData['access_token'] ?? throw new \RuntimeException('No access_token in response');
            $expiresIn  = isset($tokenData['expires_in']) ? (int) $tokenData['expires_in'] : null;

            /** @var \App\Entity\User $user */
            $user = $this->getUser();
            $this->deployService->storeToken($user, $provider, $plainToken, $expiresIn);
        } catch (\Throwable $e) {
            return $this->redirect('/#deploy-error=' . urlencode($e->getMessage()));
        }

        try {
            $stateData = json_decode(base64_decode($state), true) ?? [];
            $returnUrl = $stateData['return'] ?? '/';
        } catch (\Throwable) {
            $returnUrl = '/';
        }

        return $this->redirect($returnUrl . '?deploy_connected=' . $provider);
    }

    /**
     * Disconnect (delete token) for a provider.
     */
    #[Route('/disconnect/{provider}', name: 'disconnect', methods: ['POST'])]
    public function disconnect(
        string $provider,
        \App\Repository\DeployTokenRepository $tokenRepo,
        \Doctrine\ORM\EntityManagerInterface $em
    ): Response {
        if (!in_array($provider, DeployToken::PROVIDERS, true)) {
            return $this->json(['error' => 'Provider invalide'], 422);
        }

        /** @var \App\Entity\User $user */
        $user  = $this->getUser();
        $token = $tokenRepo->findForUser($user, $provider);

        if ($token) {
            $em->remove($token);
            $em->flush();
        }

        return $this->json(['disconnected' => $provider]);
    }
}
