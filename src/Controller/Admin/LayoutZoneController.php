<?php

namespace App\Controller\Admin;

use App\DTO\LayoutZoneFormData;
use App\Entity\LayoutZone;
use App\Entity\SeatingLayout;
use App\Exception\LayoutCannotBeModifiedException;
use App\Exception\ZoneCodeAlreadyExistsException;
use App\Form\Admin\LayoutZoneType;
use App\Service\Layout\LayoutZoneService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The zones of a configuration — reserved to the administrators.
 */
#[Route('/admin/layouts/{layoutId}/zones')]
class LayoutZoneController extends AbstractController
{
    #[Route('/new', name: 'admin_layout_zones_new', requirements: ['layoutId' => '\\d+'], methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        #[MapEntity(id: 'layoutId')] SeatingLayout $layout,
        LayoutZoneService $zoneService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $form = $this->createForm(LayoutZoneType::class, new LayoutZoneFormData());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var LayoutZoneFormData $data */
            $data = $form->getData();

            try {
                $zoneService->create($layout, $data->code, $data->name, $data->description);
            } catch (ZoneCodeAlreadyExistsException $exception) {
                $form->get('code')->addError(new FormError($exception->getMessage()));

                return $this->render('admin/layouts/zone_form.html.twig', [
                    'form' => $form,
                    'layout' => $layout,
                    'zone' => null,
                ]);
            } catch (LayoutCannotBeModifiedException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
            }

            $this->addFlash('success', 'The zone has been created.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        return $this->render('admin/layouts/zone_form.html.twig', [
            'form' => $form,
            'layout' => $layout,
            'zone' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_layout_zones_edit', requirements: ['layoutId' => '\\d+', 'id' => '\\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        #[MapEntity(id: 'layoutId')] SeatingLayout $layout,
        LayoutZone $zone,
        LayoutZoneService $zoneService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = new LayoutZoneFormData();
        $data->code = $zone->getCode();
        $data->name = $zone->getName();
        $data->description = $zone->getDescription();

        $form = $this->createForm(LayoutZoneType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var LayoutZoneFormData $data */
            $data = $form->getData();

            try {
                $zoneService->update($zone, $data->code, $data->name, $data->description);
            } catch (ZoneCodeAlreadyExistsException $exception) {
                $form->get('code')->addError(new FormError($exception->getMessage()));

                return $this->render('admin/layouts/zone_form.html.twig', [
                    'form' => $form,
                    'layout' => $layout,
                    'zone' => $zone,
                ]);
            } catch (LayoutCannotBeModifiedException $exception) {
                $this->addFlash('danger', $exception->getMessage());

                return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
            }

            $this->addFlash('success', 'The zone has been updated.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        return $this->render('admin/layouts/zone_form.html.twig', [
            'form' => $form,
            'layout' => $layout,
            'zone' => $zone,
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_layout_zones_delete', requirements: ['layoutId' => '\\d+', 'id' => '\\d+'], methods: ['POST'])]
    public function delete(
        Request $request,
        #[MapEntity(id: 'layoutId')] SeatingLayout $layout,
        LayoutZone $zone,
        LayoutZoneService $zoneService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('delete_zone_'.$zone->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'The request could not be validated. Please try again.');

            return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
        }

        try {
            $zoneService->delete($zone);
            $this->addFlash('success', 'The zone has been deleted. Its seats are unmapped again.');
        } catch (LayoutCannotBeModifiedException $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_layouts_show', ['id' => $layout->getId()]);
    }
}
