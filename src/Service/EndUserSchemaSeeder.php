<?php

namespace App\Service;

use App\Entity\EndUserField;
use App\Entity\Project;
use Doctrine\ORM\EntityManagerInterface;

class EndUserSchemaSeeder
{
    private const SYSTEM_FIELDS = [
        ['name' => 'Email',    'slug' => 'email',    'type' => 'email',       'order' => 0, 'isRequired' => true,  'options' => null],
        ['name' => 'Password', 'slug' => 'password', 'type' => 'password',    'order' => 1, 'isRequired' => true,  'options' => null],
        ['name' => 'Name',     'slug' => 'name',     'type' => 'text',        'order' => 2, 'isRequired' => false, 'options' => null],
        ['name' => 'Status',   'slug' => 'status',   'type' => 'enumeration', 'order' => 3, 'isRequired' => true,
         'options' => ['enumeration' => ['list' => ['active', 'banned', 'pending']]]],
    ];

    public function __construct(private EntityManagerInterface $em) {}

    public function seed(Project $project): void
    {
        $repo = $this->em->getRepository(EndUserField::class);

        foreach (self::SYSTEM_FIELDS as $sf) {
            if ($repo->findOneBy(['project' => $project, 'slug' => $sf['slug']]) !== null) {
                continue;
            }
            $field             = new EndUserField();
            $field->project    = $project;
            $field->name       = $sf['name'];
            $field->slug       = $sf['slug'];
            $field->type       = $sf['type'];
            $field->order      = $sf['order'];
            $field->isRequired = $sf['isRequired'];
            $field->isSystem   = true;
            $field->options    = $sf['options'];
            $this->em->persist($field);
        }
    }
}
