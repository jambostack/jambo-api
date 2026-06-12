<?php

namespace App\Tests\Controller\Api;

use App\Entity\Collection;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Vérifie que applySchema normalise les options relation au format canonique
 * à la persistance : résolution slug→id (y compris pour une collection créée
 * dans la même requête), relationType legacy absorbé, targetCollection réservé
 * à end_users.
 */
class StudioSchemaApplyTest extends WebTestCase
{
    private function createSuperAdmin(): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Studio Admin';
        $user->email = 'studio_admin_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        return $user;
    }

    public function testRelationToCollectionCreatedInSameRequestIsResolved(): void
    {
        $client = static::createClient();
        $client->loginUser($this->createSuperAdmin());

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $project = new Project();
        $project->name = 'Studio Test ' . bin2hex(random_bytes(4));
        $em->persist($project);
        $em->flush();

        $client->jsonRequest('POST', '/api/projects/' . $project->uuid->toString() . '/studio/schema', [
            'collections' => [
                [
                    'name' => 'Auteurs', 'slug' => 'auteurs', 'description' => '', 'isSingleton' => false,
                    'fields' => [
                        ['name' => 'Nom', 'slug' => 'nom', 'type' => 'text', 'isRequired' => true],
                    ],
                ],
                [
                    'name' => 'Articles', 'slug' => 'articles', 'description' => '', 'isSingleton' => false,
                    'fields' => [
                        ['name' => 'Titre', 'slug' => 'titre', 'type' => 'text', 'isRequired' => true],
                        ['name' => 'Auteur', 'slug' => 'auteur', 'type' => 'relation', 'isRequired' => false,
                         'options' => ['targetCollection' => 'auteurs', 'relationType' => 2, 'includeDraft' => true]],
                        ['name' => 'Relecteur', 'slug' => 'relecteur', 'type' => 'relation', 'isRequired' => false,
                         'options' => ['targetCollection' => 'end_users', 'relationType' => 1]],
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $client->getResponse()->getStatusCode());

        $em->clear();
        $auteurs = $em->getRepository(Collection::class)->findOneBy(['project' => $project->id, 'slug' => 'auteurs']);
        $articles = $em->getRepository(Collection::class)->findOneBy(['project' => $project->id, 'slug' => 'articles']);
        $this->assertNotNull($auteurs);
        $this->assertNotNull($articles);

        $auteurField = $em->getRepository(Field::class)->findOneBy(['collection' => $articles, 'slug' => 'auteur']);
        $this->assertNotNull($auteurField);
        $opts = $auteurField->options;

        $this->assertSame($auteurs->id, $opts['relation']['collection'] ?? null,
            'la relation doit pointer vers l\'id de la collection créée dans la même requête');
        $this->assertSame(2, $opts['relation']['type'] ?? null, 'relationType legacy doit devenir relation.type');
        $this->assertArrayNotHasKey('targetCollection', $opts, 'targetCollection est réservé à end_users');
        $this->assertArrayNotHasKey('relationType', $opts);
        $this->assertArrayNotHasKey('collection_slug', $opts['relation'], 'collection_slug est dérivé, jamais stocké');
        $this->assertTrue($opts['includeDraft'] ?? false);

        $relecteurField = $em->getRepository(Field::class)->findOneBy(['collection' => $articles, 'slug' => 'relecteur']);
        $this->assertNotNull($relecteurField);
        $ropts = $relecteurField->options;

        $this->assertSame('end_users', $ropts['targetCollection'] ?? null);
        $this->assertSame(1, $ropts['relation']['type'] ?? null);
        $this->assertArrayNotHasKey('collection', $ropts['relation'] ?? []);
    }
}
