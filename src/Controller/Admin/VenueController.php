<?php

namespace App\Controller\Admin;

use App\DTO\PageRequest;
use App\DTO\VenueFormData;
use App\Entity\Venue;
use App\Exception\VenueCannotBeDeletedException;
use App\Form\Admin\VenueType;
use App\Repository\RoomRepository;
use App\Repository\VenueRepository;
use App\Service\Venue\VenueService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The venues — reserved to the administrators.
 */
#[Route('/admin/venues')]
class VenueController extends AbstractController
{
    #[Route('', name: 'admin_venues', methods: ['GET'])]
    public function index(Request $request, VenueRepository $venues, RoomRepository $rooms): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $pageRequest = PageRequest::fromRequest($request);

        [$venueList, $pagination] = $venues->paginate($pageRequest->page, $pageRequest->perPage);

        return $this->render('admin/venues/index.html.twig', [
            'venues' => $venueList,
            'roomCounts' => $this->roomCounts($venueList, $rooms),
            'pagination' => $pagination,
            'perPage' => $pageRequest->perPage,
        ]);
    }

    #[Route('/new', name: 'admin_venues_new', methods: ['GET', 'POST'])]
    public function new(Request $request, VenueService $venueService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(VenueType::class, new VenueFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var VenueFormData $data */
            $data = $form->getData();

            $venueService->create($data->name, $data->address, $data->description);

            $this->addFlash('success', 'The venue has been created.');

            return $this->redirectToRoute('admin_venues');
        }

        return $this->render('admin/venues/form.html.twig', [
            'form' => $form,
            'venue' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_venues_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Venue $venue, VenueService $venueService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new VenueFormData();
        $data->name = $venue->getName();
        $data->address = $venue->getAddress();
        $data->description = $venue->getDescription();

        $form = $this->createForm(VenueType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var VenueFormData $data */
            $data = $form->getData();

            $venueService->update($venue, $data->name, $data->address, $data->description);

            $this->addFlash('success', 'The venue has been updated.');

            return $this->redirectToRoute('admin_venues');
        }

        return $this->render('admin/venues/form.html.twig', [
            'form' => $form,
            'venue' => $venue,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_venues_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, Venue $venue, VenueService $venueService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_venue_'.$venue->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_venues');
        }

        try {
            $venueService->delete($venue);
            $this->addFlash('success', 'The venue has been deleted.');
        } catch (VenueCannotBeDeletedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_venues');
    }

    /**
     * The number of rooms of every venue.
     *
     * @param list<Venue> $venues
     *
     * @return array<int, int>
     */
    private function roomCounts(array $venues, RoomRepository $rooms): array
    {
        $counts = [];

        foreach ($venues as $venue) {
            $counts[(int) $venue->getId()] = $rooms->countForVenue($venue);
        }

        return $counts;
    }
}
