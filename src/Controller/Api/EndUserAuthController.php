<?php

namespace App\Controller\Api;

use App\Entity\EndUser;
use App\Entity\PasswordResetToken;
use App\Repository\EndUserRepository;
use App\Repository\ProjectRepository;
use App\Service\EndUserJwtService;
use App\Service\TwoFactorService;
use App\Controller\EndUserSerializerTrait;
use Doctrine\ORM\EntityManagerInterface;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[OA\Tag(name: 'End-User Auth')]
#[Route('/api/{projectId}/auth', name: 'enduser_auth_',
    requirements: ['projectId' => '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}'],
    priority: 10)]
class EndUserAuthController extends AbstractController
{
    use EndUserSerializerTrait;

    public function __construct(
        private ProjectRepository $projectRepository,
        private EndUserRepository $endUserRepository,
        private EndUserJwtService $jwtService,
        private TwoFactorService $twoFactorService,
        private UserPasswordHasherInterface $hasher,
        private EntityManagerInterface $em,
    ) {}

    #[OA\Post(
        path: '/api/{projectId}/auth/register',
        summary: 'Register a new end-user',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string', minLength: 8),
                new OA\Property(property: 'username', type: 'string', nullable: true),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 201, description: 'User created', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 409, description: 'Email already registered', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';
        $username = $data['username'] ?? null;

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Valid email is required'], 422);
        }
        if (strlen($password) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], 422);
        }

        $existing = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($existing) {
            return $this->json(['error' => 'Email already registered'], 409);
        }

        $endUser = new EndUser($project, $email);
        $endUser->username = $username;
        $endUser->password = $this->hasher->hashPassword($endUser, $password);

        $this->em->persist($endUser);
        $this->em->flush();

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ], 201);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/login',
        summary: 'Authenticate an end-user',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email', 'password'],
            properties: [
                new OA\Property(property: 'email', type: 'string', format: 'email'),
                new OA\Property(property: 'password', type: 'string'),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Login successful', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Invalid credentials', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 403, description: 'Account banned or inactive', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/login', name: 'login', methods: ['POST'])]
    public function login(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            return $this->json(['error' => 'Email and password are required'], 422);
        }

        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if (!$endUser || !$this->hasher->isPasswordValid($endUser, $password)) {
            return $this->json(['error' => 'Invalid credentials'], 401);
        }

        if (!$endUser->isActive()) {
            return $this->json(['error' => 'Account is ' . $endUser->status], 403);
        }

        // Check 2FA
        $projectSettings = $project->getSettings() ?? [];
        $endUserTwoFactorEnabled = $projectSettings['security']['endUserTwoFactor'] ?? false;

        if ($endUserTwoFactorEnabled && $endUser->twoFactorEnabled) {
            $emailCodeHash = null;
            if ($endUser->twoFactorMethod === 'email') {
                $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                // Envoyer le code par email, stocker uniquement le hash dans le JWT
                $this->container->get(\App\Service\TwoFactorMailer::class)
                    ->sendCode($endUser->email, $code, 'JamboAPI');
                $emailCodeHash = hash('sha256', $code);
            }
            $twoFactorToken = $this->jwtService->createTwoFactorToken($endUser, $emailCodeHash);
            return $this->json([
                'requires_2fa' => true,
                'two_factor_token' => $twoFactorToken,
                'two_factor_method' => $endUser->twoFactorMethod,
            ]);
        }

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/verify-2fa',
        summary: 'Verify 2FA code and exchange ephemeral token for JWT pair',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['two_factor_token', 'code'],
            properties: [
                new OA\Property(property: 'two_factor_token', type: 'string'),
                new OA\Property(property: 'code', type: 'string'),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: '2FA verified, JWT pair returned', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', properties: [
                    new OA\Property(property: 'user', ref: '#/components/schemas/EndUser'),
                    new OA\Property(property: 'access_token', type: 'string'),
                    new OA\Property(property: 'refresh_token', type: 'string'),
                ]),
            ])),
            new OA\Response(response: 401, description: 'Invalid 2FA token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
            new OA\Response(response: 422, description: 'Invalid code or missing fields', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/verify-2fa', name: 'verify_2fa', methods: ['POST'])]
    public function verifyTwoFactor(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $twoFactorToken = $data['two_factor_token'] ?? '';
        $code = (string) ($data['code'] ?? '');

        if (empty($twoFactorToken) || empty($code)) {
            return $this->json(['error' => 'Token and code are required'], 422);
        }

        // Validate the 2FA JWT
        $claims = $this->jwtService->validateTwoFactorToken($twoFactorToken);
        if ($claims === null) {
            return $this->json(['error' => 'Invalid or expired 2FA token. Please login again.'], 401);
        }

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $claims['euid']]);
        if (!$endUser || !$endUser->isActive()) {
            return $this->json(['error' => 'User not found or inactive'], 401);
        }

        // Verify the code
        $valid = false;
        if ($endUser->twoFactorMethod === 'totp') {
            $valid = $this->twoFactorService->verifyTotp($endUser->twoFactorSecret ?? '', $code);
        } elseif ($endUser->twoFactorMethod === 'email') {
            // Le JWT contient un hash du code (pas le code en clair)
            $storedHash = $claims['ech'] ?? null;
            $valid = $storedHash !== null && hash('sha256', $code) === $storedHash;
        }

        // Fallback: backup codes
        if (!$valid && $endUser->twoFactorBackupCodes) {
            $codes = $endUser->twoFactorBackupCodes;
            $valid = $this->twoFactorService->verifyAndConsumeBackupCode($codes, $code);
            if ($valid) {
                $endUser->twoFactorBackupCodes = $codes;
                $this->em->flush();
            }
        }

        if (!$valid) {
            return $this->json(['error' => 'Invalid code'], 422);
        }

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'user' => $this->serializeEndUser($endUser),
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/refresh',
        summary: 'Refresh access and refresh tokens',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['refresh_token'],
            properties: [new OA\Property(property: 'refresh_token', type: 'string')]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'New token pair', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/TokenPair'),
            ])),
            new OA\Response(response: 401, description: 'Invalid or expired refresh token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/refresh', name: 'refresh', methods: ['POST'])]
    public function refresh(Request $request, string $_projectId): JsonResponse
    {
        $data = $request->toArray();
        $refreshJwt = $data['refresh_token'] ?? '';

        if (empty($refreshJwt)) {
            return $this->json(['error' => 'Refresh token is required'], 422);
        }

        $claims = $this->jwtService->validateToken($refreshJwt);
        if ($claims === null || !$this->jwtService->isRefreshToken($claims)) {
            return $this->json(['error' => 'Invalid or expired refresh token'], 401);
        }

        $endUser = $this->endUserRepository->findOneBy(['uuid' => $claims['euid']]);
        if (!$endUser || !$endUser->isActive()) {
            return $this->json(['error' => 'User not found or inactive'], 401);
        }

        if ($endUser->tokenVersion !== $claims['tkn']) {
            return $this->json(['error' => 'Token has been revoked'], 401);
        }

        $accessToken = $this->jwtService->createAccessToken($endUser);
        $refreshToken = $this->jwtService->createRefreshToken($endUser);

        return $this->json([
            'data' => [
                'access_token'  => $accessToken,
                'refresh_token' => $refreshToken,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/{projectId}/auth/me',
        summary: 'Get authenticated end-user profile',
        security: [['EndUserJWT' => []]],
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Current user', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/EndUser'),
            ])),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/me', name: 'me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[OA\Patch(
        path: '/api/{projectId}/auth/me',
        summary: 'Update authenticated end-user profile',
        security: [['EndUserJWT' => []]],
        requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
            new OA\Property(property: 'name', type: 'string', nullable: true),
            new OA\Property(property: 'custom_fields', type: 'object', nullable: true),
            new OA\Property(property: 'password', type: 'string', minLength: 8, nullable: true),
        ])),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Updated user', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'data', ref: '#/components/schemas/EndUser'),
            ])),
            new OA\Response(response: 401, description: 'Unauthorized', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/me', name: 'me_update', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if (!$endUser instanceof EndUser) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $data = $request->toArray();

        if (isset($data['username'])) {
            $endUser->username = $data['username'];
        }
        if (isset($data['custom_fields'])) {
            $endUser->customFields = $data['custom_fields'];
        }
        if (isset($data['password'])) {
            if (strlen($data['password']) < 8) {
                return $this->json(['error' => 'Password must be at least 8 characters'], 422);
            }
            $endUser->password = $this->hasher->hashPassword($endUser, $data['password']);
            $endUser->tokenVersion++;
        }

        $this->em->flush();

        return $this->json(['data' => $this->serializeEndUser($endUser)]);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/logout',
        summary: 'Invalidate all tokens for the authenticated end-user',
        security: [['EndUserJWT' => []]],
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 204, description: 'Logged out'),
        ]
    )]
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $endUser = $request->attributes->get('_end_user');
        if ($endUser instanceof EndUser) {
            $endUser->tokenVersion++;
            $this->em->flush();
        }

        return $this->json(null, 204);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/forgot-password',
        summary: 'Request a password reset link',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['email'],
            properties: [new OA\Property(property: 'email', type: 'string', format: 'email')]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Reset link sent (if email exists)', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
            ])),
        ]
    )]
    #[Route('/forgot-password', name: 'forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $email = $data['email'] ?? '';

        if (empty($email)) {
            return $this->json(['error' => 'Email is required'], 422);
        }

        // Always return success to prevent email enumeration
        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $email);
        if ($endUser) {
            $this->em->getRepository(PasswordResetToken::class)
                ->createQueryBuilder('t')
                ->delete()
                ->where('t.email = :email AND t.project = :project')
                ->setParameter('email', $email)
                ->setParameter('project', $project)
                ->getQuery()
                ->execute();

            $token = new PasswordResetToken($project, $email);
            $this->em->persist($token);
            $this->em->flush();
        }

        return $this->json(['message' => 'If the email exists, a reset link has been sent.']);
    }

    #[OA\Post(
        path: '/api/{projectId}/auth/reset-password',
        summary: 'Reset password using a token',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token', 'password'],
            properties: [
                new OA\Property(property: 'token', type: 'string'),
                new OA\Property(property: 'password', type: 'string', minLength: 8),
            ]
        )),
        parameters: [new OA\Parameter(name: 'projectId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Password reset successfully', content: new OA\JsonContent(properties: [
                new OA\Property(property: 'message', type: 'string'),
            ])),
            new OA\Response(response: 400, description: 'Invalid or expired token', content: new OA\JsonContent(ref: '#/components/schemas/Error')),
        ]
    )]
    #[Route('/reset-password', name: 'reset_password', methods: ['POST'])]
    public function resetPassword(Request $request, string $projectId): JsonResponse
    {
        $project = $this->projectRepository->findOneBy(['uuid' => $projectId]);
        if (!$project) {
            return $this->json(['error' => 'Project not found'], 404);
        }

        $data = $request->toArray();
        $tokenStr = $data['token'] ?? '';
        $newPassword = $data['password'] ?? '';

        if (empty($tokenStr) || empty($newPassword)) {
            return $this->json(['error' => 'Token and password are required'], 422);
        }
        if (strlen($newPassword) < 8) {
            return $this->json(['error' => 'Password must be at least 8 characters'], 422);
        }

        $token = $this->em->getRepository(PasswordResetToken::class)
            ->findOneBy(['token' => $tokenStr, 'project' => $project]);

        if (!$token || $token->isExpired()) {
            return $this->json(['error' => 'Invalid or expired token'], 400);
        }

        $endUser = $this->endUserRepository->findOneByProjectAndEmail($project, $token->email);
        if (!$endUser) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $endUser->password = $this->hasher->hashPassword($endUser, $newPassword);
        $endUser->tokenVersion++;
        $this->em->remove($token);
        $this->em->flush();

        return $this->json(['message' => 'Password has been reset successfully.']);
    }
}
