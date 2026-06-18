<?php

namespace App\Command;

use App\Entity\EndUserField;
use App\Repository\EndUserFieldRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:seed-end-user-fields', description: 'Seed system EndUser fields for all projects that do not yet have them.')]
class SeedEndUserFieldsCommand extends Command
{
    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserFieldRepository $fieldRepository,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $systemFields = [
            ['name' => 'Email',    'slug' => 'email',    'type' => 'email',       'order' => 0, 'isRequired' => true,  'options' => null],
            ['name' => 'Password', 'slug' => 'password', 'type' => 'password',    'order' => 1, 'isRequired' => true,  'options' => null],
            ['name' => 'Username', 'slug' => 'username', 'type' => 'text',        'order' => 2, 'isRequired' => false, 'options' => null],
            ['name' => 'Status',   'slug' => 'status',   'type' => 'enumeration', 'order' => 3, 'isRequired' => true,
             'options' => ['enumeration' => ['list' => ['active', 'banned', 'pending']]]],
        ];

        $projects = $this->projectRepository->findAll();
        $seeded = 0;

        foreach ($projects as $project) {
            $existing = $this->fieldRepository->findByProject($project);
            $existingSlugs = array_map(fn ($f) => $f->slug, $existing);

            foreach ($systemFields as $sf) {
                if (in_array($sf['slug'], $existingSlugs, true)) {
                    continue;
                }
                $field            = new EndUserField();
                $field->project   = $project;
                $field->name      = $sf['name'];
                $field->slug      = $sf['slug'];
                $field->type      = $sf['type'];
                $field->order     = $sf['order'];
                $field->isRequired = $sf['isRequired'];
                $field->isSystem  = true;
                $field->options   = $sf['options'];
                $this->em->persist($field);
                $seeded++;
            }
        }

        $this->em->flush();

        $io->success("Seeded {$seeded} system field(s) across " . count($projects) . " project(s).");

        return Command::SUCCESS;
    }
}
