<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * The self sign-up of the public ticket office.
 */
class RegisterTest extends FunctionalTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = $this->bootWithFixtures();
    }

    public function test_the_register_page_renders(): void
    {
        $crawler = $this->client->request('GET', '/register');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="registration[email]"]');
        self::assertSelectorTextContains('.auth__brand', 'EMC Ticketing');
    }

    public function test_a_guest_creates_an_account_and_signs_in(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler->selectButton('Create my account')->form([
            'registration[email]' => 'guest@billetterie.demo',
            'registration[firstName]' => 'Nouhaila',
            'registration[lastName]' => 'Idrissi',
            'registration[password]' => 'demo1234',
            'registration[passwordConfirm]' => 'demo1234',
        ]);

        $this->client->submit($form);

        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.auth__card', 'created');

        // The new account can sign in and lands on the shop.
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Sign in')->form([
            '_username' => 'guest@billetterie.demo',
            '_password' => 'demo1234',
        ]));
        $this->client->followRedirect();
        self::assertSame('/', $this->client->getRequest()->getPathInfo());
    }

    public function test_the_duplicate_email_is_rejected(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $form = $crawler->selectButton('Create my account')->form([
            'registration[email]' => AppFixtures::USER_CUSTOMER_EMAIL,
            'registration[firstName]' => 'Nouhaila',
            'registration[lastName]' => 'Idrissi',
            'registration[password]' => 'demo1234',
            'registration[passwordConfirm]' => 'demo1234',
        ]);

        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form__error, .alert', 'already exists');
    }

    public function test_a_signed_in_user_does_not_register_again(): void
    {
        $this->client->loginUser($this->entityManager()
            ->getRepository(User::class)
            ->findOneBy(['email' => AppFixtures::USER_CUSTOMER_EMAIL]));

        $this->client->request('GET', '/register');

        self::assertResponseRedirects('/');
    }
}
