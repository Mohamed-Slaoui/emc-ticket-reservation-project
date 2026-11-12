<?php

namespace App\DTO;

/**
 * A minimal pagination descriptor.
 *
 * The application does not need a full paginator component: a page number, a
 * page size and a total are enough to render a consistent navigation, and the
 * query itself stays a plain `LIMIT/OFFSET` in the repository.
 */
final readonly class Pagination
{
    /**
     * The number of rows a page shows by default.
     *
     * The administration screens list collections that can be long — four
     * hundred places for a single room — so the default is deliberately
     * short. The visitor chooses the size that suits them.
     */
    public const DEFAULT_PER_PAGE = 6;

    /**
     * The page sizes the visitor may choose.
     *
     * A fixed list keeps the selector usable and prevents a hand-crafted
     * URL from asking the database for an unreasonable number of rows.
     */
    public const ALLOWED_PER_PAGE = [6, 12, 24, 48, 96];

    public function __construct(
        public int $page,
        public int $perPage,
        public int $total,
    ) {
    }

    /**
     * @param int $requestedPage The page asked for; it is clamped to the
     *                           available range, so a stale bookmark cannot
     *                           produce an empty page with no explanation.
     */
    public static function create(int $requestedPage, int $perPage, int $total): self
    {
        $perPage = max(1, $perPage);
        $pageCount = max(1, (int) ceil($total / $perPage));

        return new self(min(max(1, $requestedPage), $pageCount), $perPage, $total);
    }

    public function getPageCount(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    public function getOffset(): int
    {
        return ($this->page - 1) * $this->perPage;
    }

    public function hasPrevious(): bool
    {
        return $this->page > 1;
    }

    public function hasNext(): bool
    {
        return $this->page < $this->getPageCount();
    }

    public function getPreviousPage(): int
    {
        return max(1, $this->page - 1);
    }

    public function getNextPage(): int
    {
        return min($this->getPageCount(), $this->page + 1);
    }

    /**
     * The page numbers to display, with `null` standing for an ellipsis.
     *
     * @return list<int|null>
     */
    public function getPages(): array
    {
        $pageCount = $this->getPageCount();

        if ($pageCount <= 7) {
            return range(1, $pageCount);
        }

        $pages = [1];
        $start = max(2, $this->page - 1);
        $end = min($pageCount - 1, $this->page + 1);

        if ($start > 2) {
            $pages[] = null;
        }

        for ($page = $start; $page <= $end; ++$page) {
            $pages[] = $page;
        }

        if ($end < $pageCount - 1) {
            $pages[] = null;
        }

        $pages[] = $pageCount;

        return $pages;
    }

    public function getFirstItem(): int
    {
        return $this->total === 0 ? 0 : $this->getOffset() + 1;
    }

    public function getLastItem(): int
    {
        return min($this->total, $this->getOffset() + $this->perPage);
    }
}
