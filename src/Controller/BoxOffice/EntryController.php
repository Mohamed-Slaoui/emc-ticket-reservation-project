<?php

namespace App\Controller\BoxOffice;

use App\Entity\Ticket;
use App\Enum\TicketStatus;
use App\Repository\TicketRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The entry — the scanner at the door (docs/WORKFLOWS.md §5).
 *
 * The ticket is read — its number, or the code its QR image carries — and
 * redeemed: `Ticket::scan()` makes it **single use** (INV-07, BR-13). A
 * ticket already used, or void, is refused at the door.
 */
#[Route('/box-office/entry')]
class EntryController extends AbstractController
{
    #[Route('', name: 'box_office_entry', methods: ['GET', 'POST'])]
    public function entry(Request $request, TicketRepository $tickets, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_BOX_OFFICE');

        $code = trim((string) $request->request->get('code', ''));
        $ticket = null;
        $status = null;
        $message = null;
        $admitted = false;

        if ($request->isMethod('POST')) {
            $ticket = $tickets->findByNumberOrClaim($code);

            if (null === $ticket) {
                $status = TicketStatus::Void->value;
                $message = 'No ticket matches this code.';

                return $this->render('box_office/entry.html.twig', [
                    'code' => $code,
                    'ticket' => null,
                    'status' => $status,
                    'message' => $message,
                    'admitted' => false,
                ], new Response(status: 422));
            }

            try {
                $ticket->scan();
                $entityManager->flush();

                $status = TicketStatus::Used->value;
                $message = 'Admitted — the ticket is now used.';
                $admitted = true;
            } catch (\LogicException $exception) {
                $status = $ticket->getStatus()->value;
                $message = $exception->getMessage();

                return $this->render('box_office/entry.html.twig', [
                    'code' => $code,
                    'ticket' => $ticket,
                    'status' => $status,
                    'message' => $message,
                    'admitted' => false,
                ], new Response(status: 422));
            }
        }

        return $this->render('box_office/entry.html.twig', [
            'code' => $code,
            'ticket' => $ticket,
            'status' => $status,
            'message' => $message,
            'admitted' => $admitted,
        ]);
    }
}
