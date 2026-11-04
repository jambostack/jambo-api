<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Form;
use App\Entity\FormSubmission;
use App\Entity\Project;
use App\Entity\ProjectMember;
use App\Entity\Role;
use App\Entity\User;
use App\Enum\ProjectMemberStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FormControllerExportTest extends WebTestCase
{
    public function testExportSubmissionsCsvNeutralizesFormulaInjection(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->email = 'admin-export-' . bin2hex(random_bytes(4)) . '@example.com';
        $user->password = 'password123';
        $em->persist($user);

        $project = new Project();
        $project->name = 'Export Form Project ' . bin2hex(random_bytes(4));
        $em->persist($project);

        $member = new ProjectMember();
        $member->project = $project;
        $member->user = $user;
        $member->email = $user->email;
        $member->status = ProjectMemberStatus::Active;
        $member->joinedAt = new \DateTimeImmutable();
        $em->persist($member);

        $form = new Form();
        $form->project = $project;
        $form->name = 'Contact Us';
        $form->slug = 'contact-us';
        $form->fields = [
            ['name' => 'fullname', 'label' => 'Full Name', 'type' => 'text'],
            ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'text'],
            ['name' => 'note', 'label' => 'Note', 'type' => 'text'],
        ];
        $em->persist($form);

        $submission = new FormSubmission();
        $submission->form = $form;
        $submission->data = [
            'fullname' => '=cmd| /C calc',
            'phone'    => '+33612345678',
            'note'     => '@SUM(A1:A10)',
        ];
        $submission->isComplete = true;
        $submission->isSpam = false;
        $submission->isRead = false;
        $submission->metadata = ['ip' => '127.0.0.1'];
        $em->persist($submission);

        $em->flush();

        $client->loginUser($user);

        $projectUuid = $project->uuid->toString();
        $formId = $form->id;

        $client->request('GET', "/admin-api/{$projectUuid}/forms/{$formId}/submissions/export-csv");

        $response = $client->getResponse();
        $this->assertResponseIsSuccessful();
        $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="submissions_contact-us_', $response->headers->get('Content-Disposition') ?? '');

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        if (is_callable($callback)) {
            $callback();
        } else {
            $response->sendContent();
        }
        $content = ob_get_clean();

        // Vérification de la présence du BOM UTF-8 (\xEF\xBB\xBF) au tout début
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);

        // Vérification de la neutralisation des déclencheurs de formule
        $this->assertStringContainsString("'\=cmd| /C calc", addcslashes($content, '='));
        $this->assertStringContainsString("'+33612345678", $content);
        $this->assertStringContainsString("'@SUM(A1:A10)", $content);
    }
}
