<?php

namespace App\Controller\Admin;

use App\Repository\TariffRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The four pricing categories of the venue.
 *
 * The catalogue is **fixed**: it is defined by the application and enforced by
 * the database. This screen is a read-only reference — it documents the rules
 * attached to each tariff (docs/DECISIONS.md Q5). There is deliberately no
 * form here, and no route that writes anything.
 */
#[Route('/admin/tariffs')]
class TariffController extends AbstractController
{
    #[Route('', name: 'admin_tariffs', methods: ['GET'])]
    public function index(TariffRepository $tariffs): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/tariffs/index.html.twig', [
            'tariffs' => $tariffs->catalogue(),
        ]);
    }
}
