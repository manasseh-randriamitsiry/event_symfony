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

        // Validate dates
        try {
            $startDate = new \DateTime($data['startDate']);
            $endDate = new \DateTime($data['endDate']);

            if ($endDate <= $startDate) {
                return $this->json([
                    'error' => 'End date must be after start date'
                ], Response::HTTP_BAD_REQUEST);
            }
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Invalid date format'
            ], Response::HTTP_BAD_REQUEST);
        }

        $event = new Event();
        $event->setTitle($data['title']);
        $event->setDescription($data['description']);
        $event->setStartDate($startDate);
        $event->setEndDate($endDate);
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

        // Handle dates if either is provided
        if (isset($data['startDate']) || isset($data['endDate'])) {
            try {
                $startDate = isset($data['startDate']) 
                    ? new \DateTime($data['startDate']) 
                    : $event->getStartDate();
                
                $endDate = isset($data['endDate']) 
                    ? new \DateTime($data['endDate']) 
                    : $event->getEndDate();

                // Validate dates
                if ($endDate <= $startDate) {
                    return $this->json([
                        'error' => 'End date must be after start date'
                    ], Response::HTTP_BAD_REQUEST);
                }

                $event->setStartDate($startDate);
                $event->setEndDate($endDate);
            } catch (\Exception $e) {
                return $this->json([
                    'error' => 'Invalid date format'
                ], Response::HTTP_BAD_REQUEST);
            }
        }

        // Update other fields if they are present
        if (isset($data['title'])) {
            $event->setTitle($data['title']);
        }
        if (isset($data['description'])) {
            $event->setDescription($data['description']);
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
}
