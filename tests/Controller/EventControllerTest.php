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
                'email' => $userData['email'],
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

    private function createTestEvent(array $data = []): Event
    {
        $event = new Event();
        $event->setTitle($data['title'] ?? 'Test Event');
        $event->setDescription($data['description'] ?? 'Test Description');
        $event->setStartDate($data['startDate'] ?? new \DateTime('2024-12-31T18:00:00Z'));
        $event->setEndDate($data['endDate'] ?? new \DateTime('2024-12-31T22:00:00Z'));
        $event->setLocation($data['location'] ?? 'Test Location');
        $event->setAvailablePlaces($data['available_places'] ?? 100);
        $event->setPrice($data['price'] ?? 0);
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
            'startDate' => '2024-12-31T18:00:00Z',
            'endDate' => '2024-12-31T22:00:00Z',
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

    public function testCreateEventWithInvalidDates(): void
    {
        $eventData = [
            'title' => 'Invalid Event',
            'description' => 'Invalid Description',
            'startDate' => '2024-12-31T22:00:00Z', // Later time
            'endDate' => '2024-12-31T18:00:00Z',   // Earlier time
            'location' => 'Test Location',
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

        $this->assertEquals(400, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $response);
        $this->assertEquals('End date must be after start date', $response['error']);
    }

    public function testUpcomingEvents(): void
    {
        // Create past event
        $this->createTestEvent([
            'title' => 'Past Event',
            'startDate' => new \DateTime('-2 days'),
            'endDate' => new \DateTime('-1 day')
        ]);

        // Create upcoming event
        $this->createTestEvent([
            'title' => 'Future Event',
            'startDate' => new \DateTime('+1 day'),
            'endDate' => new \DateTime('+2 days')
        ]);

        $this->client->request('GET', '/api/events/upcoming', [], [], $this->getHeaders());

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertIsArray($response);
        $this->assertCount(1, $response);
        $this->assertEquals('Future Event', $response[0]['title']);
    }

    public function testPastEvents(): void
    {
        // Create past event
        $this->createTestEvent([
            'title' => 'Past Event',
            'startDate' => new \DateTime('-2 days'),
            'endDate' => new \DateTime('-1 day')
        ]);

        // Create upcoming event
        $this->createTestEvent([
            'title' => 'Future Event',
            'startDate' => new \DateTime('+1 day'),
            'endDate' => new \DateTime('+2 days')
        ]);

        $this->client->request('GET', '/api/events/past', [], [], $this->getHeaders());

        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        
        $this->assertIsArray($response);
        $this->assertCount(1, $response);
        $this->assertEquals('Past Event', $response[0]['title']);
    }

    public function testSearchEvents(): void
    {
        // Create test events with different properties
        $this->createTestEvent([
            'title' => 'Concert in Paris',
            'location' => 'Paris',
            'price' => 50,
            'startDate' => new \DateTime('+1 week'),
            'endDate' => new \DateTime('+1 week +4 hours')
        ]);

        $this->createTestEvent([
            'title' => 'Workshop in London',
            'location' => 'London',
            'price' => 100,
            'startDate' => new \DateTime('+2 weeks'),
            'endDate' => new \DateTime('+2 weeks +4 hours')
        ]);

        // Test search by title
        $this->client->request('GET', '/api/events/search?q=Concert', [], [], $this->getHeaders());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $response);
        $this->assertEquals('Concert in Paris', $response[0]['title']);

        // Test search by location
        $this->client->request('GET', '/api/events/search?location=London', [], [], $this->getHeaders());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $response);
        $this->assertEquals('Workshop in London', $response[0]['title']);

        // Test search by price range
        $this->client->request('GET', '/api/events/search?min_price=75&max_price=150', [], [], $this->getHeaders());
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertCount(1, $response);
        $this->assertEquals('Workshop in London', $response[0]['title']);
    }

    public function testEventStatistics(): void
    {
        $event = $this->createTestEvent([
            'available_places' => 10
        ]);

        // Add some attendees
        $event->addAttendee($this->testUser);
        $this->entityManager->flush();

        $this->client->request('GET', "/api/events/{$event->getId()}/statistics", [], [], $this->getHeaders());
        
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('total_places', $response);
        $this->assertArrayHasKey('attendees_count', $response);
        $this->assertArrayHasKey('available_places', $response);
        $this->assertArrayHasKey('occupancy_rate', $response);
        $this->assertArrayHasKey('is_full', $response);

        $this->assertEquals(10, $response['total_places']);
        $this->assertEquals(1, $response['attendees_count']);
        $this->assertEquals(9, $response['available_places']);
        $this->assertEquals(10.0, $response['occupancy_rate']);
        $this->assertFalse($response['is_full']);
    }

    public function testEventParticipants(): void
    {
        $event = $this->createTestEvent();
        
        // Add the test user as an attendee
        $event->addAttendee($this->testUser);
        $this->entityManager->flush();

        $this->client->request('GET', "/api/events/{$event->getId()}/participants", [], [], $this->getHeaders());
        
        $this->assertEquals(200, $this->client->getResponse()->getStatusCode());
        $response = json_decode($this->client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('event_id', $response);
        $this->assertArrayHasKey('event_title', $response);
        $this->assertArrayHasKey('total_participants', $response);
        $this->assertArrayHasKey('participants', $response);

        $this->assertEquals($event->getId(), $response['event_id']);
        $this->assertEquals('Test Event', $response['event_title']);
        $this->assertEquals(1, $response['total_participants']);
        $this->assertCount(1, $response['participants']);
        $this->assertEquals('test@example.com', $response['participants'][0]['email']);
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
