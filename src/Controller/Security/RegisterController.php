<?php

namespace App\Controller\Security;

use App\DTO\RegistrationFormData;
use App\Entity\User;
use App\Form\Security\RegistrationType;
use App\Repository\UserRepository;
use App\Service\User\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The self sign-up of the public ticket office.
 *
 * The account is always a **customer** account — box-office and administrator
 * accounts are created by the administration. A guest who lands on the shop
 * can therefore make an account and proceed straight to the checkout.
 */
class RegisterController extends AbstractController
{
    #[Route('/register', name: 'app_register', methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserService $userService,
        UserRepository $users,
    ): Response {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('booking_index');
        }

        $data = new RegistrationFormData();
        $form = $this->createForm(RegistrationType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $email = strtolower(trim((string) $data->email));

                // The two passwords must match — checked here because the
                // identical-to constraint does not support cross-field checks
                // against a plain password field.
                if ($data->password !== $data->passwordConfirm) {
                    $form->get('passwordConfirm')->addError(new FormError('The two passwords do not match.'));
                } elseif (null !== $users->findOneBy(['email' => $email])) {
                    $form->get('email')->addError(new FormError('An account with this email address already exists.'));
                } else {
                    try {
                        $userService->create(
                            $email,
                            $data->password,
                            trim((string) $data->firstName),
                            trim((string) $data->lastName),
                            [User::ROLE_CUSTOMER],
                        );

                        $this->addFlash('success', 'Your account has been created — sign in to continue.');

                        return $this->redirectToRoute('app_login');
                    } catch (\InvalidArgumentException) {
                        $form->addError(new FormError('The account could not be created — check the details and try again.'));
                    }
                }
            }

            // Turbo wants a 422 for a form that re-renders with an error.
            return $this->render('security/register.html.twig', ['form' => $form], new Response(status: 422));
        }

        return $this->render('security/register.html.twig', ['form' => $form]);
    }
}
