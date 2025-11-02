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
        $this->selectRaw('1')->limit(1);
        $result = $this->connection->fetchOne($this->toSql(), $this->bindings);
        return $result !== null;
    }

    /**
     * Paginera resultat.
     *
     * @param int $perPage
     * @param int $currentPage
     * @return array{data: array, pagination: array{total:int,per_page:int,current_page:int,last_page:int,first_page:int}}
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
        $totalRecords = $countResult['total'] ?? 0;

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

        return [
            'data' => $data,
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
     * @return array{data: array, search: array{term:string,total:int,per_page:int,current_page:int,last_page:int,first_page:int}}
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
        $totalRecords = $countResult['total'] ?? 0;

        $lastPage = (int) ceil($totalRecords / $perPage);
        if ($currentPage > $lastPage && $lastPage > 0) {
            $currentPage = $lastPage;
        }

        $this->limit($perPage)->offset(($currentPage - 1) * $perPage);
        $data = $this->get();

        return [
            'data' => $data,
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

    /**
     * Returnera SQL med värden insatta för debug.
     *
     * @return string
     */
    public function debugSql(): string
    {
        $query = $this->toSql();
        foreach ($this->bindings as $binding) {
            $replacement = is_string($binding) ? "'" . addslashes($binding) . "'" : $binding;
            $query = preg_replace('/\?/', (string)$replacement, $query, 1);
        }
        return $query;
    }
}