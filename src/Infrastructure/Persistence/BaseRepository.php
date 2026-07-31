<?php

namespace LemurAse\Infrastructure\Persistence;

use LemurAse\Shared\Infrastructure\LemurInstance;
use LemurQuery;

abstract class BaseRepository
{
    /**
     * Define the logical table name for the repository.
     */
    abstract protected function getTableName(): string;

    /**
     * Get a fresh query builder instance for the table.
     */
    protected function query(): LemurQuery
    {
        return LemurInstance::get()->query($this->getTableName());
    }

    /**
     * Common Filter: Find by External Client ID.
     */
    public function findByClient(string $clientId): array
    {
        return $this->query()->where(['external_client_id' => $clientId])->get();
    }

    /**
     * Common Filter: Find by Status.
     */
    public function findByStatus(string $status): array
    {
        return $this->query()->where(['status' => $status])->get();
    }

    /**
     * Common Filter: Find by Client and Status.
     */
    public function findByClientAndStatus(string $clientId, string $status): array
    {
        return $this->query()
            ->where(['external_client_id' => $clientId, 'status' => $status])
            ->get();
    }

    /**
     * Common Filter: Paginate results.
     */
    public function paginate(int $limit, int $offset = 0): array
    {
        return $this->query()->limit($limit, $offset)->get();
    }

    /**
     * Common Filter: Find records where a date column is between two dates.
     */
    public function whereDateBetween(string $column, string $startDate, string $endDate): array
    {
        return $this->query()->between($column, $startDate, $endDate)->get();
    }

    /**
     * Common Filter: Find records where a date column is before a specific date.
     */
    public function whereDateBefore(string $column, string $date): array
    {
        return $this->query()->whereRaw("{$column} < ?", [$date])->get();
    }

    /**
     * Common Filter: Find records where a date column is after a specific date.
     */
    public function whereDateAfter(string $column, string $date): array
    {
        return $this->query()->whereRaw("{$column} > ?", [$date])->get();
    }

    /**
     * Common Filter: Exists by specific criteria.
     */
    protected function exists(array $criteria): bool
    {
        return (bool) $this->query()->where($criteria)->first();
    }
}
