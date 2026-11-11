<?php

declare(strict_types=1);

namespace ClyoNutri\Application\Controllers;

use ClyoNutri\Core\Database\Connection;

class LandingController
{
    // NI2-449: a versão vem de LegalDocuments (a mesma usada no registro dos aceites).
    public const CURRENT_TERMS_VERSION = \ClyoNutri\Domain\Legal\LegalDocuments::TERMS_VERSION;
    public const CURRENT_TERMS_DATE = \ClyoNutri\Domain\Legal\LegalDocuments::TERMS_DATE;

    public function index(): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);
        $userName = $_SESSION['user_name'] ?? null;

        $pageTitle = 'Clyo Nutri: da consulta ao plano alimentar';
        $metaDescription = 'Prontuário, anamnese, avaliações e plano alimentar com a TACO, em um sistema feito para nutricionistas.';
        $canonicalUrl = \ClyoNutri\Core\Security\ContactInfo::url('/');

        require __DIR__ . '/../../../views/landing/index.php';
    }

    public function plans(): void
    {
        $isLoggedIn = !empty($_SESSION['user_id']);
        $userName = $_SESSION['user_name'] ?? null;

        try {
            $db = Connection::getInstance();
            $stmt = $db->query("SELECT * FROM saas_plans WHERE is_active = 1 ORDER BY price_cents ASC");
            $plans = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable) {
            // Sem a tabela de planos a página abre vazia em vez de dar erro 500.
            $plans = [];
        }

        $pageTitle = 'Planos e Preços — Clyo Nutri';
        $metaDescription = 'Conheça nossos planos mensais e anuais para nutricionistas e clínicas. 14 dias de teste grátis sem cartão e política clara de cancelamento e arrependimento.';
        $canonicalUrl = \ClyoNutri\Core\Security\ContactInfo::url('/planos');

        require __DIR__ . '/../../../views/landing/plans.php';
    }

    public function terms(): void
    {
        $pageTitle = 'Termos de Uso — Clyo Nutri';
        $metaDescription = 'Termos e Condições Gerais de Uso da Plataforma Clyo Nutri.';
        $canonicalUrl = \ClyoNutri\Core\Security\ContactInfo::url('/termos');

        require __DIR__ . '/../../../views/legal/terms.php';
    }

    public function privacy(): void
    {
        $pageTitle = 'Política de Privacidade & Governança LGPD — Clyo Nutri';
        $metaDescription = 'Diretrizes de proteção de dados, privacidade e segurança da informação em conformidade com a LGPD.';
        $canonicalUrl = \ClyoNutri\Core\Security\ContactInfo::url('/privacidade');

        require __DIR__ . '/../../../views/legal/privacy.php';
    }
}
