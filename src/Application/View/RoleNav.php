<?php
declare(strict_types=1);

namespace ClyoNutri\Application\View;

use ClyoNutri\Core\Auth\AuthContext;
use ClyoNutri\Core\Security\ClinicalAuthorization;

/**
 * v54.57 (UX-104): tira do menu lateral os links que o perfil atual não pode abrir (mesma regra do servidor,
 * ClinicalAuthorization::allowsWeb). Perfil clínico: menu inteiro, sem custo. Grupo que fica vazio some junto.
 */
final class RoleNav
{
    public static function filter(string $html, ?array $actor = null): string
    {
        if ($actor === null) {
            $user = AuthContext::get();
            if ($user === null) {
                return $html;
            }
            $actor = $user->toArray();
        }
        if (ClinicalAuthorization::isClinical($actor)) {
            return $html;
        }
        $html = (string)preg_replace_callback('~<a\b[^>]*\bhref="(/[^"#?]*)[^"]*"[^>]*>.*?</a>~s', static function (array $m) use ($actor): string {
            return ClinicalAuthorization::allowsWeb($actor, $m[1], 'GET') ? $m[0] : '';
        }, $html);
        // grupo recolhível que ficou só com o título
        $html = (string)preg_replace('~<details\b[^>]*>\s*<summary>[^<]*</summary>\s*(?:<\?php[^>]*\?>\s*)*</details>~s', '', $html);
        return $html;
    }

    /**
     * v54.57 (UX-104): no conteúdo da página, um link para destino proibido ao perfil vira texto (o nome do paciente
     * continua legível na lista da recepção) e um botão para destino proibido some. Só para perfil não clínico.
     */
    public static function unlinkForbidden(string $html, ?array $actor = null): string
    {
        if ($actor === null) {
            $user = AuthContext::get();
            if ($user === null) {
                return $html;
            }
            $actor = $user->toArray();
        }
        if (ClinicalAuthorization::isClinical($actor)) {
            return $html;
        }
        return (string)preg_replace_callback('~<(script|style)\b[^>]*>.*?</\1\s*>(*SKIP)(*F)|<a\b([^>]*)\bhref="(/[^"#?]*)[^"]*"([^>]*)>(.*?)</a>~s', static function (array $m) use ($actor): string {
            if (ClinicalAuthorization::allowsWeb($actor, $m[3], 'GET')) {
                return $m[0];
            }
            $attrs = $m[2] . ' ' . $m[4];
            if (preg_match('~\bclass="[^"]*\bbtn\b~', $attrs)) {
                return '';
            }
            return '<span class="role-unlinked">' . $m[5] . '</span>';
        }, $html);
    }
}
