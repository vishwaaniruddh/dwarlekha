<?php
namespace App\Services;

use App\Models\Complaint;
use App\Config\Database;
use InvalidArgumentException;
use Exception;

class HelpdeskService {
    private Complaint $complaintModel;

    public function __construct(?Complaint $complaintModel = null) {
        $this->complaintModel = $complaintModel ?: new Complaint();
    }

    public function getTickets(array $filters = []): array {
        return $this->complaintModel->getAll(null, $filters);
    }

    public function raiseTicket(array $input): array {
        if (empty($input['title'])) {
            throw new InvalidArgumentException("Ticket Title is required.");
        }

        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $code = $input['id'] ?? ('TKT-' . rand(500, 999));

            $data = [
                'ticket_code' => $code,
                'title' => trim($input['title']),
                'category' => $input['category'] ?? 'Plumbing',
                'flat_number' => $input['flat_number'] ?? ($input['flatNumber'] ?? 'B3-1601'),
                'reported_by' => $input['reported_by'] ?? ($input['reportedBy'] ?? 'Resident'),
                'priority' => $input['priority'] ?? 'High',
                'description' => $input['description'] ?? 'Reported via resident portal.',
                'attachments' => $input['attachments'] ?? null
            ];

            $this->complaintModel->create($data);

            if ($manageTx) {
                $db->commit();
            }

            return array_merge($input, [
                'id' => $code,
                'status' => 'Assigned',
                'createdAt' => 'Just now'
            ]);
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public function updateTicketStatus(string $ticketCode, string $status, array $extra = []): bool {
        $db = Database::getConnection();
        $manageTx = !$db->inTransaction();
        if ($manageTx) {
            $db->beginTransaction();
        }

        try {
            $result = $this->complaintModel->updateStatus($ticketCode, $status, $extra);
            if ($manageTx) {
                $db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($manageTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }
}
