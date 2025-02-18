<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class AuthControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $passwordHasher;
    private $token;
    private $mailer;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $this->mailer = static::getContainer()->get(MailerInterface::class);
        
        // Clear the test database
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
    }

    public function testSuccessfulRegistration(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'name' => 'Test User'
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
        $responseData = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEquals($userData['email'], $responseData['user']['email']);
    }

    public function testDuplicateRegistration(): void
    {
        $userData = [
            'email' => 'duplicate@example.com',
            'password' => 'password123',
            'name' => 'Test User'
        ];

        // First registration
        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Duplicate registration
        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $this->assertEquals(409, $this->client->getResponse()->getStatusCode());
    }

    public function testSuccessfulLogin(): void
    {
        // First register a user
        $userData = [
            'email' => 'login@example.com',
            'password' => 'password123',
            'name' => 'Login Test'
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Then try to login
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $userData['email'],
                'password' => $userData['password']
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('token', $responseData);
        
        // Store token for later use
        $this->token = $responseData['token'];
        
        // Check for cookie
        $cookies = $response->headers->getCookies();
        $bearerCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'BEARER') {
                $bearerCookie = $cookie;
                break;
            }
        }
        
        $this->assertNotNull($bearerCookie, 'BEARER cookie not found');
        $this->assertEquals($responseData['token'], $bearerCookie->getValue(), 'Cookie value should match token');
        $this->assertTrue($bearerCookie->isHttpOnly(), 'Cookie should be httpOnly');
        $this->assertTrue($bearerCookie->isSecure(), 'Cookie should be secure');
    }

    public function testInvalidLogin(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'nonexistent@example.com',
                'password' => 'wrongpassword'
            ])
        );

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());
    }

    public function testLogout(): void
    {
        // First login to get a token
        $this->testSuccessfulLogin();
        
        $this->client->request(
            'POST',
            '/api/auth/logout',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ]
        );
        
        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode(), $response->getContent());
        
        // Check that cookie is cleared
        $cookies = $response->headers->getCookies();
        $bearerCookie = null;
        foreach ($cookies as $cookie) {
            if ($cookie->getName() === 'BEARER') {
                $bearerCookie = $cookie;
                break;
            }
        }
        
        $this->assertNotNull($bearerCookie, 'BEARER cookie not found');
        $this->assertEquals('', $bearerCookie->getValue(), 'Cookie should be empty');
        $this->assertTrue($bearerCookie->getExpiresTime() <= time(), 'Cookie should be expired');
    }

    public function testSuccessfulProfileUpdate(): void
    {
        // First login to get a token
        $this->testSuccessfulLogin();
        
        $updateData = [
            'name' => 'Updated Name',
            'email' => 'updated@example.com'
        ];

        $this->client->request(
            'PUT',
            '/api/auth/profile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEquals($updateData['name'], $responseData['user']['name']);
        $this->assertEquals($updateData['email'], $responseData['user']['email']);
    }

    public function testProfileUpdateWithPassword(): void
    {
        // First login to get a token
        $this->testSuccessfulLogin();
        
        $updateData = [
            'current_password' => 'password123',
            'new_password' => 'newpassword123'
        ];

        $this->client->request(
            'PUT',
            '/api/auth/profile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // Refresh user entity
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'login@example.com']);
        $this->entityManager->refresh($user);
        
        // Force validity check which will clear the code if invalid
        $user->isResetCodeValid();
        
        // Verify reset code is cleared
        $this->assertNull($user->getResetCode());
        $this->assertNull($user->getResetCodeExpiresAt());

        // Try logging in with new password
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'login@example.com',
                'password' => 'newpassword123'
            ])
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
    }

    public function testProfileUpdateWithInvalidCurrentPassword(): void
    {
        // First login to get a token
        $this->testSuccessfulLogin();
        
        $updateData = [
            'current_password' => 'wrongpassword',
            'new_password' => 'newpassword123'
        ];

        $this->client->request(
            'PUT',
            '/api/auth/profile',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token
            ],
            json_encode($updateData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('message', $responseData);
        $this->assertEquals('Current password is invalid', $responseData['message']);
    }

    public function testProfileUpdateWithoutAuthentication(): void
    {
        $updateData = [
            'name' => 'Updated Name',
            'email' => 'updated@example.com'
        ];

        $this->client->request(
            'PUT',
            '/api/auth/profile',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($updateData)
        );

        $this->assertEquals(401, $this->client->getResponse()->getStatusCode());
    }

    public function testForgotPassword(): void
    {
        // First register a user
        $userData = [
            'email' => 'reset@example.com',
            'password' => 'password123',
            'name' => 'Reset Test'
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Request password reset
        $this->client->request(
            'POST',
            '/api/auth/forgot-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $userData['email']])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        // Verify that a reset code was generated
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $userData['email']]);
        
        $this->assertNotNull($user->getResetCode());
        $this->assertNotNull($user->getResetCodeExpiresAt());
        $this->assertTrue($user->isResetCodeValid());
    }

    public function testVerifyResetCode(): void
    {
        // First request a password reset
        $this->testForgotPassword();

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'reset@example.com']);
        
        $resetCode = $user->getResetCode();

        // Test with valid code
        $this->client->request(
            'POST',
            '/api/auth/verify-reset-code',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => $resetCode
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // Test with invalid code
        $this->client->request(
            'POST',
            '/api/auth/verify-reset-code',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => '000000'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testResetPassword(): void
    {
        // First request a password reset
        $this->testForgotPassword();

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'reset@example.com']);
        
        $resetCode = $user->getResetCode();

        // Reset password with valid code
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => $resetCode,
                'new_password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // Verify reset code is cleared
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'reset@example.com']);
        $this->entityManager->refresh($user);
        
        // Force validity check which will clear the code if invalid
        $user->isResetCodeValid();
        
        // Verify reset code is cleared
        $this->assertNull($user->getResetCode());
        $this->assertNull($user->getResetCodeExpiresAt());

        // Try logging in with new password
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testResetPasswordWithInvalidCode(): void
    {
        // First request a password reset
        $this->testForgotPassword();

        // Try to reset password with invalid code
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => '000000',
                'new_password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testResetPasswordWithExpiredCode(): void
    {
        // First request a password reset
        $this->testForgotPassword();

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'reset@example.com']);
        
        $resetCode = $user->getResetCode();

        // Expire the code
        $user->setResetCodeExpiresAt(new \DateTime('-1 hour'));
        $this->entityManager->flush();

        // Try to reset password with expired code
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => $resetCode,
                'new_password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testForgotPasswordWithNonexistentEmail(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/forgot-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'nonexistent@example.com'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
        
        // Should return same message even for non-existent email
        $responseData = json_decode($response->getContent(), true);
        $this->assertEquals(
            'If an account exists for this email, you will receive a verification code',
            $responseData['message']
        );
    }

    public function testForgotPasswordWithInvalidEmail(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/forgot-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => 'invalid-email'])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testForgotPasswordWithMissingEmail(): void
    {
        $this->client->request(
            'POST',
            '/api/auth/forgot-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testCompletePasswordResetFlow(): void
    {
        // 1. Create a test user
        $userData = [
            'email' => 'reset-flow@example.com',
            'password' => 'oldpassword123',
            'name' => 'Reset Flow Test'
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // 2. Request password reset
        $this->client->request(
            'POST',
            '/api/auth/forgot-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['email' => $userData['email']])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // Get the user and their reset code
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $userData['email']]);
        
        $resetCode = $user->getResetCode();
        $this->assertNotNull($resetCode);
        $this->assertTrue($user->isResetCodeValid());

        // 3. Verify the reset code
        $this->client->request(
            'POST',
            '/api/auth/verify-reset-code',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $userData['email'],
                'code' => $resetCode
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // 4. Reset the password
        $newPassword = 'newpassword123';
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $userData['email'],
                'code' => $resetCode,
                'new_password' => $newPassword
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // Verify reset code is cleared
        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => $userData['email']]);
        $this->entityManager->refresh($user);
        
        // Force validity check which will clear the code if invalid
        $user->isResetCodeValid();
        
        // Verify reset code is cleared
        $this->assertNull($user->getResetCode());
        $this->assertNull($user->getResetCodeExpiresAt());

        // 5. Try logging in with the new password
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $userData['email'],
                'password' => $newPassword
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());

        // 6. Verify old password no longer works
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => $userData['email'],
                'password' => $userData['password']
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testVerifyResetCodeWithExpiredCode(): void
    {
        // First create a user and request reset
        $this->testForgotPassword();

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'reset@example.com']);
        
        // Expire the code
        $user->setResetCodeExpiresAt(new \DateTime('-1 hour'));
        $this->entityManager->flush();

        // Try to verify expired code
        $this->client->request(
            'POST',
            '/api/auth/verify-reset-code',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => $user->getResetCode()
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testResetPasswordWithoutVerification(): void
    {
        // First create a user and request reset
        $this->testForgotPassword();

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['email' => 'reset@example.com']);
        
        // Try to reset password without verifying first (should still work as verification is optional)
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => $user->getResetCode(),
                'new_password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testResetPasswordWithMissingFields(): void
    {
        // Test missing email
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'code' => '123456',
                'new_password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        // Test missing code
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'new_password' => 'newpassword123'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());

        // Test missing new password
        $this->client->request(
            'POST',
            '/api/auth/reset-password',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'email' => 'reset@example.com',
                'code' => '123456'
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
    }

    public function testRegistrationWithInvalidEmail(): void
    {
        $userData = [
            'email' => 'invalid-email',
            'password' => 'password123',
            'name' => 'Test User'
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('email', $responseData['errors']);
        $this->assertContains('The email "invalid-email" is not a valid email', $responseData['errors']['email']);
    }

    public function testRegistrationWithWeakPassword(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => 'weak',  // Too short and no number
            'name' => 'Test User'
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('password', $responseData['errors']);
        // Should have both length and complexity errors
        $this->assertCount(2, $responseData['errors']['password']);
    }

    public function testRegistrationWithInvalidName(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'name' => 'Test123 User!'  // Contains numbers and special characters
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(400, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('errors', $responseData);
        $this->assertArrayHasKey('name', $responseData['errors']);
        $this->assertContains('Name can only contain letters, spaces, hyphens and apostrophes', $responseData['errors']['name']);
    }

    public function testRegistrationWithValidComplexData(): void
    {
        $userData = [
            'email' => 'test-valid@example.com',
            'password' => 'StrongPass123!',  // Complex password
            'name' => "John O'Brien-Smith"   // Name with hyphen and apostrophe
        ];

        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        $response = $this->client->getResponse();
        $this->assertEquals(201, $response->getStatusCode());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('user', $responseData);
        $this->assertEquals($userData['email'], $responseData['user']['email']);
        $this->assertEquals($userData['name'], $responseData['user']['name']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up the test database
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
