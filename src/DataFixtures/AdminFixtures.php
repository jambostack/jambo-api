<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AdminFixtures extends Fixture
{
    public function __construct(private UserPasswordHasherInterface $hasher) {}

    public function load(ObjectManager $manager): void
    {
        $existing = $manager->getRepository(User::class)->findOneBy(['email' => 'admin@jamboapicms.com']);
        if ($existing !== null) {
            return; // idempotent
        }

        $admin = new User();
        $admin->email = 'admin@jamboapicms.com';
        $admin->name  = 'Admin';
        $admin->roles = ['ROLE_SUPER_ADMIN'];
        $admin->password = $this->hasher->hashPassword($admin, 'admin1234');

        $manager->persist($admin);
        $manager->flush();
    }
}
