<?php

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AppSettingsControllerTest extends WebTestCase
{
    private function createUserAndLogin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Test Super Admin';
        $user->email = 'superadmin_appsettings_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        $user->roles = ['ROLE_SUPER_ADMIN'];

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    public function testGetReturnsDefaultSettings(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        $client->request('GET', '/admin/api/app-settings');

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('appName', $data);
        $this->assertArrayHasKey('logoUrl', $data);
        $this->assertArrayHasKey('faviconUrl', $data);
    }

    public function testUpdateAppName(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'appName' => 'MyCustomCMS',
        ]));

        $this->assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('MyCustomCMS', $data['appName']);
    }

    public function testGetReturnsUpdatedName(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'appName' => 'UpdatedApp',
        ]));
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/admin/api/app-settings');
        $data = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame('UpdatedApp', $data['appName']);
    }

    private function createRegularUserAndLogin(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->name = 'Regular User';
        $user->email = 'regular_' . uniqid() . '@test.com';
        $user->password = $hasher->hashPassword($user, 'password123');
        // No ROLE_SUPER_ADMIN — only ROLE_USER (default)

        $em->persist($user);
        $em->flush();

        $client->loginUser($user);
    }

    public function testRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/api/app-settings');
        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserCannotUpdateAppSettings(): void
    {
        $client = static::createClient();
        $this->createRegularUserAndLogin($client);

        $client->request('POST', '/admin/api/app-settings', [], [], ['CONTENT_TYPE' => 'application/json'], json_encode([
            'appName' => 'HackedName',
        ]));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRegularUserCannotReadAppSettingsApi(): void
    {
        $client = static::createClient();
        $this->createRegularUserAndLogin($client);

        $client->request('GET', '/admin/api/app-settings');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testRegularUserCannotAccessAdminPage(): void
    {
        $client = static::createClient();
        $this->createRegularUserAndLogin($client);

        $client->request('GET', '/admin/app-settings');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testUploadNonImageLogoReturns422(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        $phpFile = sys_get_temp_dir() . '/test_upload.php';
        file_put_contents($phpFile, '<?php echo "hacked"; ?>');

        $client->request('POST', '/admin/api/app-settings', [], [
            'logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $phpFile, 'shell.php', 'application/x-php', null, true
            ),
        ]);

        $this->assertResponseStatusCodeSame(422);
        @unlink($phpFile);
    }

    public function testUploadValidImageLogoSucceeds(): void
    {
        $client = static::createClient();
        $this->createUserAndLogin($client);

        // Create a minimal valid PNG (1×1 pixel)
        $pngFile = sys_get_temp_dir() . '/test_logo.png';
        $im = imagecreatetruecolor(1, 1);
        imagepng($im, $pngFile);
        imagedestroy($im);

        $client->request('POST', '/admin/api/app-settings', [], [
            'logo' => new \Symfony\Component\HttpFoundation\File\UploadedFile(
                $pngFile, 'logo.png', 'image/png', null, true
            ),
        ]);

        $this->assertResponseIsSuccessful();
        @unlink($pngFile);
    }
}
