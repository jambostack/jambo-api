<?php

namespace App\DataFixtures;

use App\Entity\Permission;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class PermissionFixture extends Fixture
{
    private const PERMISSIONS = [
        ['name' => 'project.manage',    'label' => 'Gérer le projet',            'group' => 'project'],
        ['name' => 'project.create',    'label' => 'Créer un projet',            'group' => 'project'],
        ['name' => 'collection.create', 'label' => 'Créer une collection',       'group' => 'collection'],
        ['name' => 'collection.update', 'label' => 'Modifier une collection',    'group' => 'collection'],
        ['name' => 'collection.delete', 'label' => 'Supprimer une collection',   'group' => 'collection'],
        ['name' => 'content.create',    'label' => 'Créer du contenu',           'group' => 'content'],
        ['name' => 'content.update',    'label' => 'Modifier le contenu',        'group' => 'content'],
        ['name' => 'content.delete',    'label' => 'Supprimer le contenu',       'group' => 'content'],
        ['name' => 'content.trash',     'label' => 'Mettre en corbeille',        'group' => 'content'],
        ['name' => 'content.restore',   'label' => 'Restaurer le contenu',       'group' => 'content'],
        ['name' => 'assets.view',       'label' => 'Accéder aux assets',         'group' => 'assets'],
        ['name' => 'users.manage',      'label' => 'Gérer les utilisateurs',     'group' => 'users'],
        ['name' => 'roles.manage',      'label' => 'Gérer les rôles',            'group' => 'roles'],
    ];

    public function load(ObjectManager $manager): void
    {
        foreach (self::PERMISSIONS as $data) {
            $existing = $manager->getRepository(Permission::class)->findOneBy(['name' => $data['name']]);
            if ($existing !== null) {
                continue; // idempotent
            }
            $permission = new Permission();
            $permission->name  = $data['name'];
            $permission->label = $data['label'];
            $permission->group = $data['group'];
            $manager->persist($permission);
        }
        $manager->flush();
    }
}
