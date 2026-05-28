<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DefaultController extends InertiaController
{
    #[Route('/{reactRouting}', name: 'app_home', requirements: ['reactRouting' => '^(?!api).+'], defaults: ['reactRouting' => null], priority: -10)]
    public function index(Request $request): Response
    {
        return $this->inertia($request, 'dashboard', [
            'appName' => 'JamboAPI CMS',
        ]);
    }
}
