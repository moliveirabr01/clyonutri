# Modelo de papéis do ClyoNutri

Definição confirmada por Maicon em 14/10/2026, a partir da auditoria de segurança de 14/10/2026.

## Dois eixos independentes

| Onde | Campo | O que significa |
|---|---|---|
| Conta (plataforma) | `users.role` | Papel da pessoa **na plataforma ClyoNutri** ou tipo de conta |
| Vínculo com a clínica | `clinic_members.role` | Papel da pessoa **dentro de uma clínica específica** |

### `users.role`

- `superadmin`, `admin`, `admin_operacional`, `operator`, `support_agent`, `finance_admin`, `security_auditor`: **equipe da plataforma**.
  - `admin` = **administrador da plataforma**. Tem acesso global ao service desk (`/suporte/...`), aos anexos de chamados de qualquer clínica e ao console `/admin`.
- `nutritionist`, `receptionist`: contas de clínica.

### `clinic_members.role`

- `owner`, `admin`, `nutritionist`, `receptionist`, `operator`, `support_agent`.
- `admin` aqui é **gestor daquela clínica** (pode, por exemplo, gerir a assinatura e o nome do consultório). **Nunca** concede acesso de plataforma nem suporte global.

## Regras no código

- Toda decisão de suporte/plataforma usa `users.role` por meio de `src/Core/Security/RoleModel.php` (`isPlatformSupport`, `isPlatformConsole`). `clinic_members.role` nunca é usado para isso.
  - Usado em `AuthUser::isSupport()/isPlatformAdmin()`, `ClinicalAuthorization::assertWebAccess()` (`/admin`, `/suporte/`), `HelpdeskAttachmentService` e na identificação de operadores no `HelpdeskController`.
- Papéis de plataforma em `users.role` só são concedidos pela plataforma:
  - `AuthService::updateUserRole()` exige que quem concede seja `superadmin`/`admin` (para `admin` e demais papéis administrativos) ou equipe de suporte (`operator`/`support_agent`); sem autor identificado, só pela linha de comando (`bin/create_superadmin.php`).
  - Cadastro público cria sempre `users.role = 'nutritionist'`.
  - Não existe rota HTTP que permita a um membro de clínica alterar `users.role`.
- Gestão dentro da clínica (`SubscriptionService::canManageSubscription`, `ProfessionalProfile`) continua usando `clinic_members.role` (`owner`/`admin`), restrita à própria clínica.

## Teste

`tests/auditoria_2026_10_14_security_test.php`, seção "Papel admin".
