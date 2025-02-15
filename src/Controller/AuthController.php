<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
    }

    #[Route('/register', name: 'app_auth_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        // Validate required fields
        if (!isset($data['email']) || !isset($data['password']) || !isset($data['name'])) {
            return $this->json(['message' => 'Missing required fields'], Response::HTTP_BAD_REQUEST);
        }

        // Check if user already exists
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json(['message' => 'User already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        
        // Hash the password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $this->json([
            'message' => 'User registered successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName()
            ]
        ], Response::HTTP_CREATED);
    }

    #[Route('/login', name: 'app_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['password'])) {
            return $this->json(['message' => 'Missing credentials'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if (!$user || !$this->passwordHasher->isPasswordValid($user, $data['password'])) {
            return $this->json(['message' => 'Invalid credentials'], Response::HTTP_UNAUTHORIZED);
        }

        $token = $this->jwtManager->create($user);

        $response = $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
            ],
            'token' => $token,
        ]);

        $response->headers->setCookie(
            Cookie::create(
                'BEARER',
                $token,
                time() + 604800, // 7 days
                '/',
                null,
                true, // secure
                true, // httpOnly
                false, // raw
                'strict' // samesite
            )
        );

        return $response;
    }

    #[Route('/profile', name: 'app_profile_edit', methods: ['PUT'])]
    public function editProfile(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->json(['message' => 'Not authenticated'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        // Handle password update
        if (isset($data['current_password']) && isset($data['new_password'])) {
            if (!$this->passwordHasher->isPasswordValid($user, $data['current_password'])) {
                return $this->json(['message' => 'Current password is invalid'], Response::HTTP_BAD_REQUEST);
            }
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['new_password']));
        }

        // Handle profile data update
        if (isset($data['name'])) {
            $user->setName($data['name']);
        }
        if (isset($data['email'])) {
            $user->setEmail($data['email']);
        }

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
            ]
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $response = new JsonResponse(['message' => 'Logged out successfully']);
        
        // Clear the BEARER cookie
        $response->headers->setCookie(
            Cookie::create(
                'BEARER',           // Cookie name
                '',                 // Empty value
                1,                  // Expire immediately
                '/',               // Path
                null,              // Domain
                true,              // Secure
                true,              // HttpOnly
                false,             // Raw
                'strict'           // SameSite
            )
        );
        
        return $response;
    }
}
