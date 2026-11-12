<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentication: signing in, signing out, and what happens when the
 * credentials are wrong.
 */
class LoginTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
    }

    public function test_the_login_page_is_reachable(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="_username"]');
        self::assertSelectorExists('form input[name="_password"]');
        self::assertSelectorExists('form input[name="_csrf_token"]');
    }

    public function test_an_administrator_can_sign_in(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => AppFixtures::USER_ADMIN_EMAIL,
            '_password' => AppFixtures::DEMO_PASSWORD,
        ]));

        self::assertResponseRedirects('/');

        $this->followRedirects();
        self::assertResponseIsSuccessful();
        self::assertSame('/admin', $this->client->getRequest()->getPathInfo());
    }

    public function test_a_box_office_employee_can_sign_in(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => AppFixtures::USER_BOX_OFFICE_EMAIL,
            '_password' => AppFixtures::DEMO_PASSWORD,
        ]));

        self::assertResponseRedirects('/');

        $this->followRedirects();
        self::assertResponseIsSuccessful();
        self::assertSame('/account', $this->client->getRequest()->getPathInfo());
    }

    public function test_a_customer_can_sign_in(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => AppFixtures::USER_CUSTOMER_EMAIL,
            '_password' => AppFixtures::DEMO_PASSWORD,
        ]));

        self::assertResponseRedirects('/');

        $this->followRedirects();
        self::assertResponseIsSuccessful();
        self::assertSame('/account', $this->client->getRequest()->getPathInfo());
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => AppFixtures::USER_ADMIN_EMAIL,
            '_password' => 'not-the-password',
        ]));

        // The firewall sends the visitor back to the login page with the error.
        self::assertResponseRedirects('/login');

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('div.alert', 'Invalid credentials');
    }

    public function test_an_unknown_account_is_rejected(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'nobody@venue.test',
            '_password' => AppFixtures::DEMO_PASSWORD,
        ]));

        self::assertResponseRedirects('/login');
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        $em = $this->entityManager();
        $account = $em->getRepository(\App\Entity\User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]);
        $account->setIsActive(false);
        $em->flush();

        $crawler = $this->client->request('GET', '/login');

        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => AppFixtures::USER_CUSTOMER_EMAIL,
            '_password' => AppFixtures::DEMO_PASSWORD,
        ]));

        self::assertResponseRedirects('/login');
    }

    public function test_signing_out_gives_the_login_page_again(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(\App\Entity\User::class)
            ->findOneBy(['email' => AppFixtures::USER_ADMIN_EMAIL]));

        $this->client->request('GET', '/logout');

        self::assertResponseRedirects('/login');

        $this->client->request('GET', '/admin');
        self::assertResponseRedirects('/login');
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/login');
    }

    /**
     * Follows the redirect chain until a page is reached.
     *
     * Signing in lands on the home page, which then redirects to the
     * administration or to the personal area, depending on the role.
     */
    private function followRedirects(int $max = 3): void
    {
        for ($i = 0; $i < $max && $this->client->getResponse()->isRedirect(); ++$i) {
            $this->client->followRedirect();
        }
    }
}
