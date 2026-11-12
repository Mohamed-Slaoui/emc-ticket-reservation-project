<?php

namespace App\Controller\Admin;

use App\DTO\PageRequest;
use App\DTO\RoomFormData;
use App\Entity\Room;
use App\Exception\RoomCannotBeDeletedException;
use App\Form\Admin\RoomType;
use App\Repository\PlaceRepository;
use App\Repository\RoomRepository;
use App\Repository\VenueRepository;
use App\Service\Venue\RoomService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The rooms of the venues — reserved to the administrators.
 */
#[Route('/admin/rooms')]
class RoomController extends AbstractController
{
    #[Route('', name: 'admin_rooms', methods: ['GET'])]
    public function index(
        Request $request,
        RoomRepository $rooms,
        VenueRepository $venues,
        PlaceRepository $places,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // The filter is read as a string and cast by hand: an empty filter
        // is what the form submits, and `InputBag::getInt()` of Symfony 7.4
        // throws when the parameter is present but empty.
        $venueId = (int) $request->query->getString('venue');
        $venue = $venueId > 0 ? $venues->find($venueId) : null;

        $pageRequest = PageRequest::fromRequest($request);

        [$roomList, $pagination] = $rooms->paginate($pageRequest->page, $pageRequest->perPage, $venue);

        $placeCounts = [];
        foreach ($roomList as $room) {
            $placeCounts[(int) $room->getId()] = $places->countForRoom($room);
        }

        return $this->render('admin/rooms/index.html.twig', [
            'rooms' => $roomList,
            'venues' => $venues->findBy([], ['name' => 'ASC']),
            'venue' => $venue,
            'placeCounts' => $placeCounts,
            'pagination' => $pagination,
            'perPage' => $pageRequest->perPage,
        ]);
    }

    #[Route('/new', name: 'admin_rooms_new', methods: ['GET', 'POST'])]
    public function new(Request $request, RoomService $roomService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(RoomType::class, new RoomFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RoomFormData $data */
            $data = $form->getData();

            $roomService->create($data->venue, $data->name, $data->description);

            $this->addFlash('success', 'The room has been created.');

            return $this->redirectToRoute('admin_rooms');
        }

        return $this->render('admin/rooms/form.html.twig', [
            'form' => $form,
            'room' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_rooms_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Room $room, RoomService $roomService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new RoomFormData();
        $data->venue = $room->getVenue();
        $data->name = $room->getName();
        $data->description = $room->getDescription();

        $form = $this->createForm(RoomType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var RoomFormData $data */
            $data = $form->getData();

            $roomService->update($room, $data->name, $data->description);

            $this->addFlash('success', 'The room has been updated.');

            return $this->redirectToRoute('admin_rooms');
        }

        return $this->render('admin/rooms/form.html.twig', [
            'form' => $form,
            'room' => $room,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_rooms_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, Room $room, RoomService $roomService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_room_'.$room->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_rooms');
        }

        try {
            $roomService->delete($room);
            $this->addFlash('success', 'The room has been deleted.');
        } catch (RoomCannotBeDeletedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_rooms');
    }
}
