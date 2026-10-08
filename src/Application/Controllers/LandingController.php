<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

class LandingController
{
    public function index(): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);
        $userName = $_SESSION['user_name'] ?? null;

        $pageTitle = 'ClyoNutri II — O Sistema Operacional do Nutricionista';
        $metaDescription = 'Plataforma completa para nutricionistas clínicos: gestão de pacientes, anamnese, base TACO, prescrição estruturada de planos alimentares e aplicativo conectado.';
        $canonicalUrl = 'https://clyonutri.com.br/';

        require __DIR__ . '/../../../views/landing/index.php';
    }

    public function terms(): void
    {
        $pageTitle = 'Termos de Uso — ClyoNutri II';
        $metaDescription = 'Termos e Condições Gerais de Uso da Plataforma ClyoNutri II.';
        $canonicalUrl = 'https://clyonutri.com.br/termos';

        require __DIR__ . '/../../../views/legal/terms.php';
    }

    public function privacy(): void
    {
        $pageTitle = 'Política de Privacidade & Governança LGPD — ClyoNutri II';
        $metaDescription = 'Diretrizes de proteção de dados, privacidade e segurança da informação em conformidade com a LGPD.';
        $canonicalUrl = 'https://clyonutri.com.br/privacidade';

        require __DIR__ . '/../../../views/legal/privacy.php';
    }
}
