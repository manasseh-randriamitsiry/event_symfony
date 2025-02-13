<?php

namespace App\Controller;

use App\Entity\Event;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/api/events')]
class EventController extends AbstractController
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    #[Route('', name: 'event_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $events = $this->entityManager->getRepository(Event::class)->findAll();
        return $this->json($events);
    }

    #[Route('/{id}', name: 'event_show', methods: ['GET'])]
    public function show(Event $event): JsonResponse
    {
        return $this->json($event);
    }

    #[Route('', name: 'event_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $event = new Event();
        $event->setTitle($data['title']);
        $event->setDescription($data['description']);
        $event->setDate(new \DateTime($data['date']));
        $event->setLocation($data['location']);
        $event->setAvailablePlaces($data['available_places']);
        $event->setPrice($data['price'] ?? 0);
        $event->setImageUrl($data['image_url'] ?? null);
        $event->setCreator($user);

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $this->json($event, Response::HTTP_CREATED);
    }

    #[Route('/{id}', name: 'event_update', methods: ['PUT'])]
    public function update(Request $request, Event $event, #[CurrentUser] User $user): JsonResponse
    {
        if ($event->getCreator() !== $user) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) {
            $event->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $event->setDescription($data['description']);
        }
        if (isset($data['date'])) {
            $event->setDate(new \DateTime($data['date']));
        }
        if (isset($data['location'])) {
            $event->setLocation($data['location']);
        }
        if (isset($data['available_places'])) {
            $event->setAvailablePlaces($data['available_places']);
        }
        if (isset($data['price'])) {
            $event->setPrice($data['price']);
        }
        if (isset($data['image_url'])) {
            $event->setImageUrl($data['image_url']);
        }

        $this->entityManager->flush();

        return $this->json($event);
    }

    #[Route('/{id}', name: 'event_delete', methods: ['DELETE'])]
    public function delete(Event $event, #[CurrentUser] User $user): JsonResponse
    {
        if ($event->getCreator() !== $user) {
            return $this->json(['message' => 'Access denied'], Response::HTTP_FORBIDDEN);
        }

        $this->entityManager->remove($event);
        $this->entityManager->flush();

        return $this->json(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/{id}/join', name: 'event_join', methods: ['POST'])]
    public function join(Event $event, #[CurrentUser] User $user): JsonResponse
    {
        if ($event->isUserAttending($user)) {
            return $this->json(['message' => 'Already joined'], Response::HTTP_BAD_REQUEST);
        }

        if (!$event->hasAvailablePlaces()) {
            return $this->json(['message' => 'No available places'], Response::HTTP_BAD_REQUEST);
        }

        $event->addAttendee($user);
        $this->entityManager->flush();

        return $this->json($event);
    }

    #[Route('/{id}/leave', name: 'event_leave', methods: ['DELETE'])]
    public function leave(Event $event, #[CurrentUser] User $user): JsonResponse
    {
        if (!$event->isUserAttending($user)) {
            return $this->json(['message' => 'Not attending this event'], Response::HTTP_BAD_REQUEST);
        }

        $event->removeAttendee($user);
        $this->entityManager->flush();

        return $this->json($event);
    }

    #[Route('/events/{id}', name: 'update_event', methods: ['PUT'])]
    public function updateEvent(
        Request $request,
        Event $event,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        // Check if user is the creator of the event
        if ($event->getCreator() !== $this->getUser()) {
            return $this->json(['error' => 'You can only update your own events'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);

        // Update fields if they are present in the request
        if (isset($data['title'])) {
            $event->setTitle($data['title']);
        }
        
        if (isset($data['description'])) {
            $event->setDescription($data['description']);
        }
        
        if (isset($data['date'])) {
            try {
                $date = new \DateTimeImmutable($data['date']);
                $event->setDate($date);
            } catch (\Exception $e) {
                return $this->json(['error' => 'Invalid date format'], Response::HTTP_BAD_REQUEST);
            }
        }
        
        if (isset($data['location'])) {
            $event->setLocation($data['location']);
        }
        
        if (isset($data['available_places'])) {
            $event->setAvailablePlaces($data['available_places']);
        }
        
        if (isset($data['price'])) {
            $event->setPrice($data['price']);
        }
        
        if (isset($data['image_url'])) {
            $event->setImageUrl($data['image_url']);
        }

        // Update the updatedAt timestamp
        $event->setUpdatedAt(new \DateTimeImmutable());

        $entityManager->flush();

        return $this->json([
            'message' => 'Event updated successfully',
            'event' => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
                'description' => $event->getDescription(),
                'date' => $event->getDate()->format('Y-m-d\TH:i:s\Z'),
                'location' => $event->getLocation(),
                'available_places' => $event->getAvailablePlaces(),
                'price' => $event->getPrice(),
                'image_url' => $event->getImageUrl(),
                'creator' => [
                    'id' => $event->getCreator()->getId(),
                    'name' => $event->getCreator()->getName()
                ],
                'created_at' => $event->getCreatedAt()->format('Y-m-d\TH:i:s\Z'),
                'updated_at' => $event->getUpdatedAt()->format('Y-m-d\TH:i:s\Z')
            ]
        ]);
    }
}
