<?php

namespace App\MessageHandler;

use App\Message\DeployHostedAppMessage;
use App\Repository\WorkbenchProjectRepository;
use App\Service\Cloud\HostedAppService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DeployHostedAppMessageHandler
{
    public function __construct(
        private readonly WorkbenchProjectRepository $workbenchRepository,
        private readonly HostedAppService $hostedAppService,
    ) {}

    public function __invoke(DeployHostedAppMessage $message): void
    {
        $workbench = $this->workbenchRepository->find($message->workbenchProjectId);
        if ($workbench === null) {
            return;
        }

        // Builds the image and runs the container. Failures are captured on the
        // HostedApp (status FAILED + lastError) by deploy() itself, so the
        // message is considered handled and is not retried on app-level errors.
        $this->hostedAppService->deploy($workbench);
    }
}
