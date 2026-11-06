<?php

namespace App\Tests\Repository;

use App\Entity\Collection;
use App\Entity\Comment;
use App\Entity\ContentEntry;
use App\Entity\Project;
use App\Entity\User;
use App\Repository\CommentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class CommentRepositoryTest extends KernelTestCase
{
    private ?EntityManagerInterface $em = null;
    private ?CommentRepository $repository = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = $this->em->getRepository(Comment::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->em = null;
        $this->repository = null;
    }

    public function testFindByEntryPaginatedReturnsCorrectCollection(): void
    {
        $user = new User();
        $user->name = 'Comment Author ' . bin2hex(random_bytes(4));
        $user->email = 'author_' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'password123';
        $this->em->persist($user);

        $project = new Project();
        $project->name = 'Project Comments ' . bin2hex(random_bytes(4));
        $this->em->persist($project);

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Articles ' . bin2hex(random_bytes(4));
        $collection->slug = 'articles-' . bin2hex(random_bytes(4));
        $this->em->persist($collection);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->data = ['title' => 'Test Article'];
        $entry->status = 'published';
        $this->em->persist($entry);

        $parentComment = new Comment();
        $parentComment->entry = $entry;
        $parentComment->author = $user;
        $parentComment->body = 'Parent comment';
        $this->em->persist($parentComment);

        $childComment = new Comment();
        $childComment->entry = $entry;
        $childComment->author = $user;
        $childComment->parent = $parentComment;
        $childComment->body = 'Reply comment';
        $this->em->persist($childComment);

        $this->em->flush();
        $this->em->clear();

        $results = $this->repository->findByEntryPaginated($entry->id, page: 1, perPage: 10);
        $this->assertCount(1, $results);
        $this->assertSame($parentComment->id, $results[0]->id);
        $this->assertCount(1, $results[0]->children);
        $this->assertSame($childComment->id, $results[0]->children->first()->id);

        $count = $this->repository->countByEntry($entry->id);
        $this->assertSame(2, $count);
    }
}
