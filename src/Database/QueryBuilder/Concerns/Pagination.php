<?php

declare(strict_types=1);

namespace Radix\Database\QueryBuilder\Concerns;

trait Pagination
{
    /**
     * Kontrollera om queryn returnerar någon rad.
     *
     * @return bool
     */
    public function exists(): bool
    {
        // Arbeta på klon men låt WHERE + bindningar vara kvar
        $q = clone $this;
        $q->columns = [];
        $q->orderBy = [];
        $q->limit = 1;
        $q->offset = null;
        $q->selectRaw('1');

        $result = $this->getConnection()->fetchOne($q->toSql(), $q->getBindings());
        return $result !== null;
    }

    /**
     * Enkel pagination utan totalräkning (snabbare).
     *
     * @param int $perPage
     * @param int $currentPage
     * @return array{
     *     data: array<int|string, mixed>,
     *     pagination: array{
     *         per_page: int,
     *         current_page: int,
     *         has_more: bool,
     *         first_page: int
     *     }
     * }
     */
   public function simplePaginate(int $perPage = 10, int $currentPage = 1): array
    {
        $currentPage = ($currentPage > 0) ? $currentPage : 1;
        $offset = ($currentPage - 1) * $perPage;

        // Hämta en extra rad för att indikera om det finns fler
        $this->limit($perPage + 1)->offset($offset);
        $data = $this->get(); // Collection

        // Reindexera till numeriska nycklar för att matcha array<int, mixed>
        $items = $data->values()->toArray();

        $hasMore = count($items) > $perPage;
        if ($hasMore) {
            array_pop($items); // ta bort extra raden
        }

        return [
            'data' => $items,
            'pagination' => [
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'has_more' => $hasMore,
                'first_page' => 1,
            ],
        ];
    }

    /**
     * Paginera resultat.
     *
     * @param int $perPage
     * @param int $currentPage
     * @return array{
     *     data: array<int|string, mixed>,
     *     pagination: array{
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         first_page: int
     *     }
     * }
     */
    public function paginate(int $perPage = 10, int $currentPage = 1): array
    {
        $currentPage = ($currentPage > 0) ? $currentPage : 1;
        $offset = ($currentPage - 1) * $perPage;

        $countQuery = clone $this;
        $countQuery->columns = [];
        $countQuery->orderBy = [];
        $countQuery->limit = null;
        $countQuery->offset = null;
        $countQuery->selectRaw('COUNT(*) as total');

        $countResult = $this->getConnection()->fetchOne($countQuery->toSql(), $countQuery->getBindings());

        $rawTotal = $countResult['total'] ?? 0;
        if (!is_int($rawTotal)) {
            if (is_numeric($rawTotal)) {
                $rawTotal = (int) $rawTotal;
            } else {
                $rawTotal = 0;
            }
        }
        /** @var int $rawTotal */
        $totalRecords = $rawTotal;

        $lastPage = (int) ceil($totalRecords / $perPage);

        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
            $offset = ($currentPage - 1) * $perPage;
        }

        if ($totalRecords === 0) {
            return [
                'data' => [],
                'pagination' => [
                    'total' => 0,
                    'per_page' => $perPage,
                    'current_page' => $currentPage,
                    'last_page' => $lastPage,
                    'first_page' => 1,
                ],
            ];
        }

        $this->limit($perPage)->offset($offset);
        $data = $this->get();

        // Reindexera till numeriska nycklar
        $dataArray = $data->values()->toArray();

        return [
            'data' => $dataArray,
            'pagination' => [
                'total' => $totalRecords,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'first_page' => 1,
            ],
        ];
    }

    /**
     * Sök i angivna kolumner med LIKE.
     *
     * @param string $term
     * @param array<int,string> $searchColumns
     * @param int $perPage
     * @param int $currentPage
     * @return array{
     *     data: array<int|string, mixed>,
     *     search: array{
     *         term: string,
     *         total: int,
     *         per_page: int,
     *         current_page: int,
     *         last_page: int,
     *         first_page: int
     *     }
     * }
     */
    public function search(string $term, array $searchColumns, int $perPage = 10, int $currentPage = 1): array
    {
        $currentPage = ($currentPage > 0) ? $currentPage : 1;

        if (!empty($searchColumns)) {
            $this->where(function (self $q) use ($term, $searchColumns) {
                $first = true;
                foreach ($searchColumns as $column) {
                    if ($first) {
                        $q->where($column, 'LIKE', "%$term%");
                        $first = false;
                    } else {
                        $q->orWhere($column, 'LIKE', "%$term%");
                    }
                }
            });
        }

        $countQuery = clone $this;
        $countQuery->columns = [];
        $countQuery->orderBy = [];
        $countQuery->limit = null;
        $countQuery->offset = null;
        $countQuery->selectRaw('COUNT(*) as total');

        $countResult = $this->getConnection()->fetchOne($countQuery->toSql(), $countQuery->getBindings());

        $rawTotal = $countResult['total'] ?? 0;
        if (!is_int($rawTotal)) {
            if (is_numeric($rawTotal)) {
                $rawTotal = (int) $rawTotal;
            } else {
                $rawTotal = 0;
            }
        }
        /** @var int $rawTotal */
        $totalRecords = $rawTotal;

        $lastPage = (int) ceil($totalRecords / $perPage);
        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
        }

        $this->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $data = $this->get();

        // Reindexera till numeriska nycklar
        $dataArray = $data->values()->toArray();

        return [
            'data' => $dataArray,
            'search' => [
                'term' => $term,
                'total' => $totalRecords,
                'per_page' => $perPage,
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'first_page' => 1,
            ],
        ];
    }
}