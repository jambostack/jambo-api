<?php
namespace App\Command;

use App\Entity\Field;
use App\Repository\CollectionRepository;
use App\Repository\ProjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'jambo:field:create', description: 'Ajouter un champ à une collection')]
class FieldCreateCommand extends Command
{
    use CliSecurityTrait;

    private const ALLOWED_TYPES = [
        'text', 'number', 'boolean', 'media', 'relation', 'textarea',
        'email', 'url', 'rich_text', 'date', 'datetime', 'json',
        'markdown', 'enumeration', 'select', 'multiselect', 'file',
    ];

    public function __construct(
        private ProjectRepository $projects,
        private CollectionRepository $collections,
        private EntityManagerInterface $em,
        private SluggerInterface $slugger,
        ?TranslatorInterface $translator = null,
    ) {
        parent::__construct();
        $this->setTranslator($translator);
    }

    protected function configure(): void
    {
        $this->addArgument('project-uuid', InputArgument::REQUIRED, 'UUID du projet')
            ->addArgument('collection-slug', InputArgument::REQUIRED, 'Slug de la collection')
            ->addArgument('name', InputArgument::REQUIRED, 'Nom du champ')
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Type (text,number,boolean,media,relation,textarea,email,url,rich_text,date,datetime,json,markdown)', 'text')
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'Slug (généré auto si omis)')
            ->addOption('required', 'r', InputOption::VALUE_NONE, 'Champ obligatoire');
    }

    protected function execute(InputInterface $i, OutputInterface $o): int
    {
        $io = new SymfonyStyle($i, $o);
        $projectUuid = (string) $i->getArgument('project-uuid');
        $project = $this->projects->findOneBy(['uuid' => $projectUuid]);
        if (!$project) {
            $io->error($this->trans('cli.project_not_found', ['%ident%' => $projectUuid], null, null, 'Projet introuvable'));
            return Command::FAILURE;
        }

        $collectionSlug = (string) $i->getArgument('collection-slug');
        $collection = $this->collections->findOneByProjectAndSlug($project, $collectionSlug);
        if (!$collection) {
            $io->error($this->trans('cli.collection_not_found', ['%slug%' => $collectionSlug], null, null, 'Collection introuvable'));
            return Command::FAILURE;
        }

        $type = strtolower((string) $i->getOption('type'));
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            $io->error($this->trans('cli.invalid_field_type', ['%type%' => $type, '%allowed%' => implode(', ', self::ALLOWED_TYPES)], null, null, "Type de champ invalide '$type'."));
            return Command::FAILURE;
        }

        $name = (string) $i->getArgument('name');
        $slug = $i->getOption('slug') ?: (string) $this->slugger->slug($name)->lower();
        $slug = substr($slug, 0, 100);

        $existing = $this->em->getRepository(Field::class)->findOneBy(['collection' => $collection, 'slug' => $slug, 'deletedAt' => null]);
        if ($existing) {
            $io->error($this->trans('cli.field_already_exists', ['%slug%' => $slug], null, null, "Un champ avec le slug '$slug' existe déjà dans cette collection."));
            return Command::FAILURE;
        }

        $field = new Field();
        $field->name = $name;
        $field->slug = $slug;
        $field->type = $type;
        $field->collection = $collection;
        $field->isRequired = (bool) $i->getOption('required');
        $field->order = 0;
        $this->em->persist($field);
        $this->em->flush();

        $io->success("Champ '{$field->name}' ({$field->type}) ajouté !");
        return Command::SUCCESS;
    }
}
