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

        $result = $this->connection->fetchOne($q->toSql(), $q->getBindings());
        return $result !== null;
    }

    /**
     * Enkel pagination utan totalräkning (snabbare).
     *
     * @param int $perPage
     * @param int $currentPage
     * @return array{
     *     data: array<int, mixed>,
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
        $items = $data->toArray();

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
     *     data: array<int, mixed>,
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

        $countResult = $this->connection->fetchOne($countQuery->toSql(), $countQuery->getBindings());
        $totalRecords = (int)($countResult['total'] ?? 0);

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

        // $data är en Collection enligt QueryBuilder::get()
        $dataArray = $data->toArray();

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
     * @return array{data: array<int, mixed>, search: array{term:string,total:int,per_page:int,current_page:int,last_page:int,first_page:int}}
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

        $countResult = $this->connection->fetchOne($countQuery->toSql(), $countQuery->getBindings());
        $totalRecords = (int)($countResult['total'] ?? 0);

        $lastPage = (int) ceil($totalRecords / $perPage);
        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
        }

        $this->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $data = $this->get();

        // $data är en Collection enligt QueryBuilder::get()
        $dataArray = $data->toArray();

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