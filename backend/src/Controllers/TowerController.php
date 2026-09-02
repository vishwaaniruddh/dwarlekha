<?php
namespace App\Controllers;

use App\Models\Tower;
use App\Config\Database;
use Exception;

class TowerController extends BaseController {
    private Tower $towerModel;

    public function __construct(?Tower $towerModel = null) {
        $this->towerModel = $towerModel ?: new Tower();
    }

    public function index(): void {
        $towers = $this->towerModel->getBySocietyId();
        $this->success($towers);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        $name = trim($input['name'] ?? '');
        if (empty($name)) {
            $this->error('Block / Tower name is required.', 400);
            return;
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $id = $this->towerModel->create($input);
            $tower = $this->towerModel->findById($id);

            if ($manageTx) {
                $db->commit();
            }

            $this->success($tower, 'Block/Tower created successfully', 201);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->error($e->getMessage(), 400);
        }
    }

    public function update(int $id): void {
        $input = $this->getJsonInput();
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $existing = $this->towerModel->findById($id);
            if (!$existing) {
                $this->error('Block/Tower not found', 404);
                return;
            }

            $this->towerModel->update($id, $input);
            $updated = $this->towerModel->findById($id);

            if ($manageTx) {
                $db->commit();
            }

            $this->success($updated, 'Block/Tower updated successfully');
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->error($e->getMessage(), 400);
        }
    }

    public function delete(int $id): void {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $existing = $this->towerModel->findById($id);
            if (!$existing) {
                $this->error('Block/Tower not found', 404);
                return;
            }

            $this->towerModel->delete($id);

            if ($manageTx) {
                $db->commit();
            }

            $this->success(null, 'Block/Tower deleted successfully');
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            $this->error($e->getMessage(), 400);
        }
    }
}
