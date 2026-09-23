<?php
namespace App\Controllers;

use App\Services\SocietyContactService;
use App\Config\RbacGuard;
use InvalidArgumentException;
use Exception;

class SocietyContactController extends BaseController {
    private SocietyContactService $contactService;

    public function __construct(?SocietyContactService $contactService = null) {
        $this->contactService = $contactService ?: new SocietyContactService();
    }

    public function index(): void {
        try {
            $params = $_GET;
            $res = $this->contactService->getPaginatedContacts($params);
            $this->success($res, 'Society directory retrieved successfully');
        } catch (Exception $e) {
            $this->error('Failed to load directory: ' . $e->getMessage(), 500);
        }
    }

    public function listAll(): void {
        try {
            $params = $_GET;
            $res = $this->contactService->getContacts($params);
            $this->success($res, 'All contacts retrieved');
        } catch (Exception $e) {
            $this->error('Failed to load contacts: ' . $e->getMessage(), 500);
        }
    }

    public function show(string $id): void {
        try {
            $contact = $this->contactService->getContactById((int)$id);
            if (!$contact) {
                $this->error('Contact not found', 404);
                return;
            }
            $this->success($contact, 'Contact details retrieved');
        } catch (Exception $e) {
            $this->error('Failed to fetch contact: ' . $e->getMessage(), 500);
        }
    }

    public function presets(): void {
        try {
            $presets = $this->contactService->getPresets();
            $this->success($presets, 'Directory presets retrieved');
        } catch (Exception $e) {
            $this->error('Failed to fetch presets: ' . $e->getMessage(), 500);
        }
    }

    public function create(): void {
        try {
            $data = $this->getJsonInput();
            $contact = $this->contactService->createContact($data);
            $this->success($contact, 'Directory contact created successfully', 201);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (Exception $e) {
            $this->error('Failed to create directory entry: ' . $e->getMessage(), 500);
        }
    }

    public function update(string $id): void {
        try {
            $data = $this->getJsonInput();
            $contact = $this->contactService->updateContact((int)$id, $data);
            $this->success($contact, 'Directory contact updated successfully');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 400);
        } catch (Exception $e) {
            $this->error('Failed to update directory contact: ' . $e->getMessage(), 500);
        }
    }

    public function delete(string $id): void {
        try {
            $this->contactService->deleteContact((int)$id);
            $this->success(null, 'Directory contact soft-deleted successfully');
        } catch (Exception $e) {
            $this->error('Failed to delete directory contact: ' . $e->getMessage(), 500);
        }
    }
}
