<?php

namespace App\Controller\Admin;

use App\Entity\Event;
use App\Entity\Performance;
use App\Exception\PricingValidationException;
use App\Repository\LayoutZoneRepository;
use App\Repository\TariffRepository;
use App\Service\Pricing\PerformancePriceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The prices of a performance — the pricing matrix, and the versioning of
 * the prices.
 */
#[Route('/admin/events/{eventId}/performances/{performanceId}/prices')]
class PerformancePriceController extends AbstractController
{
    use RendersUnprocessableForm;

    #[Route('', name: 'admin_performances_prices', requirements: ['eventId' => '\\d+', 'performanceId' => '\\d+'], methods: ['GET', 'POST'])]
    public function show(
        Request $request,
        #[MapEntity(id: 'eventId')] Event $event,
        #[MapEntity(id: 'performanceId')] Performance $performance,
        PerformancePriceService $priceService,
        LayoutZoneRepository $zones,
        TariffRepository $tariffs,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $zoneList = $zones->findForLayout($performance->getSeatingLayout());
        $tariffList = $tariffs->catalogue();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('save_prices_'.$performance->getId(), (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'The request could not be validated. Please try again.');

                return $this->redirectToRoute('admin_performances_prices', [
                    'eventId' => $event->getId(),
                    'performanceId' => $performance->getId(),
                ]);
            }

            $amounts = [];

            foreach ($request->request->all('price') as $zoneId => $tariffsAmounts) {
                foreach ($tariffsAmounts as $tariffId => $raw) {
                    $amounts[(int) $zoneId][(int) $tariffId] = (string) $raw;
                }
            }

            try {
                $priceService->save($performance, $amounts);
            } catch (PricingValidationException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->renderUnprocessable('admin/performances/prices.html.twig', [
                    'event' => $event,
                    'performance' => $performance,
                    'zones' => $zoneList,
                    'tariffs' => $tariffList,
                    'matrix' => $priceService->currentMatrix($performance),
                    'missing' => $priceService->missingPriceCount($performance),
                ]);
            }

            $this->addFlash('success', 'The prices have been saved.');

            return $this->redirectToRoute('admin_performances_prices', [
                'eventId' => $event->getId(),
                'performanceId' => $performance->getId(),
            ]);
        }

        return $this->render('admin/performances/prices.html.twig', [
            'event' => $event,
            'performance' => $performance,
            'zones' => $zoneList,
            'tariffs' => $tariffList,
            'matrix' => $priceService->currentMatrix($performance),
            'missing' => $priceService->missingPriceCount($performance),
        ]);
    }
}
