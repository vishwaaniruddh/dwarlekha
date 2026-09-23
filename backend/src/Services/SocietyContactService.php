<?php
namespace App\Services;

use App\Models\SocietyContact;
use App\Config\TenantContext;
use App\Config\RbacGuard;
use InvalidArgumentException;
use Exception;

class SocietyContactService {
    private SocietyContact $contactModel;

    public function __construct(?SocietyContact $contactModel = null) {
        $this->contactModel = $contactModel ?: new SocietyContact();
    }

    public function getContacts(array $filters = []): array {
        $societyId = isset($filters['society_id']) ? (int)$filters['society_id'] : TenantContext::getSocietyId();
        return $this->contactModel->getAll($societyId, $filters);
    }

    public function getPaginatedContacts(array $params = []): array {
        $societyId = isset($params['society_id']) ? (int)$params['society_id'] : TenantContext::getSocietyId();
        $page = max(1, (int)($params['page'] ?? 1));
        $limit = max(1, min(100, (int)($params['limit'] ?? 15)));
        $offset = ($page - 1) * $limit;

        $filters = array_merge($params, [
            'limit' => $limit,
            'offset' => $offset
        ]);

        $items = $this->contactModel->getAll($societyId, $filters);
        $total = $this->contactModel->countTotal($societyId, $filters);
        $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;

        return [
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => $totalPages,
                'has_next' => $page < $totalPages,
                'has_prev' => $page > 1
            ]
        ];
    }

    public function getContactById(int $id, ?int $societyId = null): ?array {
        $societyId = $societyId !== null ? $societyId : TenantContext::getSocietyId();
        return $this->contactModel->findById($id, $societyId);
    }

    public function createContact(array $data): array {
        if (empty($data['name'])) {
            throw new InvalidArgumentException("Contact or person name is required.");
        }
        if (empty($data['phone'])) {
            throw new InvalidArgumentException("Primary contact phone number is required.");
        }

        $societyId = !empty($data['society_id']) ? (int)$data['society_id'] : TenantContext::getSocietyId();
        if ($societyId <= 0) {
            $societyId = 1; // Default to active tenant
        }
        $data['society_id'] = $societyId;

        $id = $this->contactModel->create($data);
        $contact = $this->contactModel->findById($id, $societyId);
        return $contact ?: ['id' => $id];
    }

    public function updateContact(int $id, array $data): array {
        $societyId = !empty($data['society_id']) ? (int)$data['society_id'] : TenantContext::getSocietyId();
        $existing = $this->contactModel->findById($id, $societyId);
        if (!$existing) {
            throw new InvalidArgumentException("Contact record not found.");
        }

        if (isset($data['name']) && empty(trim($data['name']))) {
            throw new InvalidArgumentException("Contact name cannot be empty.");
        }
        if (isset($data['phone']) && empty(trim($data['phone']))) {
            throw new InvalidArgumentException("Contact phone cannot be empty.");
        }

        $this->contactModel->update($id, $data, $societyId);
        $contact = $this->contactModel->findById($id, $societyId);
        return $contact ?: ['id' => $id];
    }

    public function deleteContact(int $id, ?int $societyId = null): bool {
        $societyId = $societyId !== null ? $societyId : TenantContext::getSocietyId();
        return $this->contactModel->softDelete($id, $societyId);
    }

    public function getPresets(): array {
        return [
            'contact_types' => [
                ['id' => 'management', 'label' => '🏛️ Management & Office Bearers', 'description' => 'Key decision makers, committee members, builder liaison'],
                ['id' => 'emergency', 'label' => '🚨 Emergency SOS & Gate Desk', 'description' => '24/7 Security, Fire EMT, Police, Lift SOS, Medical Trauma'],
                ['id' => 'service_vendor', 'label' => '🔧 Utility Vendors & Technicians', 'description' => 'Plumbers, Electricians, Carpenters, AC repair, Pest control'],
                ['id' => 'staff', 'label' => '👷 Society On-Site Staff', 'description' => 'Facility supervisor, Housekeeping lead, Guard commander']
            ],
            'management_positions' => [
                'President / Chairman',
                'Secretary',
                'Joint Secretary',
                'Treasurer / Accountant',
                'Managing Committee Member',
                'Estate Facility Manager',
                'Builder / Developer Representative',
                'Legal & Compliance Advisor',
                'Technical & AMC In-charge'
            ],
            'emergency_categories' => [
                'Gate & Perimeter Security',
                'Lift / Elevator SOS',
                'Law Enforcement (Police)',
                'Fire & Emergency Rescue',
                'Medical & Trauma Ambulance',
                'Electricity Board Emergency',
                'Water Supply Breakdown SOS',
                'Disaster Management Helpline'
            ],
            'vendor_categories' => [
                'Plumbing',
                'Electrical',
                'Carpentry',
                'Appliances & AC Servicing',
                'Pest Control',
                'Water Tanker Supplier',
                'Painter & Waterproofing',
                'Mason & Civil Repair',
                'Internet & Broadband Support',
                'Housekeeping & Cleaning',
                'Gardening & Landscaping',
                'Security & Guard Agency'
            ]
        ];
    }
}
