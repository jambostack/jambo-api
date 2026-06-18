<?php

namespace App\Controller\Auth;

use App\Service\SocialLoginService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SocialLoginController extends AbstractController
{
    public function __construct(
        private SocialLoginService $socialLogin,
    ) {}

    #[Route('/connect/{provider}', name: 'connect_start')]
    public function connect(string $provider): Response
    {
        if (!in_array($provider, ['google', 'microsoft', 'github', 'gitlab'], true)) {
            throw $this->createNotFoundException('Unknown provider.');
        }

        $redirectUri = $this->generateUrl(
            'connect_check',
            ['provider' => $provider],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $url = $this->socialLogin->getRedirectUrl($provider, $redirectUri);
        return $this->redirect($url);
    }

    #[Route('/connect/{provider}/check', name: 'connect_check')]
    public function check(string $provider): Response
    {
        // Jamais exécuté — intercepté par SocialLoginAuthenticator
        throw new \LogicException('This route is handled by SocialLoginAuthenticator.');
    }
}
