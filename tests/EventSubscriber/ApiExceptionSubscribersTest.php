<?php

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\AdminApiExceptionSubscriber;
use App\EventSubscriber\PublicApiExceptionSubscriber;
use App\EventSubscriber\TwoFactorRedirectSubscriber;
use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Contracts\Translation\TranslatorInterface;

class ApiExceptionSubscribersTest extends TestCase
{
    public function testAdminApiExceptionSubscriberMasksInProd(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('Une erreur inattendue est survenue.');

        $subscriber = new AdminApiExceptionSubscriber('prod', new NullLogger(), $translator);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin-api/projects/123/collections');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Database SQL syntax error near column'));

        $subscriber->onException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('Une erreur inattendue est survenue.', $data['error']);
        $this->assertStringNotContainsString('Database SQL', $response->getContent());
    }

    public function testAdminApiExceptionSubscriberPreservesMessageInDev(): void
    {
        $subscriber = new AdminApiExceptionSubscriber('dev', new NullLogger());

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin-api/projects/123/collections');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Detailed debug trace'));

        $subscriber->onException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Detailed debug trace', $data['error']);
    }

    public function testAdminApiExceptionSubscriberPreservesHttpExceptions(): void
    {
        $subscriber = new AdminApiExceptionSubscriber('prod', new NullLogger());

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/admin-api/projects/123/collections');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new AccessDeniedHttpException('Access Denied'));

        $subscriber->onException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame('Access Denied', $data['error']);
    }

    public function testPublicApiExceptionSubscriberMasksInProd(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('An unexpected error occurred.');

        $subscriber = new PublicApiExceptionSubscriber('prod', new NullLogger(), $translator);

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/api/content/articles');
        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Sensitive DB trace'));

        $subscriber->onException($event);

        $response = $event->getResponse();
        $this->assertNotNull($response);
        $this->assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('An unexpected error occurred.', $data['error']);
        $this->assertSame('INTERNAL_ERROR', $data['code']);
    }

    public function testTwoFactorRedirectSubscriberWithoutSessionDoesNotCrash(): void
    {
        $urlGen = $this->createMock(UrlGeneratorInterface::class);
        $subscriber = new TwoFactorRedirectSubscriber($urlGen);

        $user = new User();
        $user->twoFactorEnabled = true;

        $request = new Request(); // no session attached
        $passport = new SelfValidatingPassport(new UserBadge('user@example.com', fn () => $user));
        $event = new LoginSuccessEvent(
            $this->createMock(\Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface::class),
            $passport,
            $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class),
            $request,
            null,
            'main'
        );

        // Should return early and not throw SessionNotFoundException
        $subscriber->onLoginSuccess($event);
        $this->assertNull($event->getResponse());
    }
}
