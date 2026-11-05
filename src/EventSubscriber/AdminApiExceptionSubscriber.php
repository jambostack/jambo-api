<?php

namespace App\EventSubscriber;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Garantit des réponses JSON {"error": "..."} sécurisées pour toute exception
 * levée sous /admin-api (notamment les 403 d'autorisation, 404, et masquage des 500 en prod).
 */
class AdminApiExceptionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
        private LoggerInterface $logger = new NullLogger(),
        private ?TranslatorInterface $translator = null,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => 'onException'];
    }

    public function onException(ExceptionEvent $event): void
    {
        $path = $event->getRequest()->getPathInfo();
        if (!str_starts_with($path, '/admin-api')) {
            return;
        }

        $e = $event->getThrowable();
        $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

        if ($status >= 500) {
            $this->logger->error('Admin API error: ' . $e->getMessage(), [
                'exception' => $e,
                'path' => $path,
            ]);
        }

        $message = $e->getMessage();
        if ($status >= 500 && $this->environment === 'prod') {
            $message = $this->translator?->trans('api.unexpected_error', [], 'messages') ?? 'An unexpected error occurred.';
        }

        $event->setResponse(new JsonResponse(['error' => $message], $status));
    }
}
