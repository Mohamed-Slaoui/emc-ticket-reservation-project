<?php

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a form that could not be saved.
 *
 * The answer is **422**, like an invalid form. Turbo renders a 422
 * response, while a 200 would be refused — *"Form responses must redirect
 * to another location"* — and the administrator would see nothing at all:
 * the form would stay as it was, with no message.
 */
trait RendersUnprocessableForm
{
    /**
     * @param array<string, mixed> $parameters
     */
    private function renderUnprocessable(string $view, array $parameters): Response
    {
        return $this->render($view, $parameters, new Response('', Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
