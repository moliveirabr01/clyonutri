<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;
use ClyoNutri\Core\Security\Csrf;
use ClyoNutri\Domain\PatientPortal\PatientPasswordResetService;

/**
 * NI2-481 — Recuperação de senha do paciente (app web). Páginas públicas, neutras,
 * com CSRF (validado pelo Router nos POST) e resposta não-enumerável.
 */
final class PatientPasswordResetController
{
    private PatientPasswordResetService $service;

    public function __construct(?PatientPasswordResetService $service = null)
    {
        $this->service = $service ?? new PatientPasswordResetService();
    }

    /** GET /paciente/recuperar-senha */
    public function showRequest(): void
    {
        header('Cache-Control: no-store');
        $flashSuccess = $_SESSION['pt_pwreset_success'] ?? null;
        $flashError = $_SESSION['pt_pwreset_error'] ?? null;
        $oldEmail = $_SESSION['pt_pwreset_email'] ?? '';
        unset($_SESSION['pt_pwreset_success'], $_SESSION['pt_pwreset_error'], $_SESSION['pt_pwreset_email']);
        $this->pageRequest($flashSuccess, $flashError, (string)$oldEmail);
    }

    /** POST /paciente/recuperar-senha */
    public function submitRequest(): void
    {
        $email = trim((string)($_POST['email'] ?? ''));
        if ($email === '') {
            $_SESSION['pt_pwreset_error'] = 'Informe o e-mail cadastrado.';
            $this->redirect('/paciente/recuperar-senha');
            return;
        }
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            // Envio adiado: a resposta sai antes do SMTP, para o tempo não revelar se a conta existe.
            $pending = [];
            $this->service->deferEmailDelivery(static function (\Closure $job) use (&$pending): void {
                $pending[] = $job;
            });
            $res = $this->service->requestReset($email, $ip, $ua);
            $this->service->deferEmailDelivery(null);
            $_SESSION['pt_pwreset_success'] = $res['message'];
            if ($pending) {
                $this->redirectAndContinue('/paciente/recuperar-senha');
                foreach ($pending as $job) {
                    $job();
                }
                exit;
            }
            $this->redirect('/paciente/recuperar-senha');
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $_SESSION['pt_pwreset_error'] = $e->getMessage();
            $_SESSION['pt_pwreset_email'] = $email;
            $this->redirect('/paciente/recuperar-senha');
        } catch (\Throwable $e) {
            $_SESSION['pt_pwreset_error'] = 'Ocorreu uma instabilidade. Tente novamente.';
            $this->redirect('/paciente/recuperar-senha');
        }
    }

    /** GET /paciente/recuperar-senha/{token} */
    public function showReset(array $params = []): void
    {
        header('Cache-Control: no-store');
        $token = (string)($params['token'] ?? '');
        if ($this->service->validateToken($token) === null) {
            $this->pageInvalid();
            return;
        }
        $flashError = $_SESSION['pt_pwreset_form_error'] ?? null;
        unset($_SESSION['pt_pwreset_form_error']);
        $this->pageReset($token, $flashError);
    }

    /** POST /paciente/recuperar-senha/{token} */
    public function submitReset(array $params = []): void
    {
        $token = (string)($params['token'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm = (string)($_POST['password_confirmation'] ?? '');
        if ($password !== $confirm) {
            $_SESSION['pt_pwreset_form_error'] = 'As senhas não coincidem.';
            $this->redirect('/paciente/recuperar-senha/' . rawurlencode($token));
            return;
        }
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $res = $this->service->resetPassword($token, $password, $ip, $ua);
            // NI2-481 P1c: aviso de senha alterada (não bloqueia o fluxo se falhar).
            try {
                \ClyoNutri\Domain\PatientPortal\PatientSecurityNotifier::make(Connection::getInstance())
                    ->passwordChanged((int)$res['clinic_id'], (int)$res['patient_id'], $ip, $ua);
            } catch (\Throwable $e) {
                error_log('[ClyoNutri][patient-pwreset] aviso de senha alterada falhou: ' . $e->getMessage());
            }
            $_SESSION['pt_pwreset_success'] = $res['message'];
            $this->redirect('/paciente/recuperar-senha');
        } catch (\InvalidArgumentException $e) {
            $_SESSION['pt_pwreset_form_error'] = $e->getMessage();
            $this->redirect('/paciente/recuperar-senha/' . rawurlencode($token));
        } catch (\Throwable $e) {
            $_SESSION['pt_pwreset_form_error'] = 'Não foi possível concluir. Solicite um novo link.';
            $this->redirect('/paciente/recuperar-senha/' . rawurlencode($token));
        }
    }

    // ---------------------------------------------------------------- páginas

    private function pageRequest(?string $success, ?string $error, string $oldEmail): void
    {
        $csrf = Csrf::token();
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $banner = '';
        if ($success !== null) {
            $banner = '<div style="background:var(--semantic-success-light, #E3F4F2);border-left:4px solid var(--semantic-success, #0A7075);color:var(--semantic-success-text, #0A5F63);padding:12px 16px;border-radius:8px;margin-bottom:16px;">' . $e($success) . '</div>';
        } elseif ($error !== null) {
            $banner = '<div style="background:var(--semantic-error-light, #FEE2E2);border-left:4px solid var(--semantic-error, #B42318);color:var(--semantic-error-strong, #912018);padding:12px 16px;border-radius:8px;margin-bottom:16px;">' . $e($error) . '</div>';
        }
        $body = $banner
            . '<p style="margin:0 0 16px;color:var(--neutral-gray-700, #344054);line-height:1.5;">Informe o e-mail cadastrado no consultório. Se houver uma conta, enviamos um link para criar uma nova senha.</p>'
            . '<form method="POST" action="/paciente/recuperar-senha">'
            . '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">'
            . '<label style="display:block;font-size:0.9rem;color:var(--neutral-gray-600, #475467);margin-bottom:6px;">E-mail</label>'
            . '<input type="email" name="email" required autocomplete="email" value="' . $e($oldEmail) . '" style="width:100%;padding:12px;border:1px solid var(--border-input, #828B9B);border-radius:8px;font-size:1rem;margin-bottom:16px;box-sizing:border-box;">'
            . '<button type="submit" style="width:100%;padding:13px;background:var(--botanical-green-600, #39734A);color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:bold;cursor:pointer;">Enviar link</button>'
            . '</form>'
            . '<p style="margin:20px 0 0;text-align:center;"><a href="/paciente/app" style="color:var(--botanical-copper-600, #B04A1B);text-decoration:none;font-size:0.9rem;">Voltar para o acesso</a></p>';
        $this->render('Recuperar senha · Clyo Nutri', 'Recuperar senha', $body);
    }

    private function pageReset(string $token, ?string $error): void
    {
        $csrf = Csrf::token();
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $banner = $error !== null
            ? '<div style="background:var(--semantic-error-light, #FEE2E2);border-left:4px solid var(--semantic-error, #B42318);color:var(--semantic-error-strong, #912018);padding:12px 16px;border-radius:8px;margin-bottom:16px;">' . $e($error) . '</div>'
            : '';
        $body = $banner
            . '<p style="margin:0 0 16px;color:var(--neutral-gray-700, #344054);line-height:1.5;">Crie sua nova senha (de 8 a 72 caracteres).</p>'
            . '<form method="POST" action="/paciente/recuperar-senha/' . $e(rawurlencode($token)) . '">'
            . '<input type="hidden" name="_csrf_token" value="' . $e($csrf) . '">'
            . '<label style="display:block;font-size:0.9rem;color:var(--neutral-gray-600, #475467);margin-bottom:6px;">Nova senha</label>'
            . '<input type="password" name="password" required minlength="8" maxlength="72" autocomplete="new-password" style="width:100%;padding:12px;border:1px solid var(--border-input, #828B9B);border-radius:8px;font-size:1rem;margin-bottom:16px;box-sizing:border-box;">'
            . '<label style="display:block;font-size:0.9rem;color:var(--neutral-gray-600, #475467);margin-bottom:6px;">Confirme a nova senha</label>'
            . '<input type="password" name="password_confirmation" required minlength="8" maxlength="72" autocomplete="new-password" style="width:100%;padding:12px;border:1px solid var(--border-input, #828B9B);border-radius:8px;font-size:1rem;margin-bottom:16px;box-sizing:border-box;">'
            . '<button type="submit" style="width:100%;padding:13px;background:var(--botanical-green-600, #39734A);color:#fff;border:none;border-radius:8px;font-size:1rem;font-weight:bold;cursor:pointer;">Salvar nova senha</button>'
            . '</form>';
        $this->render('Nova senha · Clyo Nutri', 'Criar nova senha', $body);
    }

    private function pageInvalid(): void
    {
        http_response_code(410);
        $body = '<p style="margin:0 0 16px;color:var(--neutral-gray-700, #344054);line-height:1.5;">Este link de recuperação é inválido, já foi usado ou expirou.</p>'
            . '<p style="margin:0 0 20px;color:var(--neutral-gray-700, #344054);line-height:1.5;">Peça um novo link na página de recuperação.</p>'
            . '<a href="/paciente/recuperar-senha" style="display:inline-block;padding:12px 20px;background:var(--botanical-green-600, #39734A);color:#fff;border-radius:8px;text-decoration:none;font-weight:bold;">Pedir novo link</a>';
        $this->render('Link expirado · Clyo Nutri', 'Link indisponível', $body);
    }

    private function render(string $title, string $heading, string $bodyHtml): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $e($title) . '</title></head>'
            . '<body style="margin:0;background:var(--botanical-cream-50, #FAF9F4);font-family:Inter,Arial,sans-serif;color:var(--botanical-forest-900, #0F3E17);">'
            . '<main style="max-width:420px;margin:8vh auto 0;padding:0 16px;">'
            . '<div style="background:var(--botanical-forest-900, #0F3E17);color:#fff;font-weight:bold;font-size:1.1rem;padding:16px 20px;border-radius:12px 12px 0 0;">Clyo Nutri</div>'
            . '<div style="background:#fff;border:1px solid var(--neutral-gray-200, #E5E7EB);border-top:none;border-radius:0 0 12px 12px;padding:24px 20px;">'
            . '<h1 style="font-size:1.3rem;margin:0 0 16px;color:var(--botanical-forest-900, #0F3E17);">' . $e($heading) . '</h1>'
            . $bodyHtml
            . '</div></main></body></html>';
    }

    private function redirect(string $location): void
    {
        header('Location: ' . $location, true, 302);
        exit;
    }

    private function redirectAndContinue(string $location): void
    {
        header('Location: ' . $location, true, 302);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            return;
        }
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            return;
        }
        header('Connection: close');
        header('Content-Length: 0');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
