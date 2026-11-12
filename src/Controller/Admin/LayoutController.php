<?php

namespace App\Controller\Admin;

use App\DTO\PageRequest;
use App\DTO\SeatingLayoutFormData;
use App\Entity\Room;
use App\Entity\SeatingLayout;
use App\Exception\LayoutCannotBeModifiedException;
use App\Form\Admin\SeatingLayoutType;
use App\Repository\LayoutPlaceRepository;
use App\Repository\LayoutZoneRepository;
use App\Repository\PlaceRepository;
use App\Repository\RoomRepository;
use App\Repository\SeatingLayoutRepository;
use App\Service\Layout\SeatingLayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The seating layouts — the versioned configurations of the rooms.
 *
 * Reserved to the administrators.
 */
#[Route('/admin/layouts')]
class LayoutController extends AbstractController
{
    /** The number of colours the seat map cycles through. */
    private const ZONE_COLORS = 6;

    #[Route('', name: 'admin_layouts', methods: ['GET'])]
    public function index(
        Request $request,
        SeatingLayoutRepository $layouts,
        RoomRepository $rooms,
        LayoutZoneRepository $zones,
        LayoutPlaceRepository $layoutPlaces,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // The filter is read as a string and cast by hand: an empty filter
        // is what the form submits, and `InputBag::getInt()` of Symfony 7.4
        // throws when the parameter is present but empty.
        $roomId = (int) $request->query->getString('room');
        $room = $roomId > 0 ? $rooms->find($roomId) : null;

        $pageRequest = PageRequest::fromRequest($request);

        [$layoutList, $pagination] = $layouts->paginate($pageRequest->page, $pageRequest->perPage, $room);

        $zoneCounts = [];
        $placeCounts = [];

        foreach ($layoutList as $layout) {
            $zoneCounts[(int) $layout->getId()] = $zones->countForLayout($layout);
            $placeCounts[(int) $layout->getId()] = $layoutPlaces->countForLayout($layout);
        }

        return $this->render('admin/layouts/index.html.twig', [
            'layouts' => $layoutList,
            'rooms' => $rooms->findBy([], ['name' => 'ASC']),
            'room' => $room,
            'zoneCounts' => $zoneCounts,
            'placeCounts' => $placeCounts,
            'pagination' => $pagination,
            'perPage' => $pageRequest->perPage,
        ]);
    }

    #[Route('/new', name: 'admin_layouts_new', methods: ['GET', 'POST'])]
    public function new(Request $request, SeatingLayoutService $layoutService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(SeatingLayoutType::class, new SeatingLayoutFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var SeatingLayoutFormData $data */
            $data = $form->getData();

            $layout = $layoutService->create($data->room, $data->name, $data->description, $data->isActive);

            $this->addFlash('success', 'The configuration has been created. You can now define its zones and map the seats.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        return $this->render('admin/layouts/form.html.twig', [
            'form' => $form,
            'layout' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_layouts_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, SeatingLayout $layout, SeatingLayoutService $layoutService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new SeatingLayoutFormData();
        $data->room = $layout->getRoom();
        $data->name = $layout->getName();
        $data->description = $layout->getDescription();
        $data->isActive = $layout->isActive();

        $form = $this->createForm(SeatingLayoutType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var SeatingLayoutFormData $data */
            $data = $form->getData();

            try {
                $layoutService->update($layout, $data->name, $data->description, $data->isActive);
            } catch (LayoutCannotBeModifiedException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
            }

            $this->addFlash('success', 'The configuration has been updated.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        return $this->render('admin/layouts/form.html.twig', [
            'form' => $form,
            'layout' => $layout,
        ]);
    }

    /**
     * Copies the configuration into a new version of the same room.
     *
     * The new version starts empty — the administrator maps its seats.
     */
    #[Route('/{id}/new-version', name: 'admin_layouts_new_version', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function newVersion(Request $request, SeatingLayout $layout, SeatingLayoutService $layoutService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('new_version_'.$layout->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_layouts');
        }

        $clone = $layoutService->cloneAsNewVersion($layout, $layout->getName());

        $this->addFlash('success', sprintf(
            'The version %d has been created. Define its zones and map its seats.',
            $clone->getVersion(),
        ));

        return $this->redirectToRoute('admin_layouts_show', ['id' => $clone->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_layouts_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, SeatingLayout $layout, SeatingLayoutService $layoutService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_layout_'.$layout->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_layouts');
        }

        try {
            $layoutService->delete($layout);
            $this->addFlash('success', 'The configuration has been deleted.');
        } catch (LayoutCannotBeModifiedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_layouts');
    }

    /**
     * The seat map of a configuration: its zones and its seats.
     */
    #[Route('/{id}', name: 'admin_layouts_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        SeatingLayout $layout,
        SeatingLayoutService $layoutService,
        LayoutZoneRepository $zones,
        LayoutPlaceRepository $layoutPlaces,
        PlaceRepository $places,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $zoneList = $zones->findForLayout($layout);

        // The zone of every seat of the room — the map is drawn from it.
        $seatZones = [];
        foreach ($layoutPlaces->findForLayout($layout) as $layoutPlace) {
            $seatZones[(int) $layoutPlace->getPlace()->getId()] = (int) $layoutPlace->getLayoutZone()->getId();
        }

        // A zone takes its colour from its position in the configuration.
        $zoneColors = [];
        foreach ($zoneList as $index => $zone) {
            $zoneColors[(int) $zone->getId()] = ($index % self::ZONE_COLORS) + 1;
        }

        // The seats of the room, grouped by row — the map draws a room.
        $rows = [];
        foreach ($places->findForRoom($layout->getRoom()) as $place) {
            $rows[$place->getRowLabel() ?? ''][] = $place;
        }

        return $this->render('admin/layouts/show.html.twig', [
            'layout' => $layout,
            'zones' => $zoneList,
            'zoneColors' => $zoneColors,
            'rows' => $rows,
            'seatZones' => $seatZones,
            'isLocked' => $layoutService->isUsedByPerformance($layout),
        ]);
    }
}
