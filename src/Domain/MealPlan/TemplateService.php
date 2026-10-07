<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\MealPlan;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class TemplateService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function createTemplate(string $title, string $category, string $description, float $targetKcal, array $structure): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;

        $stmt = $this->db->prepare("
            INSERT INTO meal_plan_templates (clinic_id, title, category, description, target_energy_kcal, payload_json)
            VALUES (:clinic_id, :title, :category, :description, :target_kcal, :payload_json)
        ");

        $stmt->execute([
            'clinic_id' => $clinicId,
            'title' => trim($title),
            'category' => trim($category),
            'description' => trim($description),
            'target_kcal' => $targetKcal,
            'payload_json' => json_encode($structure, JSON_UNESCAPED_UNICODE),
        ]);

        return [
            'id' => (int)$this->db->lastInsertId(),
            'title' => $title,
            'category' => $category,
            'target_energy_kcal' => $targetKcal,
        ];
    }

    public function listTemplates(): array
    {
        $clinicId = TenantContext::hasClinic() ? TenantContext::getClinicId() : null;
        $stmt = $this->db->prepare("
            SELECT id, clinic_id, title, category, description, target_energy_kcal, created_at
            FROM meal_plan_templates
            WHERE clinic_id IS NULL OR clinic_id = :clinic_id
            ORDER BY clinic_id DESC, title ASC
        ");
        $stmt->execute(['clinic_id' => $clinicId]);
        return $stmt->fetchAll();
    }

    /**
     * Aplica um template diretamente na criação do plano alimentar de um paciente
     */
    public function applyTemplateToPatient(int $templateId, int $patientId, int $professionalId): array
    {
        $clinicId = TenantContext::getClinicId();

        $stmt = $this->db->prepare("
            SELECT * FROM meal_plan_templates
            WHERE id = :id AND (clinic_id IS NULL OR clinic_id = :clinic_id)
        ");
        $stmt->execute(['id' => $templateId, 'clinic_id' => $clinicId]);
        $tmpl = $stmt->fetch();

        if (!$tmpl) {
            throw new \InvalidArgumentException("Template nutricional não encontrado ou inacessível.");
        }

        $structure = json_decode($tmpl['payload_json'], true) ?: [];
        $mealPlanService = new MealPlanService($this->db);

        return $mealPlanService->createPlan(
            $patientId,
            $professionalId,
            $tmpl['title'] . ' (Personalizado)',
            $structure['meals'] ?? []
        );
    }
}
