<?php

namespace App\Controller\Settings;

use App\Entity\User;
use App\Service\AuditService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/settings', name: 'settings_')]
#[IsGranted('ROLE_USER')]
class LocaleController extends AbstractController
{
    private const ALLOWED_LOCALES = ['en', 'fr', 'es', 'ar'];
    private const MAX_PAYLOAD_BYTES = 16384; // 16 KB
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'DENY',
        'Cache-Control'          => 'no-store',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?TranslatorInterface $translator = null,
        private readonly ?AuditService $auditService = null,
    ) {}

    private function secureJson(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, array_merge(self::SECURITY_HEADERS, $headers));
    }

    private function trans(string $id, array $parameters = [], ?string $default = null): string
    {
        if ($this->translator !== null) {
            $translated = $this->translator->trans($id, $parameters, 'messages');
            if ($translated !== $id) {
                return $translated;
            }
        }
        return $default ?? $id;
    }

    #[Route('/locale', name: 'locale_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->secureJson([
                'error' => $this->trans('settings.locale.payload_too_large', [], 'Payload exceeds maximum size.'),
                'code'  => 'PAYLOAD_TOO_LARGE',
            ], 413);
        }

        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return $this->secureJson([
                'error' => $this->trans('settings.locale.invalid_json', [], 'Invalid JSON body.'),
                'code'  => 'INVALID_JSON',
            ], 400);
        }

        $locale = $data['locale'] ?? null;

        if (!in_array($locale, self::ALLOWED_LOCALES, true)) {
            return $this->secureJson([
                'error' => $this->trans('settings.locale.invalid_locale', [], 'Invalid locale.'),
                'code'  => 'INVALID_LOCALE',
            ], 422);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            return $this->secureJson([
                'error' => $this->trans('settings.locale.unauthenticated', [], 'Unauthenticated.'),
                'code'  => 'UNAUTHENTICATED',
            ], 401);
        }

        $user->locale = $locale;
        $this->em->flush();

        $this->auditService?->log(
            toolName: 'locale_change',
            project: null,
            input: ['locale' => $locale],
            output: ['success' => true],
            status: 'success',
            createdBy: $user->getUserIdentifier(),
            source: 'user_preferences'
        );
        $this->auditService?->flush();

        if (str_contains((string) $request->headers->get('Accept'), 'application/json')) {
            return $this->secureJson(['success' => true, 'locale' => $locale]);
        }

        $referer = $request->headers->get('referer', '/');
        if (str_starts_with($referer, 'http://') || str_starts_with($referer, 'https://')) {
            $parsed = parse_url($referer);
            $host = $parsed['host'] ?? '';
            if ($host !== '' && $host !== $request->getHost()) {
                $referer = '/';
            }
        }
        return $this->redirect($referer);
    }
}
