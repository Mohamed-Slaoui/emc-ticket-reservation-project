<?php

namespace App\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * The pagination asked for in the query string.
 *
 * Every list screen reads the page and the page size the same way, so the
 * rules live here rather than in each controller: the page starts at one,
 * and the page size must be one of the allowed choices.
 */
final readonly class PageRequest
{
    public function __construct(
        public int $page = 1,
        public int $perPage = Pagination::DEFAULT_PER_PAGE,
    ) {
    }

    /**
     * Reads the page and the page size from the query string.
     *
     * A page size that is not one of the allowed choices falls back to the
     * default, so a hand-crafted URL cannot ask the database for an
     * unreasonable number of rows.
     *
     * The values are read as strings and cast by hand: `InputBag::getInt()`
     * of Symfony 7.4 **throws** when the parameter is present but empty —
     * and an empty filter is exactly what a form submits.
     */
    public static function fromRequest(Request $request): self
    {
        $page = max(1, (int) $request->query->getString('page', '1'));

        $perPage = (int) $request->query->getString('perPage', (string) Pagination::DEFAULT_PER_PAGE);

        if (!in_array($perPage, Pagination::ALLOWED_PER_PAGE, true)) {
            $perPage = Pagination::DEFAULT_PER_PAGE;
        }

        return new self($page, $perPage);
    }
}
