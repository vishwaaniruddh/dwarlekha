<?php
namespace App\Controllers;

use App\Services\GlobalSearchService;
use Exception;

class GlobalSearchController extends BaseController {
    private GlobalSearchService $searchService;

    public function __construct(?GlobalSearchService $searchService = null) {
        $this->searchService = $searchService ?: new GlobalSearchService();
    }

    /**
     * GET /api/search?q={term}&type={all|societies|persons|vehicles}&society_id={id}
     */
    public function search(): void {
        try {
            $action = $_GET['action'] ?? '';
            if ($action === 'dossier') {
                $this->dossier();
                return;
            }

            $query = trim($_GET['q'] ?? $_GET['query'] ?? $_GET['search'] ?? '');
            $options = [
                'type' => $_GET['type'] ?? 'all',
                'society_id' => $_GET['society_id'] ?? null,
                'limit' => (int)($_GET['limit'] ?? 50)
            ];

            $res = $this->searchService->search($query, $options);
            $this->success($res, 'Search completed successfully');
        } catch (Exception $e) {
            $this->error('Failed to execute search: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/search/dossier?type={person|vehicle|society}&id={id}&user_id={id}&unit_id={id}
     */
    public function dossier(): void {
        try {
            $type = $_GET['type'] ?? 'person';
            $id = (int)($_GET['id'] ?? 0);
            $params = [
                'user_id' => $_GET['user_id'] ?? null,
                'resident_id' => $_GET['resident_id'] ?? null,
                'unit_id' => $_GET['unit_id'] ?? null
            ];

            $dossier = $this->searchService->getEntityDossier($type, $id, $params);
            if (isset($dossier['error'])) {
                $this->error($dossier['error'], 404);
                return;
            }

            $this->success($dossier, 'Dossier retrieved successfully');
        } catch (Exception $e) {
            $this->error('Failed to load entity dossier: ' . $e->getMessage(), 500);
        }
    }
}

