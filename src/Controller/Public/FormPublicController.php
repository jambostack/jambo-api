<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Repository\FormRepository;
use App\Repository\ProjectRepository;
use App\Service\Form\FormBuilder;
use App\Service\Form\SubmitHandler;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Exception\ValidationFailedException;

class FormPublicController extends AbstractController
{
    public function __construct(
        private readonly ProjectRepository $projectRepo,
        private readonly FormRepository $formRepo,
        private readonly FormBuilder $formBuilder,
        private readonly SubmitHandler $submitHandler,
    ) {}

    #[Route('/{projectUuid}/forms/{slug}', name: 'public_form_show', requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function show(string $projectUuid, string $slug): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $form = $this->formRepo->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$form) {
            return new JsonResponse(['error' => 'Form not found.'], 404);
        }

        // Validation de la définition du formulaire
        $definition = $this->formBuilder->validateDefinition($form->fields);

        return $this->json([
            'id' => $form->id,
            'name' => $form->name,
            'slug' => $form->slug,
            'fields' => $form->fields,
            'steps' => $form->steps,
            'definition_valid' => $definition['valid'],
            'definition_errors' => $definition['errors'],
        ]);
    }

    #[Route('/{projectUuid}/forms/{slug}/submit', name: 'public_form_submit', methods: ['POST'], requirements: ['projectUuid' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'])]
    public function submit(string $projectUuid, string $slug, Request $request): JsonResponse
    {
        $project = $this->projectRepo->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            return new JsonResponse(['error' => 'Project not found.'], 404);
        }

        $form = $this->formRepo->findOneBy(['project' => $project, 'slug' => $slug]);
        if (!$form) {
            return new JsonResponse(['error' => 'Form not found.'], 404);
        }

        $data = $request->toArray();

        try {
            $submission = $this->submitHandler->handle($form, $data, $request);
        } catch (ValidationFailedException $e) {
            $errors = [];
            foreach ($e->getViolations() as $violation) {
                $errors[$violation->getPropertyPath()] = $violation->getMessage();
            }

            return new JsonResponse(['error' => 'Validation failed.', 'validation_errors' => $errors], 422);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return $this->json([
            'success' => true,
            'submission_id' => $submission->id,
            'is_spam' => $submission->isSpam,
        ], $submission->isSpam ? 200 : 201);
    }
}
