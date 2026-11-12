<?php

namespace App\Controller\Admin;

use App\DTO\UserFormData;
use App\Entity\User;
use App\Exception\AccountCannotBeDeletedException;
use App\Form\Admin\UserPasswordType;
use App\Form\Admin\UserType;
use App\Repository\UserRepository;
use App\Service\User\UserService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Account management — reserved to the administrators.
 *
 * The routes are protected by `access_control` (`/admin` requires
 * `ROLE_ADMIN`); the checks are repeated here so that the controller stays
 * safe even if the firewall configuration changes.
 */
#[Route('/admin/users')]
class UserController extends AbstractController
{
    #[Route('', name: 'admin_users', methods: ['GET'])]
    public function index(Request $request, UserRepository $users): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $page = max(1, $request->query->getInt('page', 1));
        $search = $request->query->getString('search');

        [$accounts, $pagination] = $users->paginate($page, 20, $search);

        return $this->render('admin/users/index.html.twig', [
            'users' => $accounts,
            'pagination' => $pagination,
            'search' => $search,
        ]);
    }

    #[Route('/new', name: 'admin_users_new', methods: ['GET', 'POST'])]
    public function new(Request $request, UserService $userService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(UserType::class, new UserFormData(), ['is_new' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UserFormData $data */
            $data = $form->getData();

            $userService->create(
                (string) $data->email,
                (string) $data->plainPassword,
                $data->firstName,
                $data->lastName,
                [$data->role],
                $data->phone,
            );

            $this->addFlash('success', 'The account has been created.');

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'user' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_users_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, User $user, UserService $userService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new UserFormData();
        $data->email = $user->getEmail();
        $data->firstName = $user->getFirstName();
        $data->lastName = $user->getLastName();
        $data->phone = $user->getPhone();
        $data->role = $user->getBusinessRoles()[0] ?? User::ROLE_CUSTOMER;
        $data->isActive = $user->isActive();

        $form = $this->createForm(UserType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UserFormData $data */
            $data = $form->getData();

            $userService->update(
                $user,
                $data->firstName,
                $data->lastName,
                [$data->role],
                $data->phone,
            );
            $userService->setActive($user, $data->isActive);

            $this->addFlash('success', 'The account has been updated.');

            return $this->redirectToRoute('admin_users');
        }

        return $this->render('admin/users/form.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/password', name: 'admin_users_password', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function password(Request $request, User $user, UserService $userService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(UserPasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{plainPassword: string} $data */
            $data = $form->getData();

            $userService->resetPassword($user, $data['plainPassword']);

            $this->addFlash('success', 'The password has been changed.');

            return $this->redirectToRoute('admin_users_edit', ['id' => $user->getId()]);
        }

        return $this->render('admin/users/password.html.twig', [
            'form' => $form,
            'user' => $user,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_users_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, User $user, UserService $userService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_user_'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_users');
        }

        try {
            $userService->delete($user, $this->getUser());
            $this->addFlash('success', 'The account has been deleted.');
        } catch (AccountCannotBeDeletedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_users');
    }

    #[Route('/{id}/toggle', name: 'admin_users_toggle', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function toggle(Request $request, User $user, UserService $userService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('toggle_user_'.$user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_users');
        }

        $active = !$user->isActive();
        $userService->setActive($user, $active);

        $this->addFlash('success', $active
            ? 'The account has been enabled.'
            : 'The account has been disabled.');

        return $this->redirectToRoute('admin_users');
    }
}
