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
        $totalRecords = (int)($countResult['total'] ?? 0);

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
        // Visa parametriserad SQL (behåll frågetecken)
        return $this->toSql();
    }

    public function debugSqlInterpolated(): string
    {
        // Visa “prettified” SQL med insatta värden (endast för debug)
        $query = $this->toSql();
        foreach ($this->getBindings() as $binding) {
            if (is_string($binding)) {
                $replacement = "'" . addslashes($binding) . "'";
            } elseif (is_null($binding)) {
                $replacement = 'NULL';
            } elseif (is_bool($binding)) {
                $replacement = $binding ? '1' : '0';
            } elseif ($binding instanceof \DateTimeInterface) {
                $replacement = "'" . $binding->format('Y-m-d H:i:s') . "'";
            } else {
                $replacement = (string)$binding;
            }
            $query = preg_replace('/\?/', $replacement, $query, 1);
        }
        return $query;
    }
}