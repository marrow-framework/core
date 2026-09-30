<?php

declare(strict_types=1);

namespace Marrow\Support;

use JsonSerializable;

/**
 * Pagination result container.
 * links() renders Tailwind-compatible prev/next + page number buttons.
 */
class Paginator implements JsonSerializable
{
    private int $lastPage;

    public function __construct(
        private readonly Collection $items,
        private readonly int $total,
        private readonly int $perPage,
        private readonly int $currentPage
    ) {
        $this->lastPage = (int) ceil($total / max(1, $perPage));
    }

    public function items(): Collection
    {
        return $this->items;
    }
    public function total(): int
    {
        return $this->total;
    }
    public function perPage(): int
    {
        return $this->perPage;
    }
    public function currentPage(): int
    {
        return $this->currentPage;
    }
    public function lastPage(): int
    {
        return $this->lastPage;
    }
    public function hasMorePages(): bool
    {
        return $this->currentPage < $this->lastPage;
    }
    public function onFirstPage(): bool
    {
        return $this->currentPage <= 1;
    }

    public function links(string $pageParam = 'page'): string
    {
        if ($this->lastPage <= 1) {
            return '';
        }

        $html = '<nav class="flex items-center gap-1 mt-6" aria-label="Pagination">';

        // Previous
        if ($this->currentPage > 1) {
            $prev = $this->currentPage - 1;
            $html .= $this->pageLink($prev, '← Précédent', $pageParam);
        }

        // Page numbers (show up to 7 pages around current)
        $start = max(1, $this->currentPage - 3);
        $end = min($this->lastPage, $this->currentPage + 3);

        if ($start > 1) {
            $html .= $this->pageLink(1, '1', $pageParam);
            if ($start > 2) {
                $html .= '<span class="px-3 py-1 text-gray-500">…</span>';
            }
        }

        for ($i = $start; $i <= $end; $i++) {
            if ($i === $this->currentPage) {
                $html .= "<span class=\"px-3 py-1 rounded bg-indigo-600 text-white font-medium\">{$i}</span>";
            } else {
                $html .= $this->pageLink($i, (string) $i, $pageParam);
            }
        }

        if ($end < $this->lastPage) {
            if ($end < $this->lastPage - 1) {
                $html .= '<span class="px-3 py-1 text-gray-500">…</span>';
            }
            $html .= $this->pageLink($this->lastPage, (string) $this->lastPage, $pageParam);
        }

        // Next
        if ($this->currentPage < $this->lastPage) {
            $next = $this->currentPage + 1;
            $html .= $this->pageLink($next, 'Suivant →', $pageParam);
        }

        $html .= '</nav>';
        return $html;
    }

    private function pageLink(int $page, string $label, string $param): string
    {
        $url = $this->pageUrl($page, $param);
        return "<a href=\"{$url}\" class=\"px-3 py-1 rounded bg-gray-800 text-gray-300 hover:bg-gray-700 transition\">{$label}</a>";
    }

    private function pageUrl(int $page, string $param): string
    {
        $query = $_GET;
        $query[$param] = $page;
        return '?' . http_build_query($query);
    }

    public function jsonSerialize(): mixed
    {
        return [
            'data' => $this->items->toArray(),
            'total' => $this->total,
            'per_page' => $this->perPage,
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
        ];
    }
}
