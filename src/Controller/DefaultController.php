<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends AbstractController
{
    #[Route('/{reactRouting}', name: 'app_home', requirements: ['reactRouting' => '^(?!api).+'], defaults: ['reactRouting' => null], priority: -10)]
    public function index(Request $request): Response
    {
        // Construct basic Inertia page object manually since bundle is not compatible with SF8
        $page = [
            'component' => 'Index',
            'props' => [
                'appName' => 'JamboAPI CMS',
            ],
            'url' => $request->getRequestUri(),
            'version' => null,
        ];

        // If it's an Inertia request, return JSON
        if ($request->headers->get('X-Inertia')) {
            return $this->json($page, 200, ['X-Inertia' => 'true']);
        }

        // Otherwise return full HTML
        return $this->render('app.html.twig', [
            'page' => $page,
        ]);
    }
}
