<?php

namespace App\Tests\Service\Insights;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\Media;
use App\Entity\Project;
use App\Enum\InsightsRange;
use App\Service\Insights\InsightsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class InsightsServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private InsightsService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->service = self::getContainer()->get(InsightsService::class);
    }

    private function makeProject(): Project
    {
        $project = new Project();
        $project->name = 'Insights ' . bin2hex(random_bytes(4));
        $this->em->persist($project);
        return $project;
    }

    private function makeCollection(Project $project, string $name): Collection
    {
        $c = new Collection();
        $c->name = $name;
        $c->slug = strtolower($name);
        $c->project = $project;
        $this->em->persist($c);
        return $c;
    }

    private function makeEntry(Project $p, Collection $c, string $status): ContentEntry
    {
        $e = new ContentEntry();
        $e->project = $p;
        $e->collection = $c;
        $e->status = $status;
        $this->em->persist($e);
        return $e;
    }

    public function testContentMetrics(): void
    {
        $p = $this->makeProject();
        $c = $this->makeCollection($p, 'Articles');
        $this->makeEntry($p, $c, 'draft');
        $this->makeEntry($p, $c, 'published');
        $this->makeEntry($p, $c, 'published');
        $this->em->flush();

        $data = $this->service->forProject($p, InsightsRange::D30);

        self::assertSame('30d', $data['range']);
        self::assertSame(3, $data['content']['total']);
        self::assertSame(2, $data['content']['by_status']['published']);
        self::assertSame(1, $data['content']['by_status']['draft']);
        self::assertSame('Articles', $data['content']['top_collections'][0]['name']);
        self::assertSame(3, $data['content']['top_collections'][0]['count']);
        self::assertIsArray($data['content']['timeseries']);
    }

    public function testMediaMetrics(): void
    {
        $p = $this->makeProject();
        foreach ([['image/png', 100], ['video/mp4', 200], ['application/pdf', 50]] as [$mime, $size]) {
            $m = new Media();
            $m->project = $p;
            $m->mimeType = $mime;
            $m->fileSize = $size;
            $m->fileName = 'f' . bin2hex(random_bytes(3));
            $this->em->persist($m);
        }
        // Média soft-deleted : ne doit pas être compté
        $deleted = new Media();
        $deleted->project = $p;
        $deleted->mimeType = 'image/jpeg';
        $deleted->fileSize = 999;
        $deleted->fileName = 'deleted_' . bin2hex(random_bytes(3));
        $deleted->deletedAt = new \DateTimeImmutable();
        $this->em->persist($deleted);

        $this->em->flush();

        $data = $this->service->forProject($p, InsightsRange::D30);

        self::assertSame(3, $data['media']['total']);
        self::assertSame(350, $data['media']['total_size']);
        self::assertSame(1, $data['media']['by_type']['image']);
        self::assertSame(1, $data['media']['by_type']['video']);
        self::assertSame(1, $data['media']['by_type']['document']);
    }

    public function testActivityAndEndUserMetrics(): void
    {
        $p = $this->makeProject();

        foreach (['success', 'success', 'error'] as $status) {
            $log = new \App\Entity\AuditLog();
            $log->project = $p;
            $log->toolName = 'create_collection';
            $log->status = $status;
            $log->source = 'mcp';
            $this->em->persist($log);
        }

        $eu = new \App\Entity\EndUser($p, 'u' . bin2hex(random_bytes(3)) . '@e.com');
        $eu->status = 'active';
        $this->em->persist($eu);
        $this->em->flush();

        $data = $this->service->forProject($p, \App\Enum\InsightsRange::D30);

        self::assertCount(3, $data['activity']['recent']);
        self::assertEqualsWithDelta(2 / 3, $data['activity']['success_rate'], 0.001);
        self::assertSame(1, $data['endusers']['total']);
        self::assertSame(1, $data['endusers']['by_status']['active']);
        // flows: aucun run → structure vide cohérente
        self::assertSame(0, $data['flows']['total']);
        self::assertNull($data['flows']['avg_duration_ms']);
    }

    public function testSummaryForUserAggregatesMemberProjects(): void
    {
        $user = new \App\Entity\User();
        $user->email = 's-' . bin2hex(random_bytes(4)) . '@e.com';
        $user->password = 'x';
        $this->em->persist($user);

        $p = $this->makeProject();
        $member = new \App\Entity\ProjectMember();
        $member->project = $p;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = \App\Enum\ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $this->em->persist($member);

        $c = $this->makeCollection($p, 'Posts');
        $this->makeEntry($p, $c, 'published');
        $this->makeEntry($p, $c, 'draft');
        $this->em->flush();

        $summary = $this->service->summaryForUser($user);

        self::assertSame(1, $summary['projects']);
        self::assertSame(2, $summary['content_total']);
        self::assertSame(0, $summary['storage_bytes']);
        self::assertSame(0, $summary['endusers_total']);
    }

    public function testSummaryForUserWithNoProjectsReturnsZeroes(): void
    {
        $user = new \App\Entity\User();
        $user->email = 'noproj-' . bin2hex(random_bytes(4)) . '@e.com';
        $user->password = 'x';
        $this->em->persist($user);
        $this->em->flush();

        $summary = $this->service->summaryForUser($user);

        self::assertSame(0, $summary['projects']);
        self::assertSame(0, $summary['content_total']);
        self::assertSame(0, $summary['media_total']);
        self::assertSame(0, $summary['storage_bytes']);
        self::assertSame(0, $summary['endusers_total']);
    }
}
