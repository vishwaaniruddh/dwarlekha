<?php
namespace App\Services;

use App\Config\Database;
use App\Utils\Crypto;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;
use InvalidArgumentException;
use RuntimeException;
use PDO;

class MailService {
    private PDO $db;

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? Database::getConnection();
    }

    /**
     * Retrieve SMTP configuration for a given society.
     */
    public function getSmtpConfig(int $societyId, bool $maskPassword = true): ?array {
        $stmt = $this->db->prepare("
            SELECT id, society_id, sender_name, sender_email, reply_to_email,
                   smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password,
                   is_active, last_tested_at, last_test_status, last_test_error,
                   created_at, updated_at
            FROM smtp_configs
            WHERE society_id = ? AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([$societyId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $decryptedPassword = Crypto::decrypt($row['smtp_password']) ?? '';
        $isPasswordSet = !empty($decryptedPassword);

        if ($maskPassword) {
            $row['smtp_password'] = $isPasswordSet ? '••••••••' : '';
            $row['is_password_set'] = $isPasswordSet;
        } else {
            $row['smtp_password'] = $decryptedPassword;
            $row['is_password_set'] = $isPasswordSet;
        }

        return $row;
    }

    /**
     * Save or update SMTP configuration for a society within a transaction (Rule #1).
     */
    public function saveSmtpConfig(int $societyId, array $data): array {
        $host = trim($data['smtp_host'] ?? '');
        $port = (int)($data['smtp_port'] ?? 587);
        $username = trim($data['smtp_username'] ?? '');
        $senderEmail = trim($data['sender_email'] ?? ($data['smtp_username'] ?? ''));
        $senderName = trim($data['sender_name'] ?? 'Society Management Office');
        $replyTo = !empty($data['reply_to_email']) ? trim($data['reply_to_email']) : null;
        $encryption = strtolower(trim($data['smtp_encryption'] ?? 'tls'));
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;
        $passwordInput = $data['smtp_password'] ?? '';

        if (empty($host)) {
            throw new InvalidArgumentException("SMTP Host is required.");
        }
        if ($port <= 0 || $port > 65535) {
            throw new InvalidArgumentException("Invalid SMTP Port. Standard ports are 587 (TLS), 465 (SSL), or 25.");
        }
        if (empty($username)) {
            throw new InvalidArgumentException("SMTP Username / Email is required.");
        }
        if (empty($senderEmail) || !filter_var($senderEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException("A valid Sender Email address is required.");
        }
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $manageTx = !$this->db->inTransaction();
        if ($manageTx) {
            $this->db->beginTransaction();
        }

        try {
            // Check for existing record
            $checkStmt = $this->db->prepare("SELECT id, smtp_password FROM smtp_configs WHERE society_id = ? AND is_deleted = 0 LIMIT 1");
            $checkStmt->execute([$societyId]);
            $existing = $checkStmt->fetch();

            $encryptedPassword = '';
            if (!empty($passwordInput) && $passwordInput !== '••••••••') {
                $encryptedPassword = Crypto::encrypt($passwordInput);
            } elseif ($existing) {
                // Retain existing password
                $encryptedPassword = $existing['smtp_password'];
            } else {
                throw new InvalidArgumentException("SMTP Password is required for initial configuration.");
            }

            if ($existing) {
                $updateStmt = $this->db->prepare("
                    UPDATE smtp_configs SET
                        sender_name = ?,
                        sender_email = ?,
                        reply_to_email = ?,
                        smtp_host = ?,
                        smtp_port = ?,
                        smtp_encryption = ?,
                        smtp_username = ?,
                        smtp_password = ?,
                        is_active = ?,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $senderName,
                    $senderEmail,
                    $replyTo,
                    $host,
                    $port,
                    $encryption,
                    $username,
                    $encryptedPassword,
                    $isActive,
                    $existing['id']
                ]);
            } else {
                $insertStmt = $this->db->prepare("
                    INSERT INTO smtp_configs (
                        society_id, sender_name, sender_email, reply_to_email,
                        smtp_host, smtp_port, smtp_encryption, smtp_username, smtp_password,
                        is_active, last_test_status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
                ");
                $insertStmt->execute([
                    $societyId,
                    $senderName,
                    $senderEmail,
                    $replyTo,
                    $host,
                    $port,
                    $encryption,
                    $username,
                    $encryptedPassword,
                    $isActive
                ]);
            }

            if ($manageTx) {
                $this->db->commit();
            }

            return $this->getSmtpConfig($societyId, true) ?? [];
        } catch (\Throwable $e) {
            if ($manageTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Test SMTP connection and authentication handshake.
     * Can optionally test draft parameters or existing saved credentials.
     */
    public function testConnection(int $societyId, ?array $draftConfig = null, ?string $testRecipient = null): array {
        $config = null;
        if (!empty($draftConfig['smtp_host'])) {
            // Draft configuration provided
            $host = trim($draftConfig['smtp_host']);
            $port = (int)($draftConfig['smtp_port'] ?? 587);
            $encryption = strtolower(trim($draftConfig['smtp_encryption'] ?? 'tls'));
            $username = trim($draftConfig['smtp_username'] ?? '');
            $password = $draftConfig['smtp_password'] ?? '';
            $senderName = trim($draftConfig['sender_name'] ?? 'Society Management Office');
            $senderEmail = trim($draftConfig['sender_email'] ?? $username);
            $replyTo = !empty($draftConfig['reply_to_email']) ? trim($draftConfig['reply_to_email']) : null;

            // If masked password passed in draft, fetch existing saved password
            if ($password === '••••••••' || empty($password)) {
                $saved = $this->getSmtpConfig($societyId, false);
                $password = $saved['smtp_password'] ?? '';
            }

            $config = [
                'smtp_host' => $host,
                'smtp_port' => $port,
                'smtp_encryption' => $encryption,
                'smtp_username' => $username,
                'smtp_password' => $password,
                'sender_name' => $senderName,
                'sender_email' => $senderEmail,
                'reply_to_email' => $replyTo
            ];
        } else {
            $config = $this->getSmtpConfig($societyId, false);
            if (!$config) {
                throw new RuntimeException("No SMTP configuration found for this society. Please input server details.");
            }
        }

        if (empty($config['smtp_password'])) {
            throw new InvalidArgumentException("Password cannot be empty for SMTP connection test.");
        }

        $startTime = microtime(true);
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->Port = (int)$config['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->Timeout = 10;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            if ($config['smtp_encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['smtp_encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            // 1. Verify Handshake
            if (!$mail->smtpConnect()) {
                throw new RuntimeException("SMTP Handshake failed. Please verify Host, Port, and Encryption.");
            }

            // 2. If test recipient is provided, send test email
            $emailSent = false;
            if (!empty($testRecipient) && filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
                $mail->setFrom($config['sender_email'], $config['sender_name']);
                $mail->addAddress($testRecipient);
                if (!empty($config['reply_to_email'])) {
                    $mail->addReplyTo($config['reply_to_email']);
                }

                $mail->isHTML(true);
                $mail->Subject = "SMTP Connection Test · " . ($config['sender_name'] ?? 'Society ERP');
                $mail->Body = $this->renderTestEmailTemplate($config, $testRecipient);
                $mail->AltBody = "SMTP Connection Test Successful. Host: {$config['smtp_host']}:{$config['smtp_port']}, Encryption: {$config['smtp_encryption']}.";

                $mail->send();
                $emailSent = true;

                // Log test dispatch
                $this->logEmail($societyId, $testRecipient, 'Admin User', $mail->Subject, $mail->Body, 'sent', null);
            }

            $mail->smtpClose();
            $latencyMs = round((microtime(true) - $startTime) * 1000, 1);

            // Update status on saved record if exists
            $this->updateTestStatus($societyId, 'success', null);

            return [
                'success' => true,
                'latency_ms' => $latencyMs,
                'email_sent' => $emailSent,
                'message' => $emailSent
                    ? "Connection verified and test email dispatched to {$testRecipient} ({$latencyMs}ms)!"
                    : "SMTP connection and authentication verified successfully in {$latencyMs}ms!"
            ];
        } catch (\Throwable $e) {
            $errorMsg = $e->getMessage();
            $this->updateTestStatus($societyId, 'failed', $errorMsg);

            if (!empty($testRecipient)) {
                $this->logEmail($societyId, $testRecipient, 'Admin User', 'SMTP Test', null, 'failed', $errorMsg);
            }

            return [
                'success' => false,
                'error' => $errorMsg,
                'message' => "Connection test failed: {$errorMsg}"
            ];
        }
    }

    /**
     * Send an outgoing email using the society's active SMTP settings.
     */
    public function send(
        int $societyId,
        string $toEmail,
        string $subject,
        string $htmlBody,
        ?string $toName = null,
        array $attachments = []
    ): array {
        $config = $this->getSmtpConfig($societyId, false);

        if (!$config || empty($config['is_active'])) {
            $err = "SMTP configuration is inactive or not configured for society #{$societyId}.";
            $this->logEmail($societyId, $toEmail, $toName, $subject, $htmlBody, 'failed', $err);
            return ['success' => false, 'error' => $err];
        }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->Port = (int)$config['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->Timeout = 12;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            if ($config['smtp_encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($config['smtp_encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($config['sender_email'], $config['sender_name']);
            $mail->addAddress($toEmail, $toName ?? '');

            if (!empty($config['reply_to_email'])) {
                $mail->addReplyTo($config['reply_to_email']);
            }

            foreach ($attachments as $att) {
                if (is_array($att) && !empty($att['path'])) {
                    $mail->addAttachment($att['path'], $att['name'] ?? '');
                } elseif (is_string($att) && file_exists($att)) {
                    $mail->addAttachment($att);
                }
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);

            $mail->send();

            $logId = $this->logEmail($societyId, $toEmail, $toName, $subject, $htmlBody, 'sent', null);

            return [
                'success' => true,
                'log_id' => $logId,
                'message_id' => $mail->getLastMessageID()
            ];
        } catch (\Throwable $e) {
            $errMsg = $e->getMessage();
            $this->logEmail($societyId, $toEmail, $toName, $subject, $htmlBody, 'failed', $errMsg);
            return [
                'success' => false,
                'error' => $errMsg
            ];
        }
    }

    /**
     * Get paginated email logs for a society (Rule #3 & Rule #4).
     */
    public function getEmailLogs(int $societyId, int $page = 1, int $limit = 15): array {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $offset = ($page - 1) * $limit;

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM email_logs WHERE society_id = ? AND is_deleted = 0");
        $countStmt->execute([$societyId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT id, society_id, recipient_email, recipient_name, subject,
                   status, error_message, sent_at, created_at
            FROM email_logs
            WHERE society_id = ? AND is_deleted = 0
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $societyId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll();
        $totalPages = $total > 0 ? (int)ceil($total / $limit) : 1;

        return [
            'data' => $rows,
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

    /**
     * Soft delete an email log (Rule #4).
     */
    public function deleteEmailLog(int $logId, int $societyId): bool {
        $stmt = $this->db->prepare("UPDATE email_logs SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND society_id = ?");
        return $stmt->execute([$logId, $societyId]);
    }

    private function updateTestStatus(int $societyId, string $status, ?string $error): void {
        try {
            $stmt = $this->db->prepare("
                UPDATE smtp_configs SET
                    last_tested_at = NOW(),
                    last_test_status = ?,
                    last_test_error = ?
                WHERE society_id = ? AND is_deleted = 0
            ");
            $stmt->execute([$status, $error, $societyId]);
        } catch (\Throwable $t) {
            // Best effort logging
        }
    }

    private function logEmail(
        int $societyId,
        string $toEmail,
        ?string $toName,
        string $subject,
        ?string $body,
        string $status,
        ?string $error
    ): int {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO email_logs (
                    society_id, recipient_email, recipient_name, subject,
                    body_html, status, error_message, sent_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $sentAt = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
            $stmt->execute([
                $societyId,
                $toEmail,
                $toName,
                $subject,
                $body,
                $status,
                $error,
                $sentAt
            ]);
            return (int)$this->db->lastInsertId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function renderTestEmailTemplate(array $config, string $recipient): string {
        $date = date('d M Y, h:i A');
        $host = htmlspecialchars($config['smtp_host']);
        $port = htmlspecialchars($config['smtp_port']);
        $enc = strtoupper(htmlspecialchars($config['smtp_encryption']));
        $sender = htmlspecialchars($config['sender_name'] . " <{$config['sender_email']}>");

        return "
        <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 580px; margin: 0 auto; background: #FFFFFF; border: 1px solid #E4E4E7; border-radius: 12px; overflow: hidden; color: #18181B;\">
          <div style=\"padding: 24px 28px; background: #18181B; color: #FAFAFA;\">
            <h2 style=\"margin: 0; font-size: 18px; font-weight: 700; letter-spacing: -0.01em;\">SMTP Connection Verified</h2>
            <div style=\"font-size: 12px; opacity: 0.8; margin-top: 4px;\">DwarLekha / SAR Multi-Tenant Society ERP Platform</div>
          </div>
          <div style=\"padding: 28px;\">
            <p style=\"font-size: 14px; line-height: 1.6; margin: 0 0 16px 0;\">
              Hello Administrator,<br><br>
              This is a test notification confirming that your dedicated housing society email gateway is active and configured correctly.
            </p>
            <div style=\"background: #F4F4F5; border: 1px solid #E4E4E7; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px;\">
              <div style=\"font-size: 11px; text-transform: uppercase; font-weight: 700; color: #71717A; letter-spacing: 0.04em; margin-bottom: 8px;\">Configuration Telemetry</div>
              <div style=\"font-size: 12.5px; line-height: 1.8;\">
                <div><strong>SMTP Host:</strong> {$host}:{$port} ({$enc})</div>
                <div><strong>Sender Identity:</strong> {$sender}</div>
                <div><strong>Verified At:</strong> {$date}</div>
              </div>
            </div>
            <p style=\"font-size: 12px; color: #71717A; margin: 0;\">
              Automated monthly maintenance bills, payment receipts, visitor security alerts, and broadcast notices will now be sent seamlessly from this mailbox.
            </p>
          </div>
          <div style=\"padding: 14px 28px; background: #FAFAFA; border-top: 1px solid #E4E4E7; font-size: 11px; color: #A1A1AA; text-align: center;\">
            Society Management Platform · Dedicated SMTP Gateway Service
          </div>
        </div>
        ";
    }
}