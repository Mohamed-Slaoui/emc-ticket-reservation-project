<?php

namespace App\Controller\Admin;

use App\DTO\PerformanceFormData;
use App\Entity\Event;
use App\Entity\Performance;
use App\Exception\InvalidPerformanceScheduleException;
use App\Exception\PerformanceCannotBeDeletedException;
use App\Exception\PerformanceRoomUnavailableException;
use App\Form\Admin\PerformanceType;
use App\Repository\PerformancePlaceRepository;
use App\Service\Event\PerformanceService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The performances of an event — reserved to the administrators.
 */
#[Route('/admin/events/{eventId}/performances')]
class PerformanceController extends AbstractController
{
    use RendersUnprocessableForm;

    #[Route('/new', name: 'admin_performances_new', requirements: ['eventId' => '\\d+'], methods: ['GET', 'POST'])]
    public function new(Request $request, #[MapEntity(id: 'eventId')] Event $event, PerformanceService $performanceService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(PerformanceType::class, new PerformanceFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PerformanceFormData $data */
            $data = $form->getData();

            try {
                $performanceService->create(
                    $event,
                    $data->layout,
                    $data->startsAt,
                    $data->endsAt,
                    $data->salesOpenAt,
                    $data->salesCloseAt,
                );
            } catch (InvalidPerformanceScheduleException|PerformanceRoomUnavailableException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->renderUnprocessable('admin/performances/form.html.twig', [
                    'form' => $form,
                    'event' => $event,
                    'performance' => null,
                ]);
            }

            $this->addFlash('success', 'The performance has been scheduled and its inventory has been generated.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        return $this->render('admin/performances/form.html.twig', [
            'form' => $form,
            'event' => $event,
            'performance' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_performances_edit', requirements: ['eventId' => '\\d+', 'id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(id: 'eventId')] Event $event,
        Performance $performance,
        PerformanceService $performanceService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new PerformanceFormData();
        $data->layout = $performance->getSeatingLayout();
        $data->startsAt = $performance->getStartsAt();
        $data->endsAt = $performance->getEndsAt();
        $data->salesOpenAt = $performance->getSalesOpenAt();
        $data->salesCloseAt = $performance->getSalesCloseAt();

        $form = $this->createForm(PerformanceType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var PerformanceFormData $data */
            $data = $form->getData();

            try {
                $performanceService->update(
                    $performance,
                    $data->startsAt,
                    $data->endsAt,
                    $data->salesOpenAt,
                    $data->salesCloseAt,
                );
            } catch (InvalidPerformanceScheduleException|PerformanceRoomUnavailableException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->renderUnprocessable('admin/performances/form.html.twig', [
                    'form' => $form,
                    'event' => $event,
                    'performance' => $performance,
                ]);
            }

            $this->addFlash('success', 'The performance has been updated.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        return $this->render('admin/performances/form.html.twig', [
            'form' => $form,
            'event' => $event,
            'performance' => $performance,
        ]);
    }

    #[Route('/{id}/cancel', name: 'admin_performances_cancel', requirements: ['eventId' => '\\d+', 'id' => '\\d+'], methods: ['POST'])]
    public function cancel(
        Request $request,
        #[MapEntity(id: 'eventId')] Event $event,
        Performance $performance,
        PerformanceService $performanceService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('cancel_performance_'.$performance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        $performanceService->cancel($performance);

        $this->addFlash('success', 'The performance has been cancelled.');

        return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_performances_delete', requirements: ['eventId' => '\\d+', 'id' => '\\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        #[MapEntity(id: 'eventId')] Event $event,
        Performance $performance,
        PerformanceService $performanceService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_performance_'.$performance->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        try {
            $performanceService->delete($performance);
            $this->addFlash('success', 'The performance and its inventory have been deleted.');
        } catch (PerformanceCannotBeDeletedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
    }
}
