<?php

namespace App\Security;

use App\Entity\Project;
use App\Entity\User;
use App\Repository\ProjectMemberRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class ProjectVoter extends Voter
{
    public const VIEW   = 'project.view';
    public const MANAGE = 'project.manage';

    public function __construct(private ProjectMemberRepository $memberRepo) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Toute permission de projet (project.*, content.*, collection.*, assets.*)
        // est résolue par ce voter dès lors que le sujet est un Project.
        return $subject instanceof Project
            && str_contains($attribute, '.');
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        /** @var Project $project */
        $project = $subject;

        if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $member = $this->memberRepo->findActiveByUserAndProject($user, $project);
        if ($member === null) {
            return false;
        }

        // project.view ne nécessite que l'appartenance au projet ;
        // toute autre permission est vérifiée sur le rôle du membre.
        if ($attribute === self::VIEW) {
            return true;
        }

        return $member->role?->hasPermission($attribute) === true;
    }
}
