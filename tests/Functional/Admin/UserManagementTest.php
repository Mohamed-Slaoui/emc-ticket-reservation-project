<?php

namespace App\Tests\Functional\Admin;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Account management — the administrator screens.
 */
class UserManagementTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
    }

    private function loginAsAdmin(): KernelBrowser
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));

        return $this->client;
    }

    public function test_the_accounts_are_listed(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', AppFixtures::USER_ADMIN_EMAIL);
        self::assertSelectorTextContains('tbody', AppFixtures::USER_CUSTOMER_EMAIL);
        self::assertCount(3, $crawler->filter('tbody tr'));
    }

    public function test_the_list_can_be_searched(): void
    {
        $client = $this->loginAsAdmin();
        $client->request('GET', '/admin/users', ['search' => 'client@']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', AppFixtures::USER_CUSTOMER_EMAIL);
        self::assertSelectorTextNotContains('tbody', AppFixtures::USER_ADMIN_EMAIL);
    }

    public function test_an_administrator_creates_an_account(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users/new');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'admin_user[email]' => 'new.box-office@venue.test',
            'admin_user[firstName]' => 'Nadia',
            'admin_user[lastName]' => 'Bennani',
            'admin_user[phone]' => '+212 600 00 00 00',
            'admin_user[role]' => 'ROLE_BOX_OFFICE',
            'admin_user[plainPassword]' => 'a-very-secret-password',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $created = $this->entityManager()->getRepository(User::class)
            ->findOneBy(['email' => 'new.box-office@venue.test']);

        self::assertNotNull($created);
        self::assertSame('Nadia', $created->getFirstName());
        self::assertTrue($created->hasRole('ROLE_BOX_OFFICE'));
        self::assertNotSame('a-very-secret-password', $created->getPassword(), 'The password is stored hashed.');
    }

    public function test_an_administrator_edits_an_account(): void
    {
        $em = $this->entityManager();
        $customer = $em->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);

        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users/'.$customer->getId().'/edit');

        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form([
            'admin_user[firstName]' => 'Karim',
            'admin_user[lastName]' => 'Ouazzani',
            'admin_user[role]' => 'ROLE_BOX_OFFICE',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $em->clear();
        $updated = $em->getRepository(User::class)->find($customer->getId());

        self::assertTrue($updated->hasRole('ROLE_BOX_OFFICE'));
    }

    public function test_an_administrator_disables_and_enables_an_account(): void
    {
        $em = $this->entityManager();
        $customer = $em->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);

        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users');

        $client->submit($crawler->filter('form[action*="/admin/users/'.$customer->getId().'/toggle"]')->form());

        self::assertResponseRedirects('/admin/users');

        $em->clear();
        self::assertFalse($em->getRepository(User::class)->find($customer->getId())->isActive());
    }

    public function test_an_administrator_resets_a_password(): void
    {
        $em = $this->entityManager();
        $customer = $em->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);
        $hashBefore = $customer->getPassword();

        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users/'.$customer->getId().'/password');

        self::assertResponseIsSuccessful();

        $client->submit($crawler->filter('form')->form([
            'admin_user_password[plainPassword]' => 'another-very-secret-password',
        ]));

        self::assertResponseRedirects('/admin/users/'.$customer->getId().'/edit');

        $em->clear();
        self::assertNotSame($hashBefore, $em->getRepository(User::class)->find($customer->getId())->getPassword());
    }

    public function test_a_customer_cannot_manage_accounts(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));

        $this->client->request('GET', '/admin/users/new');
        self::assertResponseStatusCodeSame(403);
    }

    public function test_an_administrator_deletes_an_unused_account(): void
    {
        $em = $this->entityManager();
        $boxOffice = $em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]);

        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users');

        $form = $crawler->filter('form[action*="/admin/users/'.$boxOffice->getId().'/delete"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]));
    }

    public function test_an_account_that_appears_on_a_reservation_cannot_be_deleted(): void
    {
        $em = $this->entityManager();
        $customer = $em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);

        // The customer becomes the buyer of a reservation.
        $layout = $em->getRepository(\App\Entity\SeatingLayout::class)->findOneBy(['version' => 1]);
        $event = new \App\Entity\Event('Test', \App\Enum\EventType::Concert);
        $em->persist($event);

        $performance = new \App\Entity\Performance($event, $layout, new \DateTimeImmutable('+30 days'));
        $em->persist($performance);
        $em->flush();

        $reservation = new \App\Entity\Reservation(
            $performance,
            $customer,
            \App\Enum\SalesChannel::Online,
            new \DateTimeImmutable('+10 minutes'),
            $customer,
        );
        $em->persist($reservation);
        $em->flush();

        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users');

        $form = $crawler->filter('form[action*="/admin/users/'.$customer->getId().'/delete"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'disable it instead of deleting it');

        $em->clear();
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));
    }

    public function test_the_signed_in_account_cannot_be_deleted(): void
    {
        $em = $this->entityManager();
        $admin = $em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]);

        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users');

        $form = $crawler->filter('form[action*="/admin/users/'.$admin->getId().'/delete"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/admin/users');

        $client->followRedirect();
        self::assertSelectorTextContains('.alert--danger', 'cannot delete the account you are signed in with');

        $em->clear();
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));
    }

    public function test_the_deletion_requires_a_valid_csrf_token(): void
    {
        $em = $this->entityManager();
        $boxOffice = $em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]);

        $client = $this->loginAsAdmin();
        $client->request('POST', '/admin/users/'.$boxOffice->getId().'/delete', ['_token' => 'not-a-valid-token']);

        self::assertResponseRedirects('/admin/users');

        $em->clear();
        self::assertNotNull($em->getRepository(User::class)->findOneBy(['email' => AppFixtures::USER_BOX_OFFICE_EMAIL]));
    }

    public function test_the_creation_form_refuses_an_invalid_email(): void
    {
        $client = $this->loginAsAdmin();
        $crawler = $client->request('GET', '/admin/users/new');

        $form = $crawler->filter('form')->form([
            'admin_user[email]' => 'not-an-email',
            'admin_user[firstName]' => 'Nadia',
            'admin_user[lastName]' => 'Bennani',
            'admin_user[plainPassword]' => 'a-very-secret-password',
        ]);

        $client->submit($form);

        // Symfony answers 422 when a submitted form is invalid.
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__error', 'not a valid email');
    }
}
