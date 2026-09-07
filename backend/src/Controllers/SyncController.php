<?php
namespace App\Controllers;

use App\Services\SyncService;
use Exception;

class SyncController extends BaseController {
    private SyncService $syncService;

    public function __construct(?SyncService $syncService = null) {
        $this->syncService = $syncService ?: new SyncService();
    }

    public function status(): void {
        try {
            $status = $this->syncService->getStatus();
            $this->success($status);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function compare(): void {
        try {
            $input = $this->getJsonInput();
            $remoteUrl = $input['remote_url'] ?? $_GET['remote_url'] ?? 'https://dwarlekha.sarsspl.com/backend/public';
            $secretKey = $input['secret_key'] ?? $_GET['secret_key'] ?? '';
            $result = $this->syncService->getComparison($remoteUrl, $secretKey);
            $this->success($result);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function schema(): void {
        try {
            $table = $_GET['table'] ?? '';
            if (empty($table)) {
                throw new Exception("Table name parameter required");
            }
            $schema = $this->syncService->getTableSchema($table);
            $this->success(['table' => $table, 'schema' => $schema]);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function export(): void {
        try {
            $input = $this->getJsonInput();
            $tables = $input['tables'] ?? [];
            $export = $this->syncService->exportData(is_array($tables) ? $tables : []);
            $this->success($export);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function import(): void {
        try {
            $input = $this->getJsonInput();
            $mode = $input['mode'] ?? 'replace';
            $result = $this->syncService->importData($input, $mode);
            $this->success($result, "Database synchronized successfully.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function pushRemote(): void {
        try {
            $input = $this->getJsonInput();
            $targetUrl = $input['target_url'] ?? 'http://dwarlekha.sarsspl.com/backend/public';
            $secretKey = $input['secret_key'] ?? '';
            $tables = $input['tables'] ?? [];
            $mode = $input['mode'] ?? 'replace';
            $result = $this->syncService->pushToRemote(
                $targetUrl, 
                $secretKey, 
                is_array($tables) ? $tables : [], 
                $mode
            );
            $this->success($result, "Database pushed and synchronized to remote server successfully.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function pullRemote(): void {
        try {
            $input = $this->getJsonInput();
            $sourceUrl = $input['source_url'] ?? 'http://dwarlekha.sarsspl.com/backend/public';
            $secretKey = $input['secret_key'] ?? '';
            $tables = $input['tables'] ?? [];
            $mode = $input['mode'] ?? 'replace';
            $result = $this->syncService->pullFromRemote(
                $sourceUrl, 
                $secretKey, 
                is_array($tables) ? $tables : [], 
                $mode
            );
            $this->success($result, "Database pulled from remote server and updated locally successfully.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
