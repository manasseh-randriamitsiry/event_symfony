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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/auth')]
class AuthController extends AbstractController
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator,
        private ValidatorInterface $validator
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

        // Check if user already exists before validation
        $existingUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingUser) {
            return $this->json(['message' => 'User already exists'], Response::HTTP_CONFLICT);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setName($data['name']);
        $user->setPassword($data['password']); // Set raw password for validation
        $user->setIsVerified(false); // Set as unverified by default

        // Validate user entity
        $violations = $this->validator->validate($user);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()][] = $violation->getMessage();
            }
            return $this->json(['message' => 'Validation failed', 'errors' => $errors], Response::HTTP_BAD_REQUEST);
        }

        // Hash the password after validation
        $hashedPassword = $this->passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        // Generate verification code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->setVerificationCode($code);
        $user->setVerificationCodeExpiresAt(new \DateTime('+15 minutes'));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        // Send verification email
        $email = (new Email())
            ->from('noreply@yourdomain.com')
            ->to($user->getEmail())
            ->subject('Account Verification Code')
            ->html("<p>Hello {$user->getName()},</p>
                   <p>Your account verification code is: <strong>{$code}</strong></p>
                   <p>This code will expire in 15 minutes.</p>
                   <p>Please verify your account to access all features.</p>");

        $this->mailer->send($email);

        return $this->json([
            'message' => 'User registered successfully. Please check your email for the verification code.',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'isVerified' => $user->isVerified()
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
        $response = $this->json(['message' => 'Logged out successfully']);
        
        // Clear the BEARER cookie
        $response->headers->setCookie(
            Cookie::create(
                'BEARER',
                '',
                1,
                '/',
                null,
                true,
                true,
                false,
                'strict'
            )
        );
        
        return $response;
    }

    #[Route('/logout-success', name: 'app_logout_success', methods: ['GET'])]
    public function logoutSuccess(): JsonResponse
    {
        return $this->json(['message' => 'Logged out successfully']);
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email'])) {
            return $this->json(['message' => 'Email is required'], Response::HTTP_BAD_REQUEST);
        }

        // Validate email format
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return $this->json(['message' => 'Invalid email format'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if (!$user) {
            // Don't reveal whether a user was found or not
            return $this->json(['message' => 'If an account exists for this email, you will receive a verification code']);
        }

        // Generate 6-digit verification code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->setResetCode($code);
        $user->setResetCodeExpiresAt(new \DateTime('+15 minutes'));

        $this->entityManager->flush();

        // Send email with verification code
        $email = (new Email())
            ->from('noreply@yourdomain.com')
            ->to($user->getEmail())
            ->subject('Password Reset Verification Code')
            ->html("<p>Hello {$user->getName()},</p>
                   <p>Your password reset verification code is: <strong>{$code}</strong></p>
                   <p>This code will expire in 15 minutes.</p>
                   <p>If you did not request this reset, please ignore this email.</p>");

        $this->mailer->send($email);

        return $this->json(['message' => 'If an account exists for this email, you will receive a verification code']);
    }

    #[Route('/verify-reset-code', name: 'app_verify_reset_code', methods: ['POST'])]
    public function verifyResetCode(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['code'])) {
            return $this->json(['message' => 'Email and verification code are required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if (!$user || !$user->isResetCodeValid() || $user->getResetCode() !== $data['code']) {
            return $this->json(['message' => 'Invalid or expired verification code'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['message' => 'Verification code is valid']);
    }

    #[Route('/reset-password', name: 'app_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['code']) || !isset($data['new_password'])) {
            return $this->json(['message' => 'Email, verification code, and new password are required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if (!$user || !$user->isResetCodeValid() || $user->getResetCode() !== $data['code']) {
            return $this->json(['message' => 'Invalid or expired verification code'], Response::HTTP_BAD_REQUEST);
        }

        // Begin transaction
        $this->entityManager->beginTransaction();
        
        try {
            // Set new password (this will clear the reset code)
            $user->setPassword($this->passwordHasher->hashPassword($user, $data['new_password']));
            
            // Flush changes
            $this->entityManager->flush();
            
            // Commit transaction
            $this->entityManager->commit();
            
            // Refresh entity to ensure we have the latest state
            $this->entityManager->refresh($user);
            
            return $this->json(['message' => 'Password has been successfully reset']);
        } catch (\Exception $e) {
            // Rollback transaction on error
            $this->entityManager->rollback();
            throw $e;
        }
    }

    #[Route('/verify-account', name: 'app_verify_account', methods: ['POST'])]
    public function verifyAccount(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['email']) || !isset($data['code'])) {
            return $this->json(['message' => 'Email and verification code are required'], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);

        if (!$user) {
            return $this->json(['message' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        if ($user->isVerified()) {
            return $this->json(['message' => 'Account is already verified'], Response::HTTP_BAD_REQUEST);
        }

        if (!$user->isVerificationCodeValid() || $user->getVerificationCode() !== $data['code']) {
            return $this->json(['message' => 'Invalid or expired verification code'], Response::HTTP_BAD_REQUEST);
        }

        // Verify the account
        $user->setIsVerified(true);
        $user->setVerificationCode(null);
        $user->setVerificationCodeExpiresAt(null);

        $this->entityManager->flush();

        return $this->json([
            'message' => 'Account verified successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
                'isVerified' => $user->isVerified()
            ]
        ]);
    }
}
