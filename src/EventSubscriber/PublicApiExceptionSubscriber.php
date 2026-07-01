<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Garantit des réponses JSON {"error": "...", "code": "..."} pour toute
 * exception levée sous /api/ (l'API publique).
 */
class PublicApiExceptionSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Ne concerne que l'API publique (/api/), pas /admin-api ni les autres routes
        if (!str_starts_with($path, '/api/')) {
            return;
        }

        $e = $event->getThrowable();
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        // Essayer d'extraire un code d'erreur plus spécifique
        $errorCode = match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            422 => 'VALIDATION_ERROR',
            429 => 'RATE_LIMITED',
            default => 'INTERNAL_ERROR',
        };

        // En mode dev, on garde le message original ; en prod on le simplifie
        $message = $e->getMessage();
        if ($status === 500 && $_SERVER['APP_ENV'] === 'prod') {
            $message = 'An unexpected error occurred.';
            $errorCode = 'INTERNAL_ERROR';
        }

        $payload = [
            'error' => $message,
            'code'  => $errorCode,
        ];

        // Ajouter les infos de validation si présentes (ViolationException)
        if (method_exists($e, 'getViolations')) {
            $violations = [];
            foreach ($e->getViolations() as $v) {
                $violations[$v->getPropertyPath()] = $v->getMessage();
            }
            if ($violations !== []) {
                $payload['errors'] = $violations;
            }
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }
}
