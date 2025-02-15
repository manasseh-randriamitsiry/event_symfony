<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $passwordHasher;
    private $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->passwordHasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        
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

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up the test database
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
