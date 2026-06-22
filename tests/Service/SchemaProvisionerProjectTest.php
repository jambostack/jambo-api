<?php

namespace App\Tests\Service;

use App\Entity\ProjectMember;
use App\Entity\User;
use App\Service\EndUserSchemaSeeder;
use App\Service\SchemaProvisioner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SchemaProvisionerProjectTest extends KernelTestCase
{
    private SchemaProvisioner $svc;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->svc = new SchemaProvisioner(
            $this->em,
            new EndUserSchemaSeeder($this->em),
            new \App\Service\FieldRelationOptionsNormalizer($this->em->getRepository(\App\Entity\Collection::class)),
        );
    }

    private function user(): User
    {
        $user = new User();
        $user->email = 'owner-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'x';
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    public function testCreateProjectAddsOwnerMember(): void
    {
        $user = $this->user();
        $project = $this->svc->createProject($user, ['name' => 'Eureka', 'default_locale' => 'fr', 'locales' => ['fr', 'en']]);

        self::assertNotNull($project->uuid);
        self::assertSame('fr', $project->defaultLocale);

        $member = $this->em->getRepository(ProjectMember::class)->findOneBy(['project' => $project, 'user' => $user]);
        self::assertNotNull($member, 'owner must be a project member');
    }

    public function testUpdateProject(): void
    {
        $project = $this->svc->createProject($this->user(), ['name' => 'Old', 'public_api' => false]);
        $this->svc->updateProject($project, ['name' => 'New', 'public_api' => true]);

        self::assertSame('New', $project->name);
        self::assertTrue($project->publicApi);
    }

    public function testDeleteProject(): void
    {
        $project = $this->svc->createProject($this->user(), ['name' => 'Temp']);
        $uuid = $project->uuid;
        $this->svc->deleteProject($project);

        $found = $this->em->getRepository(\App\Entity\Project::class)->findOneBy(['uuid' => $uuid]);
        self::assertNull($found);
    }
}
