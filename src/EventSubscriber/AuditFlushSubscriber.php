<?php

namespace App\EventSubscriber;

use App\Service\AuditService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AuditFlushSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private AuditService $audit,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $this->audit->flush();
    }
}
