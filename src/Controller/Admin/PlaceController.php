<?php

namespace App\Controller\Admin;

use App\DTO\PageRequest;
use App\DTO\PlaceBlockData;
use App\DTO\PlaceFormData;
use App\Entity\Place;
use App\Exception\PlaceCannotBeDeletedException;
use App\Exception\PlaceCodeAlreadyExistsException;
use App\Form\Admin\PlaceBlockType;
use App\Form\Admin\PlaceType;
use App\Repository\LayoutPlaceRepository;
use App\Repository\PlaceRepository;
use App\Repository\RoomRepository;
use App\Service\Venue\PlaceService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The physical places of a room — reserved to the administrators.
 */
#[Route('/admin/places')]
class PlaceController extends AbstractController
{
    #[Route('', name: 'admin_places', methods: ['GET'])]
    public function index(
        Request $request,
        PlaceRepository $places,
        RoomRepository $rooms,
        LayoutPlaceRepository $layoutPlaces,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // The filter is read as a string and cast by hand: an empty filter
        // is what the form submits, and `InputBag::getInt()` of Symfony 7.4
        // throws when the parameter is present but empty.
        $roomId = (int) $request->query->getString('room');
        $room = $roomId > 0 ? $rooms->find($roomId) : null;

        $pageRequest = PageRequest::fromRequest($request);

        [$placeList, $pagination] = $places->paginate($pageRequest->page, $pageRequest->perPage, $room);

        return $this->render('admin/places/index.html.twig', [
            'places' => $placeList,
            'rooms' => $rooms->findBy([], ['name' => 'ASC']),
            'room' => $room,
            'mapped' => $layoutPlaces->findMappedPlaceIds($placeList),
            'pagination' => $pagination,
            'perPage' => $pageRequest->perPage,
        ]);
    }

    #[Route('/new', name: 'admin_places_new', methods: ['GET', 'POST'])]
    public function new(Request $request, PlaceService $placeService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(PlaceType::class, new PlaceFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlaceFormData $data */
            $data = $form->getData();

            try {
                $placeService->create(
                    $data->room,
                    $data->code,
                    $data->getPlaceType(),
                    $data->getRowLabel(),
                    $data->seatNumber,
                    $data->isActive,
                );
            } catch (PlaceCodeAlreadyExistsException $exception) {
                // The reason is shown next to the field, and the values the
                // administrator typed are kept.
                $form->get('code')->addError(new FormError($exception->getMessage()));

                return $this->render('admin/places/form.html.twig', [
                    'form' => $form,
                    'place' => null,
                ]);
            }

            $this->addFlash('success', 'The place has been created.');

            return $this->redirectToRoute('admin_places');
        }

        return $this->render('admin/places/form.html.twig', [
            'form' => $form,
            'place' => null,
        ]);
    }

    #[Route('/block', name: 'admin_places_block', methods: ['GET', 'POST'])]
    public function block(Request $request, PlaceService $placeService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(PlaceBlockType::class, new PlaceBlockData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlaceBlockData $data */
            $data = $form->getData();

            if ($data->from > $data->to) {
                $this->addFlash('danger', 'The "from" number must be lower than or equal to the "to" number.');

                return $this->redirectToRoute('admin_places_block');
            }

            $result = $placeService->createBlock(
                $data->room,
                $data->rowLabel,
                $data->from,
                $data->to,
                $data->getPlaceType(),
                $data->codePrefix,
            );

            $message = sprintf('%d place%s created.', $result['created'], $result['created'] > 1 ? 's' : '');
            if ($result['skipped'] > 0) {
                $message .= sprintf(' %d already existed and %s skipped.', $result['skipped'], $result['skipped'] > 1 ? 'were' : 'was');
            }

            $this->addFlash('success', $message);

            return $this->redirectToRoute('admin_places', ['room' => $data->room->getId()]);
        }

        return $this->render('admin/places/block.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_places_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Place $place, PlaceService $placeService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new PlaceFormData();
        $data->room = $place->getRoom();
        $data->code = $place->getCode();
        $data->type = $place->getType()->value;
        $data->rowLabel = $place->getRowLabel();
        $data->seatNumber = $place->getSeatNumber();
        $data->isActive = $place->isActive();

        $form = $this->createForm(PlaceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PlaceFormData $data */
            $data = $form->getData();

            try {
                $placeService->update(
                    $place,
                    $data->code,
                    $data->getPlaceType(),
                    $data->getRowLabel(),
                    $data->seatNumber,
                    $data->isActive,
                );
            } catch (PlaceCodeAlreadyExistsException $exception) {
                // The reason is shown next to the field, and the values the
                // administrator typed are kept.
                $form->get('code')->addError(new FormError($exception->getMessage()));

                return $this->render('admin/places/form.html.twig', [
                    'form' => $form,
                    'place' => $place,
                ]);
            }

            $this->addFlash('success', 'The place has been updated.');

            return $this->redirectToRoute('admin_places');
        }

        return $this->render('admin/places/form.html.twig', [
            'form' => $form,
            'place' => $place,
        ]);
    }

    #[Route('/{id}/toggle', name: 'admin_places_toggle', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function toggle(Request $request, Place $place, PlaceService $placeService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('toggle_place_'.$place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_places');
        }

        $active = !$place->isActive();
        $placeService->setActive($place, $active);

        $this->addFlash('success', $active
            ? 'The place is back in service.'
            : 'The place is out of service: it is not sold any more.');

        return $this->redirectToRoute('admin_places');
    }

    #[Route('/{id}/delete', name: 'admin_places_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, Place $place, PlaceService $placeService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_place_'.$place->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_places');
        }

        try {
            $placeService->delete($place);
            $this->addFlash('success', 'The place has been deleted.');
        } catch (PlaceCannotBeDeletedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_places');
    }
}
