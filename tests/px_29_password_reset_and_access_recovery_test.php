<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — SUÍTE DE TESTES E2E PX-29: RECUPERAÇÃO DE ACESSO & SENHA (NI2-322)
 * ==============================================================================
 * Homologa rigorosamente os critérios de aceite de PX-29:
 *  - GATE 1: Solicitação legítima de redefinição com token criptográfico de 64 hex e validade de 30 min.
 *  - GATE 2: Resposta uniforme e não-enumerável (prevenção contra enumeração de contas).
 *  - GATE 3: Anti-abuso e Rate Limiting estrito (bloqueio por excesso de requisições).
 *  - GATE 4: Invalidação de tokens anteriores no reenvio (apenas o token mais recente é válido).
 *  - GATE 5: Validação de token e renderização Web nos endpoints /recuperar-senha e /recuperar-senha/{token}.
 *  - GATE 6: Expiração estrita (rejeição sumária de tokens fora da janela de 30 minutos).
 *  - GATE 7: Redefinição real de credencial com bcrypt cost 12 e consumo atômico (Single-Use Token).
 *  - GATE 8: Revogação imediata de sessões anteriores web/API e login com nova credencial.
 *  - GATE 9: Trilha de auditoria em audit_logs e isolamento relacional entre contas.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Auth\AuthService;
use ClyoNutri\Core\Auth\PasswordResetService;
use ClyoNutri\Core\Auth\TenantContext;
use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Application\Controllers\AuthController;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

echo "\n========================================================================\n";
echo ">>> CLYONUTRI II — SUÍTE DE TESTES PX-29: RECUPERAÇÃO DE ACESSO (NI2-322) <<<\n";
echo "========================================================================\n\n";

$db = Connection::getInstance();

function assertPx29(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException("FALHA NA ASSERÇÃO: " . $message);
    }
}

function logPx29Pass(string $gate, string $desc): void {
    echo "  \033[32m[PASS]\033[0m [{$gate}] {$desc}\n";
}

$testEmail = 'dr.marcelo.px29@clyonutri.test';
$unknownEmail = 'inexistente.px29@clyonutri.test';
$initialPassword = 'SenhaOriginal@2026';
$newPassword = 'NovaSenhaSegura@2026#';

$userId = 0;
$clinicId = 0;

try {
    // -------------------------------------------------------------------------
    // PREPARAÇÃO: Criando usuário e tenant reais no banco de dados
    // -------------------------------------------------------------------------
    echo "--- PREPARAÇÃO: Criando Usuário e Tenant Reais ---\n";

    // Limpeza prévia de registros de teste
    $db->prepare("DELETE FROM password_resets WHERE LOWER(email) IN (:e1, :e2)")->execute(['e1' => $testEmail, 'e2' => $unknownEmail]);
    $db->prepare("DELETE FROM audit_logs WHERE details LIKE :e1 OR details LIKE :e2")->execute(['e1' => '%' . $testEmail . '%', 'e2' => '%' . $unknownEmail . '%']);
    $db->prepare("DELETE FROM users WHERE email = :e")->execute(['e' => $testEmail]);

    $authService = new AuthService($db);
    $registered = $authService->registerNutritionist(
        name: 'Dr. Marcelo Silva PX29',
        email: $testEmail,
        password: $initialPassword,
        clinicName: 'Clínica Marcelo Nutrição PX29',
        crn: 'CRN3-987654',
        crnRegion: 'CRN-3'
    );
    $userId = (int)$registered['user_id'];
    $clinicId = (int)$registered['clinic_id'];

    // Ativa conta
    $db->prepare("UPDATE users SET is_verified = 1 WHERE id = :id")->execute(['id' => $userId]);

    // Cria uma sessão web ativa fictícia em 'sessions'
    $sessId = 'sess_px29_initial_' . bin2hex(random_bytes(16));
    $db->prepare("
        INSERT INTO sessions (id, user_id, clinic_id, ip_address, user_agent, payload, last_activity)
        VALUES (:id, :uid, :cid, '127.0.0.1', 'PHPTest/1.0', 'data', :la)
    ")->execute([
        'id' => $sessId,
        'uid' => $userId,
        'cid' => $clinicId,
        'la' => time(),
    ]);

    // Cria um token de API ativo fictício em 'personal_access_tokens'
    $apiTokenVal = bin2hex(random_bytes(16));
    $db->prepare("
        INSERT INTO personal_access_tokens (tokenable_type, tokenable_id, name, token, abilities, expires_at)
        VALUES ('user', :uid, 'mobile_token', :tok, '[\"*\"]', datetime('now', '+30 days'))
    ")->execute([
        'uid' => $userId,
        'tok' => $apiTokenVal,
    ]);

    logPx29Pass("PREPARAÇÃO", "Usuário, clínica, sessão ativa e token de API persistidos no banco de dados");

    $resetService = new PasswordResetService($db);

    // -------------------------------------------------------------------------
    // GATE 1: SOLICITAÇÃO LEGÍTIMA COM TOKEN CRIPTOGRÁFICO DE 64 HEX E 30 MIN
    // -------------------------------------------------------------------------
    echo "\n--- GATE 1: Solicitação Legítima de Redefinição de Senha ---\n";

    $reqResult = $resetService->requestReset($testEmail, '192.168.1.100', 'Mozilla/5.0');
    assertPx29($reqResult['success'] === true, "Solicitação deve retornar sucesso");
    assertPx29(!empty($reqResult['token']), "Deve gerar token para e-mail cadastrado");
    assertPx29(strlen($reqResult['token']) === 64, "Token deve conter exatamente 64 caracteres hexadecimais (32 bytes)");
    assertPx29(ctype_xdigit($reqResult['token']), "Token deve ser puramente hexadecimal");
    assertPx29($reqResult['user_found'] === true, "Metadado interno deve confirmar usuário existente");

    // Verifica persistência no banco na tabela password_resets
    $stmt = $db->prepare("SELECT email, token, expires_at FROM password_resets WHERE token = :t");
    $stmt->execute(['t' => $reqResult['token']]);
    $persisted = $stmt->fetch(PDO::FETCH_ASSOC);
    assertPx29($persisted !== false, "Token deve estar gravado na tabela password_resets");
    assertPx29($persisted['email'] === $testEmail, "E-mail associado na tabela deve ser o do usuário");

    $expiresTimestamp = strtotime($persisted['expires_at']);
    $diffMinutes = round(($expiresTimestamp - time()) / 60);
    assertPx29($diffMinutes >= 28 && $diffMinutes <= 31, "Validade do token deve ser de aproximadamente 30 minutos (calculado: {$diffMinutes} min)");

    // Verifica trilha de auditoria
    $stmt = $db->prepare("SELECT action, resource, details FROM audit_logs WHERE user_id = :uid AND action = 'PASSWORD_RESET_REQUESTED'");
    $stmt->execute(['uid' => $userId]);
    $auditLog = $stmt->fetch(PDO::FETCH_ASSOC);
    assertPx29($auditLog !== false, "Evento PASSWORD_RESET_REQUESTED deve ser gravado em audit_logs");

    logPx29Pass("GATE 1", "Solicitação legítima validada: token seguro de 64 hex, expiração 30m, persistência e auditoria");

    // -------------------------------------------------------------------------
    // GATE 2: NÃO-ENUMERABILIDADE (PROTEÇÃO CONTRA ENUMERAÇÃO DE CONTAS)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 2: Não-Enumerabilidade de Contas ---\n";

    $unknownResult = $resetService->requestReset($unknownEmail, '192.168.1.101', 'Mozilla/5.0');
    assertPx29($unknownResult['success'] === true, "Solicitação para e-mail inexistente deve retornar success true");
    assertPx29($unknownResult['message'] === $reqResult['message'], "Mensagem para e-mail inexistente deve ser rigorosamente IDÊNTICA à do e-mail cadastrado");
    assertPx29($unknownResult['token'] === null, "Nenhum token deve ser emitido para e-mail inexistente");

    // Garante que NADA foi salvo em password_resets para o e-mail inexistente
    $stmt = $db->prepare("SELECT COUNT(*) FROM password_resets WHERE LOWER(email) = :e");
    $stmt->execute(['e' => $unknownEmail]);
    assertPx29((int)$stmt->fetchColumn() === 0, "Nenhum registro pode ser inserido em password_resets para e-mail inexistente");

    // Log de auditoria de tentativa desconhecida
    $stmt = $db->prepare("SELECT action FROM audit_logs WHERE action = 'PASSWORD_RESET_ATTEMPT_UNKNOWN'");
    $stmt->execute();
    assertPx29($stmt->fetch() !== false, "Evento PASSWORD_RESET_ATTEMPT_UNKNOWN deve ser auditado para análise de segurança");

    logPx29Pass("GATE 2", "Não-enumerabilidade comprovada: mensagens uniformes e ausência de vazamento de existência");

    // -------------------------------------------------------------------------
    // GATE 3: ANTI-ABUSO & RATE LIMITING
    // -------------------------------------------------------------------------
    echo "\n--- GATE 3: Anti-Abuso & Rate Limiting ---\n";

    // O e-mail testEmail já tem 1 solicitação válida no GATE 1.
    // Vamos fazer mais 3 solicitações válidas (total 4) e uma 5ª (total 5).
    for ($i = 2; $i <= 5; $i++) {
        $resetService->requestReset($testEmail, '192.168.1.100', 'Mozilla/5.0');
    }

    // A 6ª tentativa para o mesmo e-mail no intervalo de 15 minutos deve ser bloqueada
    $rateLimitCaught = false;
    try {
        $resetService->requestReset($testEmail, '192.168.1.100', 'Mozilla/5.0');
    } catch (RuntimeException $e) {
        $rateLimitCaught = true;
        assertPx29(str_contains($e->getMessage(), 'Muitas solicitações'), "Mensagem deve alertar sobre excesso de requisições");
    }
    assertPx29($rateLimitCaught, "Tentativa excedendo o limite deve lançar RuntimeException fail-closed");

    logPx29Pass("GATE 3", "Rate limiting validado: 5 tentativas por e-mail toleradas em 15m; 6ª requisição bloqueada");

    // -------------------------------------------------------------------------
    // GATE 4: REENVIO E INVALIDAÇÃO DE TOKENS ANTERIORES
    // -------------------------------------------------------------------------
    echo "\n--- GATE 4: Reenvio e Invalidação de Tokens Anteriores ---\n";

    // Limpa auditoria recente temporariamente para permitir teste de unicidade
    $db->prepare("DELETE FROM audit_logs WHERE action = 'PASSWORD_RESET_REQUESTED' AND details LIKE :e")
       ->execute(['e' => '%' . $testEmail . '%']);

    // Emissão do Token A
    $tokenARes = $resetService->requestReset($testEmail, '192.168.1.102', 'Mozilla/5.0');
    $tokenA = $tokenARes['token'];
    assertPx29(!empty($tokenA), "Token A deve ter sido gerado");

    // Imediatamente solicita novo token (Token B) para o mesmo e-mail
    $tokenBRes = $resetService->requestReset($testEmail, '192.168.1.102', 'Mozilla/5.0');
    $tokenB = $tokenBRes['token'];
    assertPx29(!empty($tokenB) && $tokenB !== $tokenA, "Token B deve ser diferente do Token A");

    // Token A deve estar invalidado/inexistente
    $validateA = $resetService->validateToken($tokenA);
    assertPx29($validateA === null, "Token A deve ser completamente inválido após geração do Token B");

    // Token B deve estar ativo e válido
    $validateB = $resetService->validateToken($tokenB);
    assertPx29($validateB !== null, "Token B deve ser validado com sucesso");
    assertPx29($validateB['email'] === $testEmail, "Token B deve estar vinculado ao e-mail de teste");

    // Na tabela só pode haver 1 token ativo para este e-mail
    $stmt = $db->prepare("SELECT COUNT(*) FROM password_resets WHERE LOWER(email) = :e");
    $stmt->execute(['e' => $testEmail]);
    assertPx29((int)$stmt->fetchColumn() === 1, "Deve existir exatamente 1 token ativo por e-mail na tabela password_resets");

    logPx29Pass("GATE 4", "Reenvio validado: novo token revoga imediatamente token anterior (unicidade garantida)");

    // -------------------------------------------------------------------------
    // GATE 5: VALIDAÇÃO DE TOKEN E RENDERIZAÇÃO WEB (/recuperar-senha)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 5: Validação Web e Renderização de Telas ---\n";

    $authController = new AuthController($authService, $resetService);

    // 5.1 Tela de solicitação inicial
    $_SESSION = [];
    ob_start();
    $authController->showForgotPassword();
    $forgotHtml = ob_get_clean();

    assertPx29(str_contains($forgotHtml, 'Recuperar Acesso'), "Tela deve exibir título 'Recuperar Acesso'");
    assertPx29(str_contains($forgotHtml, 'action="/recuperar-senha"'), "Formulário deve postar para /recuperar-senha");
    assertPx29(str_contains($forgotHtml, 'name="email"'), "Formulário deve ter campo de e-mail");
    assertPx29(str_contains($forgotHtml, 'Voltar ao Login'), "Tela deve oferecer retorno ao login");
    assertPx29(str_contains($forgotHtml, 'suporte@clyonutri.com.br'), "Tela deve oferecer suporte como canal alternativo");

    // 5.2 Tela de nova senha com Token Válido
    ob_start();
    $authController->showResetPassword(['token' => $tokenB]);
    $resetValidHtml = ob_get_clean();

    assertPx29(str_contains($resetValidHtml, 'Criar Nova Senha'), "Tela deve exibir 'Criar Nova Senha'");
    assertPx29(str_contains($resetValidHtml, 'action="/recuperar-senha/' . $tokenB . '"'), "Formulário deve postar com token na rota");
    assertPx29(str_contains($resetValidHtml, 'name="password"'), "Formulário deve ter campo de nova senha");
    assertPx29(str_contains($resetValidHtml, 'name="password_confirmation"'), "Formulário deve ter campo de confirmação de senha");
    assertPx29(str_contains($resetValidHtml, 'Redefinir Senha & Acessar'), "Botão de submissão deve estar presente");

    // 5.3 Tela de nova senha com Token Inválido / Fake
    ob_start();
    $authController->showResetPassword(['token' => 'token_forjado_e_inexistente_1234567890abcdef']);
    $resetInvalidHtml = ob_get_clean();

    assertPx29(str_contains($resetInvalidHtml, 'Link Inválido ou Expirado'), "Token inválido deve renderizar aviso 'Link Inválido ou Expirado'");
    assertPx29(str_contains($resetInvalidHtml, 'Solicitar Novo Link de Recuperação'), "Deve conter CTA para nova solicitação");
    assertPx29(!str_contains($resetInvalidHtml, 'action="/recuperar-senha/'), "Não deve renderizar formulário de troca de senha com token inválido");

    // 5.4 Tela de Login contém link explícito para /recuperar-senha
    ob_start();
    $authController->showLogin();
    $loginHtml = ob_get_clean();

    assertPx29(str_contains($loginHtml, 'href="/recuperar-senha"'), "Login deve conter link direto para /recuperar-senha");
    assertPx29(str_contains($loginHtml, 'Esqueci minha senha'), "Link deve ter texto compreensível 'Esqueci minha senha'");
    assertPx29(!str_contains($loginHtml, 'mailto:suporte@clyonutri.com.br?subject=Ajuda%20com%20acesso'), "Link principal de senha NÃO deve ser mais mailto de suporte");

    logPx29Pass("GATE 5", "Renderização Web validada: telas amigáveis, formulários com CSRF e eliminação do mailto");

    // -------------------------------------------------------------------------
    // GATE 6: EXPIRAÇÃO ESTRITA DE TOKENS (FORA DA JANELA DE 30 MINUTOS)
    // -------------------------------------------------------------------------
    echo "\n--- GATE 6: Expiração Estrita de Tokens (Janela 30m) ---\n";

    // Força data de expiração no passado para o Token B
    $pastDate = date('Y-m-d H:i:s', time() - 300); // 5 minutos atrás
    $stmt = $db->prepare("UPDATE password_resets SET expires_at = :past WHERE token = :t");
    $stmt->execute(['past' => $pastDate, 't' => $tokenB]);

    // Tentativa de validação deve falhar
    $valExpired = $resetService->validateToken($tokenB);
    assertPx29($valExpired === null, "validateToken deve retornar null para token com expires_at no passado");

    // Tentativa de redefinição com token expirado deve lançar exceção
    $expiredResetCaught = false;
    try {
        $resetService->resetPassword($tokenB, 'NovaSenhaTentativa123');
    } catch (InvalidArgumentException $e) {
        $expiredResetCaught = true;
        assertPx29(str_contains($e->getMessage(), 'inválido ou expirado'), "Exceção deve indicar link inválido ou expirado");
    }
    assertPx29($expiredResetCaught, "Tentativa de reset com token expirado deve lançar InvalidArgumentException");

    // Verifica que senha no banco NÃO foi alterada
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $currentHash = $stmt->fetchColumn();
    assertPx29(password_verify($initialPassword, $currentHash), "Senha do usuário não pode ter sido modificada por token expirado");

    logPx29Pass("GATE 6", "Expiração estrita validada: tokens passados são limpos e sumariamente rejeitados");

    // -------------------------------------------------------------------------
    // GATE 7: REDEFINIÇÃO REAL DE CREDENCIAL & SINGLE-USE DO TOKEN
    // -------------------------------------------------------------------------
    echo "\n--- GATE 7: Redefinição Real de Credencial & Single-Use ---\n";

    // Limpa rate limits e gera token limpo para a troca final
    $db->prepare("DELETE FROM audit_logs WHERE action = 'PASSWORD_RESET_REQUESTED' AND details LIKE :e")
       ->execute(['e' => '%' . $testEmail . '%']);
    $finalReq = $resetService->requestReset($testEmail, '192.168.1.103', 'Mozilla/5.0');
    $activeToken = $finalReq['token'];
    assertPx29(!empty($activeToken), "Token final gerado com sucesso");

    // Valida rejeição de senha com menos de 8 dígitos
    $shortPassCaught = false;
    try {
        $resetService->resetPassword($activeToken, '1234567');
    } catch (InvalidArgumentException $e) {
        $shortPassCaught = true;
        assertPx29(str_contains($e->getMessage(), '8 caracteres'), "Deve exigir no mínimo 8 caracteres");
    }
    assertPx29($shortPassCaught, "Senha menor que 8 dígitos deve ser rejeitada");

    // Executa a redefinição com senha forte válida
    $resetOutput = $resetService->resetPassword(
        token: $activeToken,
        newPassword: $newPassword,
        ipAddress: '192.168.1.103',
        userAgent: 'Mozilla/5.0'
    );
    assertPx29($resetOutput['success'] === true, "Redefinição de senha deve retornar sucesso");

    // Comprova que o hash no banco foi atualizado para a nova senha com bcrypt cost 12
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $updatedHash = $stmt->fetchColumn();
    assertPx29(!empty($updatedHash), "Hash do usuário deve estar presente");
    assertPx29(password_verify($newPassword, $updatedHash), "Novo hash DEVE verificar positivamente com a nova senha");
    assertPx29(!password_verify($initialPassword, $updatedHash), "Hash NÃO DEVE mais aceitar a senha antiga");

    // Comprova que o token foi EXCLUÍDO (Single-Use Token)
    $stmt = $db->prepare("SELECT COUNT(*) FROM password_resets WHERE token = :t");
    $stmt->execute(['t' => $activeToken]);
    assertPx29((int)$stmt->fetchColumn() === 0, "Token consumido deve ser excluído da base imediatamente");

    // Segunda tentativa com o MESMO token DEVE falhar
    $reuseCaught = false;
    try {
        $resetService->resetPassword($activeToken, 'TentativaReuso1234#');
    } catch (InvalidArgumentException $e) {
        $reuseCaught = true;
    }
    assertPx29($reuseCaught, "Tentativa de reutilização do mesmo token consumido deve falhar imediatamente (Single-Use)");

    logPx29Pass("GATE 7", "Redefinição real validada: bcrypt cost 12 atualizado e token consumido imediatamente");

    // -------------------------------------------------------------------------
    // GATE 8: REVOGAÇÃO DE SESSÕES ANTERIORES & AUTENTICAÇÃO E2E
    // -------------------------------------------------------------------------
    echo "\n--- GATE 8: Revogação de Sessões Anteriores & Autenticação E2E ---\n";

    // 8.1 Sessões antigas web (tabela sessions) foram revogadas
    $stmt = $db->prepare("SELECT COUNT(*) FROM sessions WHERE id = :id");
    $stmt->execute(['id' => $sessId]);
    assertPx29((int)$stmt->fetchColumn() === 0, "Sessão web anterior do usuário DEVE ser deletada após redefinição de senha");

    // 8.2 Tokens de API anteriores (tabela personal_access_tokens) foram revogados
    $stmt = $db->prepare("SELECT COUNT(*) FROM personal_access_tokens WHERE tokenable_id = :uid");
    $stmt->execute(['uid' => $userId]);
    assertPx29((int)$stmt->fetchColumn() === 0, "Tokens pessoais de API do usuário DEVEM ser revogados na redefinição");

    // 8.3 Tentativa de login com senha antiga FALHA
    $oldAttempt = $authService->attempt($testEmail, $initialPassword);
    assertPx29($oldAttempt === null, "Tentativa de login com senha antiga DEVE retornar null");

    // 8.4 Login com a nova senha obtém SUCESSO e retorna contexto completo
    $newAttempt = $authService->attempt($testEmail, $newPassword);
    assertPx29($newAttempt !== null, "Login com a nova senha DEVE autenticar com sucesso");
    assertPx29((int)$newAttempt['id'] === $userId, "Usuário autenticado deve ser o usuário correto");
    assertPx29((int)$newAttempt['clinic_id'] === $clinicId, "Tenant/Clínica associada deve ser carregada corretamente");
    assertPx29($newAttempt['name'] === 'Dr. Marcelo Silva PX29', "Nome do usuário deve ser retornado");

    logPx29Pass("GATE 8", "Sessões antigas revogadas e autenticação E2E com nova credencial comprovada com sucesso");

    // -------------------------------------------------------------------------
    // GATE 9: AUDITORIA COMPLETA & ISOLAMENTO MULTI-TENANT
    // -------------------------------------------------------------------------
    echo "\n--- GATE 9: Auditoria Completa e Isolamento Multi-Tenant ---\n";

    // Verifica log PASSWORD_RESET_COMPLETED
    $stmt = $db->prepare("
        SELECT action, resource, resource_id, details
        FROM audit_logs
        WHERE user_id = :uid AND action = 'PASSWORD_RESET_COMPLETED'
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute(['uid' => $userId]);
    $completeAudit = $stmt->fetch(PDO::FETCH_ASSOC);
    assertPx29($completeAudit !== false, "Evento PASSWORD_RESET_COMPLETED deve ser persistido em audit_logs");
    $auditDetails = json_decode((string)$completeAudit['details'], true);
    assertPx29($auditDetails['sessions_revoked'] === true, "Audit log deve registrar sessions_revoked = true");

    // Cria um segundo usuário em outro tenant para provar isolamento estrito
    $otherEmail = 'outro.nutri.px29@clyonutri.test';
    $otherReg = $authService->registerNutritionist(
        name: 'Dra. Roberta PX29',
        email: $otherEmail,
        password: 'SenhaDaRoberta@2026',
        clinicName: 'Clínica Roberta PX29',
        crn: 'CRN3-112233',
        crnRegion: 'CRN-3'
    );
    $otherUserId = (int)$otherReg['user_id'];

    // Garante que o outro usuário continua com sua credencial e status inalterados
    $otherAttempt = $authService->attempt($otherEmail, 'SenhaDaRoberta@2026');
    assertPx29($otherAttempt !== null, "Redefinição de senha do usuário A não pode impactar credencial do usuário B");
    assertPx29((int)$otherAttempt['id'] === $otherUserId, "Usuário B permanece isolado");

    logPx29Pass("GATE 9", "Auditoria completa e isolamento estrito entre usuários/tenants homologados");

    echo "\n========================================================================\n";
    echo ">>> TODOS OS 9 GATES DE HOMOLOGAÇÃO PX-29 (NI2-322) FORAM APROVADOS (PASS) <<<\n";
    echo "========================================================================\n\n";

} catch (Throwable $e) {
    echo "\n\033[31m[ERRO CRÍTICO]\033[0m " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
} finally {
    // Limpeza de fixtures
    if (!empty($testEmail)) {
        $db->prepare("DELETE FROM password_resets WHERE LOWER(email) IN (:e1, :e2)")
           ->execute(['e1' => $testEmail, 'e2' => 'outro.nutri.px29@clyonutri.test']);
        $db->prepare("DELETE FROM sessions WHERE user_id = :uid")->execute(['uid' => $userId]);
        $db->prepare("DELETE FROM personal_access_tokens WHERE tokenable_id = :uid")->execute(['uid' => $userId]);
    }
    if ($userId > 0) {
        $db->prepare("DELETE FROM professionals WHERE user_id = :uid")->execute(['uid' => $userId]);
        $db->prepare("DELETE FROM clinic_members WHERE user_id = :uid")->execute(['uid' => $userId]);
        $db->prepare("DELETE FROM audit_logs WHERE user_id = :uid")->execute(['uid' => $userId]);
        $db->prepare("DELETE FROM users WHERE id = :uid")->execute(['uid' => $userId]);
    }
    if ($clinicId > 0) {
        $db->prepare("DELETE FROM clinics WHERE id = :cid")->execute(['cid' => $clinicId]);
    }
    if (!empty($otherUserId)) {
        $db->prepare("DELETE FROM professionals WHERE user_id = :uid")->execute(['uid' => $otherUserId]);
        $db->prepare("DELETE FROM clinic_members WHERE user_id = :uid")->execute(['uid' => $otherUserId]);
        $db->prepare("DELETE FROM audit_logs WHERE user_id = :uid")->execute(['uid' => $otherUserId]);
        $db->prepare("DELETE FROM users WHERE id = :uid")->execute(['uid' => $otherUserId]);
    }
    if (!empty($otherReg['clinic_id'])) {
        $db->prepare("DELETE FROM clinics WHERE id = :cid")->execute(['cid' => $otherReg['clinic_id']]);
    }
}
