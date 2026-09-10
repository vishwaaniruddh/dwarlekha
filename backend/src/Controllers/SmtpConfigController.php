<?php
namespace App\Controllers;

use App\Services\MailService;
use App\Config\TenantContext;
use App\Config\RbacGuard;
use Exception;
use InvalidArgumentException;

class SmtpConfigController extends BaseController {
    private MailService $service;

    public function __construct(?MailService $service = null) {
        $this->service = $service ?: new MailService();
    }

    private function resolveSocietyId(): int {
        $input = $this->getJsonInput();
        if (isset($input['society_id']) && is_numeric($input['society_id'])) {
            return (int)$input['society_id'];
        }
        if (isset($_GET['society_id']) && is_numeric($_GET['society_id'])) {
            return (int)$_GET['society_id'];
        }
        $tenantId = TenantContext::getSocietyId();
        if ($tenantId > 0) {
            return $tenantId;
        }
        $curr = RbacGuard::getCurrentUser();
        if (!empty($curr['societyId'])) {
            return (int)$curr['societyId'];
        }
        $isParent = !empty($curr['isParentUser']) || !empty($curr['is_parent_user']) || 
                    ($curr['roleCode'] ?? '') === 'sar_platform_admin' || 
                    ($curr['roleCode'] ?? '') === 'sar_support';
        return $isParent ? 0 : 1;
    }

    /**
     * GET /smtp or GET /smtp/config
     */
    public function getConfig(): void {
        try {
            $societyId = $this->resolveSocietyId();
            $config = $this->service->getSmtpConfig($societyId, true);
            
            if (!$config) {
                // Return default template so UI is instantly populated
                $isGlobal = ($societyId === 0);
                $config = [
                    'society_id' => $societyId,
                    'sender_name' => $isGlobal ? 'SAR Master Platform (DwarLekha)' : 'Society Management Office',
                    'sender_email' => $isGlobal ? 'notifications@sarsspl.com' : '',
                    'reply_to_email' => $isGlobal ? 'support@sarsspl.com' : '',
                    'smtp_host' => 'smtp.gmail.com',
                    'smtp_port' => 587,
                    'smtp_encryption' => 'tls',
                    'smtp_username' => '',
                    'smtp_password' => '',
                    'is_password_set' => false,
                    'is_active' => 1,
                    'last_tested_at' => null,
                    'last_test_status' => null,
                    'last_test_error' => null
                ];
            }

            $this->success($config);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    /**
     * POST /smtp or POST /smtp/config
     */
    public function saveConfig(): void {
        try {
            $societyId = $this->resolveSocietyId();
            $input = $this->getJsonInput();
            $updated = $this->service->saveSmtpConfig($societyId, $input);
            $this->success($updated, "SMTP mailer credentials updated successfully.");
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * POST /smtp/test
     * Test handshake and authentication with SMTP server.
     */
    public function testConnection(): void {
        try {
            $societyId = $this->resolveSocietyId();
            $input = $this->getJsonInput();
            
            // Allow testing draft inputs or existing saved config
            $draft = !empty($input['smtp_host']) ? $input : null;
            $recipient = !empty($input['recipient']) ? trim($input['recipient']) : null;

            $result = $this->service->testConnection($societyId, $draft, $recipient);
            
            if (!empty($result['success'])) {
                $this->success($result, $result['message'] ?? 'SMTP handshake successful!');
            } else {
                $this->json([
                    'success' => false,
                    'error' => $result['message'] ?? 'SMTP connection failed',
                    'diagnostics' => $result
                ], 400);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * POST /smtp/send-test
     * Dispatch live test email.
     */
    public function sendTestEmail(): void {
        try {
            $societyId = $this->resolveSocietyId();
            $input = $this->getJsonInput();
            $recipient = !empty($input['recipient']) ? trim($input['recipient']) : '';

            if (empty($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $curr = RbacGuard::getCurrentUser();
                $recipient = !empty($curr['email']) ? $curr['email'] : '';
            }

            if (empty($recipient)) {
                throw new InvalidArgumentException("A valid recipient email address is required.");
            }

            $draft = !empty($input['smtp_host']) ? $input : null;
            $result = $this->service->testConnection($societyId, $draft, $recipient);

            if (!empty($result['success'])) {
                $this->success($result, "Test email dispatched successfully to " . htmlspecialchars($recipient));
            } else {
                $this->json([
                    'success' => false,
                    'error' => $result['message'] ?? 'Failed to send test email',
                    'diagnostics' => $result
                ], 400);
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * GET /smtp/logs
     * Paginated email audit log.
     */
    public function logs(): void {
        try {
            $societyId = $this->resolveSocietyId();
            $page = max(1, (int)($_GET['page'] ?? 1));
            $limit = max(1, min(100, (int)($_GET['limit'] ?? 15)));

            $result = $this->service->getEmailLogs($societyId, $page, $limit);
            $this->json([
                'success' => true,
                'data' => $result['data'],
                'pagination' => $result['pagination']
            ]);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * DELETE /smtp/logs/{id}
     * Soft delete email log record (Rule #4).
     */
    public function deleteLog(int $id): void {
        try {
            $societyId = $this->resolveSocietyId();
            $ok = $this->service->deleteEmailLog($id, $societyId);
            if ($ok) {
                $this->success(null, "Audit record archived successfully.");
            } else {
                $this->error("Record not found or already archived.", 404);
            }
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
