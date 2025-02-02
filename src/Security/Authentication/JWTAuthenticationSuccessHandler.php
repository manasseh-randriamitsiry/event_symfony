<?php

namespace App\Security\Authentication;

use Lexik\Bundle\JWTAuthenticationBundle\Security\Http\Authentication\AuthenticationSuccessHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

class JWTAuthenticationSuccessHandler extends AuthenticationSuccessHandler
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): JsonResponse
    {
        $user = $token->getUser();
        $jwtToken = $this->jwtManager->create($user);

        $response = new JsonResponse([
            'token' => $jwtToken,
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'name' => $user->getName(),
            ],
        ]);

        // Set JWT as a cookie
        $response->headers->setCookie(
            new Cookie(
                'BEARER',           // Cookie name
                $jwtToken,          // Cookie value
                time() + 604800,    // Expiration (7 days)
                '/',               // Path
                null,              // Domain
                true,              // Secure
                true,              // HttpOnly
                false,             // Raw
                'strict'           // SameSite
            )
        );

        $this->dispatcher->dispatch(new AuthenticationSuccessEvent(['token' => $jwtToken], $user, $response));

        return $response;
    }
}
