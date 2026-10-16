<?php

declare(strict_types=1);

namespace ClyoNutri\Core\Auth;

final class AuthUser
{
    public function __construct(
        private int $id,
        private string $uuid,
        private string $name,
        private string $email,
        private string $userRole,
        private int $authVersion,
        private int $clinicId,
        private string $memberRole,
        private string $clinicName
    ) {}

    public function getUserId(): int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserRole(): string
    {
        return $this->userRole;
    }

    public function getAuthVersion(): int
    {
        return $this->authVersion;
    }

    public function getClinicId(): int
    {
        return $this->clinicId;
    }

    public function getMemberRole(): string
    {
        return $this->memberRole;
    }

    public function getClinicName(): string
    {
        return $this->clinicName;
    }

    /**
     * Suporte global = papel de PLATAFORMA em users.role ('admin' = administrador da plataforma).
     * clinic_members.role (memberRole) nunca entra aqui. Ver RoleModel / docs/delivery/MODELO_PAPEIS.md.
     */
    public function isSupport(): bool
    {
        return \ClyoNutri\Core\Security\RoleModel::isPlatformSupport($this->userRole);
    }

    public function isClinical(): bool
    {
        return in_array($this->userRole, ['nutritionist', 'admin', 'owner'], true)
            && in_array($this->memberRole, ['owner', 'admin', 'nutritionist'], true);
    }

    public function isReceptionist(): bool
    {
        return $this->userRole === 'receptionist' && $this->memberRole === 'receptionist';
    }

    public function isPlatformAdmin(): bool
    {
        return \ClyoNutri\Core\Security\RoleModel::isPlatformConsole($this->userRole);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'user_name' => $this->name,
            'email' => $this->email,
            'user_email' => $this->email,
            'user_role' => $this->userRole,
            'role' => $this->userRole,
            'auth_version' => $this->authVersion,
            'clinic_id' => $this->clinicId,
            'member_role' => $this->memberRole,
            'clinic_name' => $this->clinicName,
            'is_support' => $this->isSupport(),
            'is_platform_admin' => $this->isPlatformAdmin(),
            'is_clinical' => $this->isClinical(),
            'is_receptionist' => $this->isReceptionist(),
        ];
    }
}
