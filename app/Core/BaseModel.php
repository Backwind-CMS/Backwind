<?php

namespace App\Core;

use App\System\Crud\CreateService;
use App\System\Crud\UpdateService;
use App\System\Crud\DeleteService;

use App\System\Read\FindByIdService;
use App\System\Read\FindAllService;
use App\System\Read\PaginateService;
use App\System\Read\FetchAllService;
use App\System\Read\GetTotalPagesService;
use App\System\Read\FetchOne;
use App\System\Read\FetchRaw;
use App\System\Read\SearchRecords;
use App\System\Read\CountRecords;

use App\Core\Factory;
use PDO;
use PDOException;

abstract class BaseModel {
    /**
     * @var PDO Database connection instance
     */
    protected $pdo;

    /**
     * @var string Table name (must be defined in child classes)
     */
    protected $table;

    // CRUD Services
    protected $createService;
    protected $updateService;
    protected $deleteService;

    // Read Services
    protected $fetchOneService;
    protected $fetchRawService;
    protected $searchRecordsService;
    protected $countRecordsService;

    /**
     * BaseModel constructor.
     * Initializes database connection, checks table name, and sets up services.
     *
     * @throws \Exception If the table name is not defined in the child class.
     */
    public function __construct() {
        $this->pdo = Factory::getDB();

        if (!$this->table) {
            throw new \Exception("Table name is not set in " . get_called_class());
        }

        // Initialize CRUD services
        $this->createService = new CreateService($this->table);
        $this->updateService = new UpdateService($this->table);
        $this->deleteService = new DeleteService($this->table);

        // Initialize Read services
        $this->fetchOneService       = new FetchOne($this->pdo, $this->table);
        $this->fetchRawService       = new FetchRaw($this->pdo);
        $this->searchRecordsService  = new SearchRecords($this->pdo, $this->table);
        $this->countRecordsService   = new CountRecords($this->pdo, $this->table);
    }

    // -------------------
    // Create Methods
    // -------------------

    /**
     * Insert a new record.
     */
    public function create(array $data): bool {
        return $this->createService->insert($data) !== false;
    }

    /**
     * Insert a new record and return the inserted ID.
     */
    public function createAndReturnId(array $data) {
        return $this->createService->insert($data, true);
    }

    // -------------------
    // Update Methods
    // -------------------

    /**
     * Update a record by ID.
     */
    public function update(int $id, array $data): bool {
        return $this->updateService->update($id, $data);
    }

    // -------------------
    // Delete Methods
    // -------------------

    /**
     * Delete a record by ID.
     */
    public function delete(int $id): bool {
        return $this->deleteService->delete(['id' => $id]);
    }

    /**
     * Delete records based on custom conditions.
     */
    public function deleteWhere(array $conditions): bool {
        return $this->deleteService->delete($conditions);
    }

    // -------------------
    // Read Methods
    // -------------------

    /**
     * Find a record by its ID.
     */
    public function findById(int $id) {
        return FindByIdService::execute($this->pdo, $this->table, $id);
    }

    /**
     * Retrieve all records with optional filters, sorting, and pagination.
     */
    public function findAll(array $conditions = [], string $orderBy = "id DESC", int $limit = null, int $offset = 0): array {
        return FindAllService::execute($this, $conditions, $orderBy, $limit, $offset);
    }

    /**
     * Paginate records.
     */
    public function paginate(array $conditions = [], string $orderBy = "id DESC", int $perPage = 10, int $currentPage = 1): array {
        return PaginateService::execute($this->pdo, $this->table, $conditions, $orderBy, $perPage, $currentPage);
    }

    /**
     * Fetch all records based on optional conditions.
     */
    public function fetchAll(array $conditions = []): array {
        return FetchAllService::execute($this->pdo, $this->table, $conditions);
    }

    /**
     * Get total number of pages based on perPage value and optional conditions.
     */
    public function getTotalPages(int $perPage = 10, array $conditions = []): int {
        return GetTotalPagesService::execute($this, $perPage, $conditions);
    }

    /**
     * Fetch a single record based on custom conditions.
     */
    public function fetchOne(array $conditions): ?array {
        return $this->fetchOneService->handle($conditions);
    }

    /**
     * Execute raw SQL with parameters and return one result.
     */
    public function fetchRaw(string $sql, array $params = []): ?array {
        return $this->fetchRawService->handle($sql, $params);
    }

    /**
     * Search for records by keyword in a specific column.
     */
    public function search(string $column, string $keyword, string $orderBy = "id DESC", int $limit = 10): array {
        return $this->searchRecordsService->handle($column, $keyword, $orderBy, $limit);
    }

    /**
     * Count total records based on conditions.
     */
    public function count(array $conditions = []): int {
        return $this->countRecordsService->handle($conditions);
    }


    // -------------------
    // Bulk Action Methods
    // -------------------


    /**
     * Bulk update multiple records with the same data.
     *
     * Iterates over an array of IDs and applies the same update to each.
     *
     * @param array $ids Array of record IDs to update.
     * @param array $data Associative array of column => value pairs to apply.
     * @return bool True if all updates succeed, false if any fail.
     */
    public function bulkUpdate(array $ids, array $data): bool {
        if (empty($ids) || empty($data)) {
            return false;
        }

        foreach ($ids as $id) {
            $success = $this->update((int)$id, $data);
            if (!$success) {
                return false; // Optionally log which ID failed
            }
        }

        return true;
    }

    /**
     * Bulk delete multiple records by ID.
     *
     * Iterates over an array of IDs and deletes each record.
     *
     * @param array $ids Array of record IDs to delete.
     * @return bool True if all deletions succeed, false if any fail.
     */
    public function bulkDelete(array $ids): bool {
        if (empty($ids)) {
            return false;
        }

        foreach ($ids as $id) {
            $success = $this->delete((int)$id);
            if (!$success) {
                return false; // Optionally log or collect failed IDs
            }
        }

        return true;
    }

    /**
     * Bulk edit multiple records with individual updates.
     *
     * Each record must include an `id` and a `fields` array.
     * Useful when different records need different values.
     *
     * Example:
     * [
     *   ['id' => 1, 'fields' => ['status' => 'active']],
     *   ['id' => 2, 'fields' => ['title' => 'Updated']]
     * ]
     *
     * @param array $records Array of records with individual updates.
     * @return bool True if all edits succeed, false otherwise.
     */
    public function bulkEdit(array $records): bool {
        if (empty($records)) {
            return false;
        }

        try {
            $this->pdo->beginTransaction();

            foreach ($records as $record) {
                if (!isset($record['id'], $record['fields']) || !is_array($record['fields'])) {
                    continue; // Skip invalid records
                }
                $this->update($record['id'], $record['fields']);
            }

            return $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            error_log("BulkEdit Error: " . $e->getMessage());
            return false;
        }
    }
}
