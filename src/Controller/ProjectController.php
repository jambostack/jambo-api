<?php

namespace App\Controller;

use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Enum\ProjectMemberStatus;
use App\Repository\ProjectMemberRepository;
use App\Repository\ProjectRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/projects', name: 'api_project_')]
class ProjectController extends InertiaController
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private ProjectMemberRepository $memberRepo,
        private RoleRepository $roleRepository,
    ) {}

    private ?ProjectMember $currentMember = null;

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();
        $projects = $this->projectRepository->findByMember($user);

        return $this->json([
            'data' => array_map(fn ($p) => $this->serialize($p), $projects),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = $request->toArray();

        if (empty($data['name'])) {
            return $this->json(['error' => 'name is required'], 422);
        }

        $project = new Project();
        $project->name          = $data['name'];
        $project->description   = $data['description'] ?? null;
        $project->defaultLocale = $data['default_locale'] ?? 'en';
        $project->locales       = $data['locales'] ?? ['en'];
        $project->disk          = $data['disk'] ?? 'public';
        $project->publicApi     = $data['public_api'] ?? false;

        $this->em->persist($project);

        // Add creator as active member
        $creator           = new ProjectMember();
        $creator->project  = $project;
        $creator->user     = $this->getUser();
        $creator->email    = $this->getUser()->email;
        $creator->status   = ProjectMemberStatus::Active;
        $creator->joinedAt = new \DateTimeImmutable();
        $this->em->persist($creator);

        $this->em->flush();

        return $this->json(['data' => $this->serialize($project)], 201);
    }

    #[Route('/{uuid}', name: 'show', methods: ['GET'])]
    public function show(string $uuid): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        return $this->json(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'update', methods: ['PUT', 'PATCH'])]
    public function update(string $uuid, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $data = $request->toArray();

        if (isset($data['name'])) {
            if (trim((string) $data['name']) === '') {
                return $this->json(['error' => 'name cannot be empty'], 422);
            }
            $project->name = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $project->description = $data['description'];
        }
        if (isset($data['default_locale'])) {
            $project->defaultLocale = $data['default_locale'];
        }
        if (isset($data['locales'])) {
            $project->locales = $data['locales'];
        }
        if (isset($data['disk'])) {
            $project->disk = $data['disk'];
        }
        if (isset($data['public_api'])) {
            $project->publicApi = (bool) $data['public_api'];
        }

        $this->em->flush();

        // Inertia form submissions expect a redirect on success
        if ($request->headers->has('X-Inertia')) {
            return $this->redirect('/projects/' . $project->id . '/settings/project', 303);
        }

        return $this->json(['data' => $this->serialize($project)]);
    }

    #[Route('/{uuid}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $uuid, Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $this->em->remove($project);
        $this->em->flush();

        if ($request->headers->has('X-Inertia')) {
            return $this->redirect('/', 303);
        }

        return $this->json(null, 204);
    }

    #[Route('/{uuid}/clone', name: 'clone', methods: ['POST'])]
    public function cloneProject(string $uuid, Request $request): JsonResponse
    {
        $source = $this->resolveProject($uuid);
        if ($source instanceof JsonResponse) {
            return $source;
        }

        $data = $request->toArray();

        $cloneName = $data['name'] ?? ($source->name . ' (Copy)');
        if (trim($cloneName) === '') {
            return $this->json(['error' => 'name cannot be empty'], 422);
        }

        $clone = new Project();
        $clone->name          = $cloneName;
        $clone->description   = $source->description;
        $clone->defaultLocale = $source->defaultLocale;
        $clone->locales       = $source->locales;
        $clone->disk          = $source->disk;
        $clone->publicApi     = false;

        // Clone collections and fields (without content)
        foreach ($source->collections as $sourceCollection) {
            if ($sourceCollection->isDeleted()) {
                continue;
            }
            $newCollection = new \App\Entity\Collection();
            $newCollection->name        = $sourceCollection->name;
            $newCollection->slug        = $sourceCollection->slug;
            $newCollection->description = $sourceCollection->description;
            $newCollection->isSingleton = $sourceCollection->isSingleton;
            $newCollection->order       = $sourceCollection->order;
            $newCollection->project     = $clone;

            foreach ($sourceCollection->fields as $sourceField) {
                if ($sourceField->isDeleted()) {
                    continue;
                }
                $newField = new \App\Entity\Field();
                $newField->name       = $sourceField->name;
                $newField->slug       = $sourceField->slug;
                $newField->type       = $sourceField->type;
                $newField->options    = $sourceField->options;
                $newField->order      = $sourceField->order;
                $newField->isRequired = $sourceField->isRequired;
                $newField->collection = $newCollection;
                $this->em->persist($newField);
            }

            $this->em->persist($newCollection);
        }

        $this->em->persist($clone);

        // Add cloner as active member
        $cloner           = new ProjectMember();
        $cloner->project  = $clone;
        $cloner->user     = $this->getUser();
        $cloner->email    = $this->getUser()->email;
        $cloner->status   = ProjectMemberStatus::Active;
        $cloner->joinedAt = new \DateTimeImmutable();
        $this->em->persist($cloner);

        $this->em->flush();

        return $this->json(['data' => $this->serialize($clone)], 201);
    }

    #[Route('/{uuid}/members', name: 'members_add', methods: ['POST'])]
    public function addMember(string $uuid, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->json(['error' => 'You do not have permission to manage members.'], 403);
            }
        }

        $data   = $request->toArray();
        $roleId = $data['role_id'] ?? null;
        $role   = null;
        if ($roleId !== null && $roleId !== '') {
            $role = $this->roleRepository->find((int) $roleId);
            if ($role === null) {
                return $this->json(['error' => 'Role not found.'], 404);
            }
        }

        if (isset($data['user_id']) && $data['user_id'] !== '' && $data['user_id'] !== null) {
            $user = $this->userRepository->find((int) $data['user_id']);
            if ($user === null) {
                return $this->json(['error' => 'User not found.'], 404);
            }
            $existing = $this->memberRepo->findOneBy(['project' => $project, 'user' => $user]);
            if ($existing !== null) {
                return $this->json(['error' => 'User is already a member.'], 409);
            }
            $member           = new ProjectMember();
            $member->project  = $project;
            $member->user     = $user;
            $member->email    = $user->email;
            $member->role     = $role;
            $member->status   = ProjectMemberStatus::Active;
            $member->invitedBy = $currentUser;
            $member->joinedAt = new \DateTimeImmutable();
            $this->em->persist($member);
            $this->em->flush();
            return $this->json(['data' => $this->serializeMember($member)], 201);
        }

        if (!empty($data['email']) && is_string($data['email'])) {
            $email = trim($data['email']);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Invalid email address.'], 422);
            }
            $existing = $this->memberRepo->findOneBy(['project' => $project, 'email' => $email]);
            if ($existing !== null) {
                return $this->json(['error' => 'This email is already a member or has a pending invitation.'], 409);
            }
            $token                   = bin2hex(random_bytes(32));
            $member                  = new ProjectMember();
            $member->project         = $project;
            $member->user            = null;
            $member->email           = $email;
            $member->role            = $role;
            $member->status          = ProjectMemberStatus::Pending;
            $member->invitedBy       = $currentUser;
            $member->invitationToken = $token;
            $member->tokenExpiresAt  = new \DateTimeImmutable('+72 hours');
            $this->em->persist($member);
            $this->em->flush();
            return $this->json(['data' => $this->serializeMember($member)], 201);
        }

        return $this->json(['error' => 'user_id or email is required.'], 422);
    }

    #[Route('/{uuid}/members/{memberId}', name: 'members_remove', methods: ['DELETE'])]
    public function removeMember(string $uuid, int $memberId): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->json(['error' => 'You do not have permission to manage members.'], 403);
            }
        }

        $member = $this->memberRepo->findOneBy(['id' => $memberId, 'project' => $project]);
        if ($member === null) {
            return $this->json(['error' => 'Member not found.'], 404);
        }

        if ($member->status === ProjectMemberStatus::Active && $this->memberRepo->countActiveByProject($project) <= 1) {
            return $this->json(['error' => 'Cannot remove the last active member of a project.'], 422);
        }

        $soleManage = $this->memberRepo->findSoleManagePermissionMember($project);
        if ($soleManage !== null && $soleManage->id === $member->id) {
            return $this->json(['error' => 'Cannot remove the last member with project.manage permission.'], 422);
        }

        $this->em->remove($member);
        $this->em->flush();
        return $this->json(null, 204);
    }

    #[Route('/{uuid}/members/{memberId}/role', name: 'members_update_role', methods: ['PATCH'])]
    public function updateMemberRole(string $uuid, int $memberId, Request $request): JsonResponse
    {
        $project = $this->resolveProject($uuid);
        if ($project instanceof JsonResponse) {
            return $project;
        }

        $currentUser = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $currentUser->getRoles(), true)) {
            if ($this->currentMember === null || $this->currentMember->role?->hasPermission('project.manage') !== true) {
                return $this->json(['error' => 'You do not have permission to manage members.'], 403);
            }
        }

        $member = $this->memberRepo->findOneBy(['id' => $memberId, 'project' => $project]);
        if ($member === null) {
            return $this->json(['error' => 'Member not found.'], 404);
        }

        $data         = $request->toArray();
        $roleId       = $data['role_id'] ?? null;
        $member->role = $roleId ? $this->roleRepository->find((int) $roleId) : null;
        $this->em->flush();
        return $this->json(['data' => $this->serializeMember($member)]);
    }

    public function serializeMember(ProjectMember $member): array
    {
        return [
            'id'         => $member->id,
            'user'       => $member->user ? [
                'id'    => $member->user->id,
                'name'  => $member->user->name,
                'email' => $member->user->email,
            ] : null,
            'role'       => $member->role ? [
                'id'    => $member->role->id,
                'name'  => $member->role->name,
                'label' => $member->role->label,
            ] : null,
            'email'      => $member->email,
            'status'     => $member->status->value,
            'joined_at'  => $member->joinedAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $member->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    private function resolveProject(string $uuid): Project|JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $uuid]);
        if ($project === null) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $user = $this->getUser();
        if (!in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
            if ($member === null) {
                return $this->json(['error' => 'Access denied'], 403);
            }
            $this->currentMember = $member;
        }

        return $project;
    }

    private function serialize(Project $project): array
    {
        return [
            'uuid'             => $project->uuid?->toString(),
            'name'             => $project->name,
            'description'      => $project->description,
            'defaultLocale'    => $project->defaultLocale,
            'locales'          => $project->locales,
            'disk'             => $project->disk,
            'publicApi'        => $project->publicApi,
            'collectionsCount' => $project->collections->count(),
            'membersCount'     => $project->projectMembers->count(),
        ];
    }
}
