<?php

namespace App\Tests\Controller;

use App\Entity\Event;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class EventControllerTest extends WebTestCase
{
    private $client;
    private $entityManager;
    private $token;
    private $testUser;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        // Clear the test database
        $this->entityManager->createQuery('DELETE FROM App\Entity\Event')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();

        // Create a test user and get JWT token
        $this->createUserAndGetToken();
    }

    private function createUserAndGetToken(): void
    {
        $userData = [
            'email' => 'test@example.com',
            'password' => 'password123',
            'name' => 'Test User'
        ];

        // Register user
        $this->client->request(
            'POST',
            '/api/auth/register',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($userData)
        );

        // Login to get token
        $this->client->request(
            'POST',
            '/api/auth/login',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'username' => $userData['email'],
                'password' => $userData['password']
            ])
        );

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode(), 'Login failed: ' . $response->getContent());
        
        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('token', $responseData, 'Token not found in response: ' . $response->getContent());
        
        $this->token = $responseData['token'];
        $this->testUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $userData['email']]);
        
        $this->assertNotNull($this->testUser, 'Test user not found in database');
    }

    private function createTestEvent(): Event
    {
        $event = new Event();
        $event->setTitle('Test Event');
        $event->setDescription('Test Description');
        $event->setDate(new \DateTime('2024-12-31'));
        $event->setLocation('Test Location');
        $event->setAvailablePlaces(100);
        $event->setPrice(0);
        $event->setCreator($this->testUser);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    private function getHeaders(): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json'
        ];
    }

    public function testListEvents(): void
    {
        $this->createTestEvent();

        $this->client->request(
            'GET',
            '/api/events',
            [],
            [],
            $this->getHeaders()
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($response);
        $this->assertCount(1, $response);
    }

    public function testGetSingleEvent(): void
    {
        $event = $this->createTestEvent();

        $this->client->request(
            'GET',
            '/api/events/' . $event->getId(),
            [],
            [],
            $this->getHeaders()
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals('Test Event', $response['title']);
    }

    public function testCreateEvent(): void
    {
        $eventData = [
            'title' => 'New Event',
            'description' => 'New Description',
            'date' => '2024-12-31T00:00:00Z',
            'location' => 'New Location',
            'available_places' => 50,
            'price' => 10
        ];

        $this->client->request(
            'POST',
            '/api/events',
            [],
            [],
            $this->getHeaders(),
            json_encode($eventData)
        );

        $this->assertEquals(201, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($eventData['title'], $response['title']);
    }

    public function testUpdateEvent(): void
    {
        $event = $this->createTestEvent();
        $updateData = [
            'title' => 'Updated Event',
            'description' => 'Updated Description'
        ];

        $this->client->request(
            'PUT',
            '/api/events/' . $event->getId(),
            [],
            [],
            $this->getHeaders(),
            json_encode($updateData)
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertEquals($updateData['title'], $response['title']);
    }

    public function testDeleteEvent(): void
    {
        $event = $this->createTestEvent();

        $this->client->request(
            'DELETE',
            '/api/events/' . $event->getId(),
            [],
            [],
            $this->getHeaders()
        );

        $this->assertEquals(204, $this->client->getResponse()->getStatusCode());
    }

    public function testJoinEvent(): void
    {
        $event = $this->createTestEvent();

        $this->client->request(
            'POST',
            '/api/events/' . $event->getId() . '/join',
            [],
            [],
            $this->getHeaders()
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertContains($this->testUser->getId(), array_column($response['attendees'], 'id'));
    }

    public function testLeaveEvent(): void
    {
        $event = $this->createTestEvent();
        
        // First join the event
        $event->addAttendee($this->testUser);
        $this->entityManager->flush();

        $this->client->request(
            'DELETE',
            '/api/events/' . $event->getId() . '/leave',
            [],
            [],
            $this->getHeaders()
        );

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertNotContains($this->testUser->getId(), array_column($response['attendees'], 'id'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up the test database
        $this->entityManager->createQuery('DELETE FROM App\Entity\Event')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\User')->execute();
        
        $this->entityManager->close();
        $this->entityManager = null;
    }
}
