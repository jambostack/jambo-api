<?php

namespace App\Controller;

use App\Service\EavDataFormatterService;
use App\Service\Share\ShareService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicShareController extends AbstractController
{
    public function __construct(
        private readonly ShareService $shares,
        private readonly EavDataFormatterService $formatter,
    ) {}

    #[Route('/share/{token}', name: 'public_share_show', requirements: ['token' => '[A-Za-z0-9_]+'], defaults: ['_format' => 'html'], methods: ['GET'])]
    #[Route('/share/{token}.json', name: 'public_share_show_json', requirements: ['token' => '[A-Za-z0-9_]+'], defaults: ['_format' => 'json'], methods: ['GET'])]
    public function show(string $token, string $_format, Request $request): Response
    {
        $share = $this->shares->resolve($token);

        if ($share === null || $share->isRevoked()) {
            return $this->renderError(404, 'invalid');
        }
        if ($share->isExpired()) {
            return $this->renderError(410, 'expired');
        }
        $entry = $share->entry;
        if ($entry === null || $entry->isDeleted()) {
            return $this->renderError(404, 'invalid');
        }

        $this->shares->recordAccess($share);

        $data = $this->formatter->formatEntry($entry);

        $wantsJson = $_format === 'json'
            || str_contains((string) $request->headers->get('Accept'), 'application/json');

        if ($wantsJson) {
            return new JsonResponse($data);
        }

        return $this->render('share/show.html.twig', [
            'entry'      => $data,
            'collection' => $entry->collection?->name ?? $data['collection'],
        ]);
    }

    private function renderError(int $status, string $reason): Response
    {
        return $this->render('share/error.html.twig', ['reason' => $reason], new Response('', $status));
    }
}
