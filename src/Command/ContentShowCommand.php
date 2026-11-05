<?php
namespace App\Command;

use App\Repository\ContentEntryRepository;
use App\Repository\ProjectRepository;
use App\Repository\CollectionRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:content:show', description: 'Afficher le détail d\'une entrée')]
class ContentShowCommand extends Command
{
    use CliSecurityTrait;

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private ContentEntryRepository $entries,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('collection-slug', InputArgument::REQUIRED, 'Slug de la collection')
            ->addArgument('uuid', InputArgument::REQUIRED, 'UUID de l\'entrée');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $uuidStr = (string) $i->getArgument('uuid');

        if (!Uuid::isValid($uuidStr)) {
            $io->error($this->trans('cli.entry_not_found', [], null, null, 'Entrée introuvable'));
            return Command::FAILURE;
        }

        $entry = $this->entries->findOneBy(['uuid' => $uuidStr]);
        if (!$entry) {
            $io->error($this->trans('cli.entry_not_found', [], null, null, 'Entrée introuvable'));
            return Command::FAILURE;
        }

        $io->writeln("UUID: {$entry->uuid}");
        $io->writeln("Slug: {$entry->slug}");
        $io->writeln("Statut: {$entry->status}");
        $io->writeln("Locale: {$entry->locale}");
        $io->writeln("Créé: {$entry->createdAt->format('Y-m-d H:i:s')}");
        $io->writeln("Champs:");

        foreach ($entry->fieldValues as $fv) {
            $slug = $fv->field?->slug ?? '(field-' . $fv->id . ')';
            $val = $fv->textValue
                ?? $fv->numberValue
                ?? ($fv->booleanValue !== null ? ($fv->booleanValue ? 'true' : 'false') : null)
                ?? ($fv->dateValue ? $fv->dateValue->format('Y-m-d') : null)
                ?? ($fv->datetimeValue ? $fv->datetimeValue->format('Y-m-d H:i:s') : null)
                ?? ($fv->jsonValue !== null ? json_encode($fv->jsonValue) : null)
                ?? 'null';

            $io->writeln("  {$slug}: {$val}");
        }

        return Command::SUCCESS;
    }
}
