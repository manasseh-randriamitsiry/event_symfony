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

    #[Route('/upcoming', name: 'event_upcoming', methods: ['GET'])]
    public function upcoming(): JsonResponse
    {
        $now = new \DateTime();
        $events = $this->entityManager->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('e.startDate > :now')
            ->setParameter('now', $now)
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json($events);
    }

    #[Route('/past', name: 'event_past', methods: ['GET'])]
    public function past(): JsonResponse
    {
        $now = new \DateTime();
        $events = $this->entityManager->getRepository(Event::class)
            ->createQueryBuilder('e')
            ->where('e.endDate < :now')
            ->setParameter('now', $now)
            ->orderBy('e.endDate', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->json($events);
    }

    #[Route('/search', name: 'event_search', methods: ['GET'])]
    public function search(Request $request): JsonResponse
    {
        $queryBuilder = $this->entityManager->getRepository(Event::class)
            ->createQueryBuilder('e');

        // Search by title or description
        if ($search = $request->query->get('q')) {
            $queryBuilder
                ->where('e.title LIKE :search')
                ->orWhere('e.description LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        // Filter by date range
        if ($startDate = $request->query->get('start_date')) {
            $queryBuilder
                ->andWhere('e.startDate >= :startDate')
                ->setParameter('startDate', new \DateTime($startDate));
        }

        if ($endDate = $request->query->get('end_date')) {
            $queryBuilder
                ->andWhere('e.endDate <= :endDate')
                ->setParameter('endDate', new \DateTime($endDate));
        }

        // Filter by location
        if ($location = $request->query->get('location')) {
            $queryBuilder
                ->andWhere('e.location LIKE :location')
                ->setParameter('location', '%' . $location . '%');
        }

        // Filter by price range
        if ($minPrice = $request->query->get('min_price')) {
            $queryBuilder
                ->andWhere('e.price >= :minPrice')
                ->setParameter('minPrice', $minPrice);
        }

        if ($maxPrice = $request->query->get('max_price')) {
            $queryBuilder
                ->andWhere('e.price <= :maxPrice')
                ->setParameter('maxPrice', $maxPrice);
        }

        // Filter by available places
        if ($request->query->has('has_available_places')) {
            $queryBuilder
                ->andWhere('e.available_places > SIZE(e.attendees)');
        }

        $events = $queryBuilder
            ->orderBy('e.startDate', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json($events);
    }

    #[Route('/{id}/statistics', name: 'event_statistics', methods: ['GET'])]
    public function statistics(Event $event): JsonResponse
    {
        $attendeesCount = $event->getAttendees()->count();
        $availablePlaces = $event->getAvailablePlaces();
        $occupancyRate = $availablePlaces > 0 
            ? round(($attendeesCount / $availablePlaces) * 100, 2) 
            : 0;

        return $this->json([
            'total_places' => $availablePlaces,
            'attendees_count' => $attendeesCount,
            'available_places' => $availablePlaces - $attendeesCount,
            'occupancy_rate' => $occupancyRate,
            'is_full' => !$event->hasAvailablePlaces(),
        ]);
    }

    #[Route('/{id}/participants', name: 'event_participants', methods: ['GET'])]
    public function participants(Event $event): JsonResponse
    {
        $attendees = $event->getAttendees()->map(function($user) {
            return [
                'id' => $user->getId(),
                'name' => $user->getName(),
                'email' => $user->getEmail(),
            ];
        })->toArray();

        return $this->json([
            'event_id' => $event->getId(),
            'event_title' => $event->getTitle(),
            'total_participants' => count($attendees),
            'participants' => $attendees,
        ]);
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
