<?php
namespace App\Controllers;

use App\Services\HelpdeskService;
use Exception;

class HelpdeskController extends BaseController {
    private HelpdeskService $helpdeskService;

    public function __construct(?HelpdeskService $helpdeskService = null) {
        $this->helpdeskService = $helpdeskService ?: new HelpdeskService();
    }

    public function index(): void {
        $filters = [];
        if (!empty($_GET['unit_id'])) $filters['unit_id'] = $_GET['unit_id'];
        if (!empty($_GET['flat_number'])) $filters['flat_number'] = $_GET['flat_number'];
        if (!empty($_GET['flatNumber'])) $filters['flat_number'] = $_GET['flatNumber'];
        if (!empty($_GET['unit_code'])) $filters['flat_number'] = $_GET['unit_code'];

        $tickets = $this->helpdeskService->getTickets($filters);
        $this->success($tickets);
    }

    public function create(): void {
        $input = $this->getJsonInput();
        try {
            $ticket = $this->helpdeskService->raiseTicket($input);
            $this->success($ticket, 'Ticket raised successfully', 201);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function updateStatus(string $ticketCode): void {
        $input = $this->getJsonInput();
        $status = $input['status'] ?? 'Resolved';
        try {
            $this->helpdeskService->updateTicketStatus($ticketCode, $status, $input);
            $this->success(null, "Ticket {$ticketCode} status updated to {$status}");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }
}
