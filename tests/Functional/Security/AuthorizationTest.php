<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Role-based access: who may reach which screen.
 */
class AuthorizationTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
    }

    private function loginAs(string $email): KernelBrowser
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => $email]));

        return $this->client;
    }

    public function test_an_administrator_reaches_the_administration(): void
    {
        $client = $this->loginAs(AppFixtures::USER_ADMIN_EMAIL);

        $client->request('GET', '/admin');
        self::assertResponseIsSuccessful();

        $client->request('GET', '/admin/users');
        self::assertResponseIsSuccessful();
    }

    public function test_a_customer_cannot_reach_the_administration(): void
    {
        $client = $this->loginAs(AppFixtures::USER_CUSTOMER_EMAIL);

        $client->request('GET', '/admin');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_a_box_office_employee_cannot_reach_the_administration(): void
    {
        $client = $this->loginAs(AppFixtures::USER_BOX_OFFICE_EMAIL);

        $client->request('GET', '/admin/users');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_every_signed_in_user_reaches_their_account(): void
    {
        foreach ([
            AppFixtures::USER_ADMIN_EMAIL,
            AppFixtures::USER_BOX_OFFICE_EMAIL,
            AppFixtures::USER_CUSTOMER_EMAIL,
        ] as $email) {
            $client = $this->loginAs($email);

            $client->request('GET', '/account');
            self::assertResponseIsSuccessful();
        }
    }

    public function test_the_role_hierarchy_grants_the_lower_permissions(): void
    {
        $admin = $this->entityManager()->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]);

        // An administrator inherits the customer permission.
        self::assertTrue($admin->hasRole('ROLE_ADMIN'));
        self::assertContains('ROLE_USER', $admin->getRoles());
        self::assertNotContains('ROLE_USER', $admin->getBusinessRoles());
    }
}
