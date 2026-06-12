<?php

namespace App\Command;

use App\Entity\Field;
use App\Service\FieldRelationOptionsNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Migration one-shot : normalise les options de tous les champs relation
 * vers le format canonique (relation.collection id, relation.type,
 * targetCollection réservé à end_users). À exécuter une fois en production,
 * après quoi les blocs de rétrocompatibilité frontend pourront être retirés.
 */
#[AsCommand(
    name: 'app:normalize-relation-options',
    description: 'Normalise les options des champs relation vers le format canonique',
)]
class NormalizeRelationOptionsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FieldRelationOptionsNormalizer $normalizer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les changements sans écrire en base');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $fields = $this->em->getRepository(Field::class)->findBy(['type' => 'relation']);

        $changed = 0;
        foreach ($fields as $field) {
            $project = $field->collection?->project;
            if ($project === null) {
                continue;
            }

            $before = $field->options ?? [];
            $after = $this->normalizer->normalize($before, $project, forStorage: true);

            // Comparaison insensible à l'ordre des clés (MySQL réordonne le JSON)
            if (self::canonicalize($after) !== self::canonicalize($before)) {
                $io->text(sprintf(
                    '%s.%s : %s → %s',
                    $field->collection->slug,
                    $field->slug,
                    json_encode($before, JSON_UNESCAPED_UNICODE),
                    json_encode($after, JSON_UNESCAPED_UNICODE),
                ));
                if (!$dryRun) {
                    $field->options = $after;
                }
                $changed++;
            }
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        $io->success(sprintf(
            '%d champ(s) relation analysé(s), %d normalisé(s)%s.',
            count($fields),
            $changed,
            $dryRun ? ' (dry-run, rien écrit)' : '',
        ));

        return Command::SUCCESS;
    }

    /** Tri récursif des clés pour comparer deux tableaux indépendamment de l'ordre. */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $sorted = array_map(self::canonicalize(...), $value);
        ksort($sorted);

        return $sorted;
    }
}
