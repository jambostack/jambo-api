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
        return in_array($attribute, [self::VIEW, self::MANAGE])
            && $subject instanceof Project;
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

        return match ($attribute) {
            self::VIEW   => $member !== null,
            self::MANAGE => $member !== null && $member->role?->hasPermission('project.manage') === true,
            default      => false,
        };
    }
}
