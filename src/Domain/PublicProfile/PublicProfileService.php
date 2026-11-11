<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\PublicProfile;

use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use PDO;

class PublicProfileService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function createOrUpdateProfile(int $professionalId, array $data): array
    {
        $clinicId = TenantContext::getClinicId();
        \ClyoNutri\Core\Security\TenantGuard::assertProfessionalBelongsToClinic($professionalId, $clinicId, $this->db);
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', trim($data['slug'] ?? 'nutri-' . $professionalId))));

        $chk = $this->db->prepare("SELECT id FROM public_profiles WHERE professional_id = :prof_id");
        $chk->execute(['prof_id' => $professionalId]);
        $existing = $chk->fetchColumn();

        if ($existing) {
            $stmt = $this->db->prepare("
                UPDATE public_profiles
                SET slug = :slug, headline = :headline, bio = :bio, service_types = :services
                WHERE professional_id = :prof_id
            ");
            $stmt->execute([
                'slug' => $slug,
                'headline' => $data['headline'] ?? null,
                'bio' => $data['bio'] ?? null,
                'services' => json_encode($data['service_types'] ?? [], JSON_UNESCAPED_UNICODE),
                'prof_id' => $professionalId,
            ]);
            $id = (int)$existing;
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO public_profiles (clinic_id, professional_id, slug, headline, bio, service_types)
                VALUES (:clinic_id, :prof_id, :slug, :headline, :bio, :services)
            ");
            $stmt->execute([
                'clinic_id' => $clinicId,
                'prof_id' => $professionalId,
                'slug' => $slug,
                'headline' => $data['headline'] ?? null,
                'bio' => $data['bio'] ?? null,
                'services' => json_encode($data['service_types'] ?? [], JSON_UNESCAPED_UNICODE),
            ]);
            $id = (int)$this->db->lastInsertId();
        }

        return [
            'id' => $id,
            'slug' => $slug,
            'public_url' => \ClyoNutri\Core\Security\ContactInfo::url('/nutricionista/' . rawurlencode((string)$slug)),
        ];
    }

    public function getProfileBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT p.*, prof.crn, prof.crn_region, u.name AS professional_name, c.name AS clinic_name
            FROM public_profiles p
            JOIN professionals prof ON prof.id = p.professional_id
            JOIN users u ON u.id = prof.user_id
            JOIN clinics c ON c.id = p.clinic_id
            WHERE p.slug = :slug AND p.is_published = 1
            LIMIT 1
        ");
        $stmt->execute(['slug' => $slug]);
        $prof = $stmt->fetch();
        return $prof ?: null;
    }
}
