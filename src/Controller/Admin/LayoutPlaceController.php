<?php

namespace App\Controller\Admin;

use App\Entity\LayoutZone;
use App\Entity\SeatingLayout;
use App\Exception\LayoutCannotBeModifiedException;
use App\Repository\LayoutZoneRepository;
use App\Service\Layout\LayoutPlaceService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The seat map of a configuration: the places mapped to the zones.
 *
 * The map holds one checkbox per seat of the room — four hundred of them —
 * so it is a plain form rather than a Symfony form: the browser sends the
 * checked seats, and the server maps them.
 */
#[Route('/admin/layouts/{layoutId}/places')]
class LayoutPlaceController extends AbstractController
{
    #[Route('/assign', name: 'admin_layout_places_assign', requirements: ['layoutId' => '\\d+'], methods: ['POST'])]
    public function assign(
        Request $request,
        #[MapEntity(id: 'layoutId')] SeatingLayout $layout,
        LayoutPlaceService $placeService,
        LayoutZoneRepository $zones,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('assign_places_'.$layout->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        /** @var list<int|string> $placeIds */
        $placeIds = array_map(
            static fn (mixed $id): int => (int) $id,
            (array) $request->request->all('places'),
        );

        if ([] === $placeIds) {
            $this->addFlash('danger', 'No seat was selected.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        $release = 'release' === $request->request->get('action');

        try {
            if ($release) {
                $released = $placeService->unassign($layout, $placeIds);

                $this->addFlash('success', sprintf(
                    '%d seat%s released: they are unmapped again.',
                    $released,
                    $released > 1 ? 's' : '',
                ));
            } else {
                $zone = $this->zoneFromRequest($request, $layout, $zones);

                if (null === $zone) {
                    $this->addFlash('danger', 'Choose the zone the seats belong to.');

                    return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
                }

                $assigned = $placeService->assign($layout, $zone, $placeIds);

                $this->addFlash('success', sprintf(
                    '%d seat%s mapped to the zone "%s".',
                    $assigned,
                    $assigned > 1 ? 's' : '',
                    $zone->getName(),
                ));
            }
        } catch (LayoutCannotBeModifiedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
    }

    /**
     * The zone chosen in the form — it must belong to the configuration.
     */
    private function zoneFromRequest(Request $request, SeatingLayout $layout, LayoutZoneRepository $zones): ?LayoutZone
    {
        $zoneId = (int) $request->request->get('zone');

        if ($zoneId <= 0) {
            return null;
        }

        $zone = $zones->find($zoneId);

        if (!$zone instanceof LayoutZone || $zone->getSeatingLayout()->getId() !== $layout->getId()) {
            return null;
        }

        return $zone;
    }
}
