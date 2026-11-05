<?php

namespace App\EventSubscriber;

use App\Service\Error\JsonErrorHandler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Garantit des réponses JSON {"error": "...", "code": "..."} pour toute
 * exception levée sous /api/ (l'API publique) avec en-têtes de sécurité et anti-cache stricts.
 */
class PublicApiExceptionSubscriber implements EventSubscriberInterface
{
    private JsonErrorHandler $errorHandler;

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
        private LoggerInterface $logger = new NullLogger(),
        private ?TranslatorInterface $translator = null,
        ?JsonErrorHandler $errorHandler = null,
    ) {
        $this->errorHandler = $errorHandler ?? new JsonErrorHandler($this->environment, $this->logger, $this->translator);
    }

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

        $response = $this->errorHandler->createResponse($event->getThrowable(), $request);
        $event->setResponse($response);
    }
}
