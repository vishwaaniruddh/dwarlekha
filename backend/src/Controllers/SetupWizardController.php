<?php
namespace App\Controllers;

use App\Services\SetupWizardService;
use App\Config\TenantContext;
use Exception;
use InvalidArgumentException;

class SetupWizardController extends BaseController {
    private SetupWizardService $service;

    public function __construct(?SetupWizardService $service = null) {
        $this->service = $service ?: new SetupWizardService();
    }

    private function getTargetSocietyId(): int {
        $input = $this->getJsonInput();
        if (!empty($input['society_id'])) {
            return (int)$input['society_id'];
        }
        if (!empty($_GET['society_id'])) {
            return (int)$_GET['society_id'];
        }
        $tenantId = TenantContext::getSocietyId();
        if ($tenantId > 0) {
            return $tenantId;
        }
        $curr = \App\Config\RbacGuard::getCurrentUser();
        if (!empty($curr['societyId'])) {
            return (int)$curr['societyId'];
        }
        return 1;
    }

    public function status(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $status = $this->service->getSetupStatus($societyId);
            $this->success($status);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 400);
        }
    }

    public function saveSocietyBank(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $result = $this->service->saveSocietyBankStep($societyId, $input);
            $this->success($result, "Society identity and bank profile updated successfully.");
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function generateTowersUnits(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $result = $this->service->generateTowersAndUnits($societyId, $input);
            $this->success($result, "Towers and flats bulk generated successfully.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function applyCoaPreset(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $preset = $input['preset_type'] ?? ($input['preset'] ?? 'residential');
            $selectedCodes = $input['selected_codes'] ?? ($input['selected_accounts'] ?? []);
            $result = $this->service->applyCoaPreset($societyId, $preset, is_array($selectedCodes) ? $selectedCodes : []);
            $this->success($result, "Chart of Accounts configured successfully.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function saveChargeRules(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $result = $this->service->saveChargeRules($societyId, $input);
            $this->success($result, "Maintenance charge master rules saved successfully.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function saveSmtp(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $result = $this->service->saveSmtpStep($societyId, $input);
            $this->success($result, "SMTP credentials saved successfully.");
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function skipSmtp(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $result = $this->service->skipSmtpStep($societyId);
            $this->success($result, "SMTP setup deferred. You can configure this later.");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function createAdminUser(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $result = $this->service->createAdminUser($societyId, $input);
            $this->success($result, "Society administrator provisioned successfully.");
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    public function quickLaunch(): void {
        try {
            $societyId = $this->getTargetSocietyId();
            $input = $this->getJsonInput();
            $result = $this->service->autoSetupAll($societyId, $input);
            $this->success($result, "Full society setup wizard completed successfully!");
        } catch (Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}
