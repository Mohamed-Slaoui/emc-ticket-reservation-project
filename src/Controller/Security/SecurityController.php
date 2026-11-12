<?php

namespace App\Controller\Security;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The authentication screens.
 */
class SecurityController extends AbstractController
{
    /**
     * The login form. The firewall posts the credentials back to the very
     * same route, so the action only renders the page.
     */
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // The form is handled by the firewall; the controller only renders it.
        $error = $authenticationUtils->getLastAuthenticationError();
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('security/login.html.twig', [
            'error' => $error,
            'last_username' => $lastUsername,
        ]);
    }

    /**
     * The logout route.
     *
     * The action is never executed: the firewall intercepts the request and
     * clears the session. The route nevertheless has to exist, because the
     * navigation links to it.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET'])]
    public function logout(): void
    {
        throw new \LogicException('The firewall intercepts the logout request before this action is reached.');
    }

    /**
     * Where an authenticated user lands.
     */
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        if (!$this->isGranted('ROLE_USER')) {
            return $this->redirectToRoute('app_login');
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        return $this->redirectToRoute('app_account');
    }
}
