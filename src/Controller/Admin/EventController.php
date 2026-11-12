<?php

namespace App\Controller\Admin;

use App\DTO\EventFormData;
use App\DTO\PageRequest;
use App\Entity\Event;
use App\Enum\EventStatus;
use App\Enum\EventType;
use App\Exception\EventCannotBeDeletedException;
use App\Exception\EventCannotBePublishedException;
use App\Form\Admin\EventType as EventForm;
use App\Repository\EventRepository;
use App\Repository\PerformanceRepository;
use App\Service\Event\EventService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The events and their performances — reserved to the administrators.
 */
#[Route('/admin/events')]
class EventController extends AbstractController
{
    #[Route('', name: 'admin_events', methods: ['GET'])]
    public function index(
        Request $request,
        EventRepository $events,
        PerformanceRepository $performances,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $status = $this->enumFromRequest($request->query->getString('status'), EventStatus::class);
        $type = $this->enumFromRequest($request->query->getString('type'), EventType::class);

        $pageRequest = PageRequest::fromRequest($request);

        [$eventList, $pagination] = $events->paginate($pageRequest->page, $pageRequest->perPage, $status, $type);

        $performanceCounts = [];
        foreach ($eventList as $event) {
            $performanceCounts[(int) $event->getId()] = $performances->countForEvent($event);
        }

        return $this->render('admin/events/index.html.twig', [
            'events' => $eventList,
            'performanceCounts' => $performanceCounts,
            'pagination' => $pagination,
            'perPage' => $pageRequest->perPage,
            'status' => $status,
            'type' => $type,
        ]);
    }

    #[Route('/new', name: 'admin_events_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EventService $eventService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(EventForm::class, new EventFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EventFormData $data */
            $data = $form->getData();

            $event = $eventService->create($data->title, $data->getEventType(), $data->description);

            $this->addFlash('success', 'The event has been created. Schedule its performances.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        return $this->render('admin/events/form.html.twig', [
            'form' => $form,
            'event' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_events_edit', requirements: ['id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Event $event, EventService $eventService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new EventFormData();
        $data->title = $event->getTitle();
        $data->type = $event->getType()->value;
        $data->description = $event->getDescription();

        $form = $this->createForm(EventForm::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var EventFormData $data */
            $data = $form->getData();

            $eventService->update($event, $data->title, $data->getEventType(), $data->description);

            $this->addFlash('success', 'The event has been updated.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        return $this->render('admin/events/form.html.twig', [
            'form' => $form,
            'event' => $event,
        ]);
    }

    /**
     * The event and its performances, with the publication checks.
     */
    #[Route('/{id}', name: 'admin_events_show', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function show(
        Event $event,
        EventService $eventService,
        PerformanceRepository $performances,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/events/show.html.twig', [
            'event' => $event,
            'performances' => $performances->findForEvent($event),
            'checks' => $eventService->publicationChecks($event),
        ]);
    }

    #[Route('/{id}/publish', name: 'admin_events_publish', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function publish(Request $request, Event $event, EventService $eventService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('publish_event_'.$event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        try {
            $eventService->publish($event);
            $this->addFlash('success', 'The event has been published.');
        } catch (EventCannotBePublishedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
    }

    #[Route('/{id}/archive', name: 'admin_events_archive', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function archive(Request $request, Event $event, EventService $eventService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('archive_event_'.$event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
        }

        $eventService->archive($event);

        $this->addFlash('success', 'The event has been archived.');

        return $this->redirectToRoute('admin_events_show', ['id' => $event->getId()]);
    }

    #[Route('/{id}/delete', name: 'admin_events_delete', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function delete(Request $request, Event $event, EventService $eventService): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_event_'.$event->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_events');
        }

        try {
            $eventService->delete($event);
            $this->addFlash('success', 'The event has been deleted.');
        } catch (EventCannotBeDeletedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_events');
    }

    /**
     * Reads an enumeration from the query string.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enumClass
     *
     * @return T|null
     */
    private function enumFromRequest(string $value, string $enumClass): ?\BackedEnum
    {
        if ('' === $value) {
            return null;
        }

        return $enumClass::tryFrom($value);
    }
}
