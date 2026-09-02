<?php
namespace App\Controllers;

use App\Services\GeoService;

class GeoController {
    private GeoService $geoService;

    public function __construct() {
        $this->geoService = new GeoService();
    }

    public function getCountries(): void {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $this->geoService->getCountries()
        ]);
    }

    public function getZones(): void {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $this->geoService->getZones()
        ]);
    }

    public function getStates(): void {
        header('Content-Type: application/json');
        $countryId = isset($_GET['country_id']) ? (int)$_GET['country_id'] : null;
        $zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;
        echo json_encode([
            'success' => true,
            'data' => $this->geoService->getStates($countryId, $zoneId)
        ]);
    }

    public function getCities(): void {
        header('Content-Type: application/json');
        $stateId = isset($_GET['state_id']) ? (int)$_GET['state_id'] : null;
        $countryId = isset($_GET['country_id']) ? (int)$_GET['country_id'] : null;
        $zoneId = isset($_GET['zone_id']) ? (int)$_GET['zone_id'] : null;
        echo json_encode([
            'success' => true,
            'data' => $this->geoService->getCities($stateId, $countryId, $zoneId)
        ]);
    }

    public function getGeoLookup(): void {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $this->geoService->getGeoLookup()
        ]);
    }
}