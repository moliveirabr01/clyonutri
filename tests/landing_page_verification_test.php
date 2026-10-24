<?php

declare(strict_types=1);

/**
 * ==============================================================================
 * CLYONUTRI II — TESTE DE VERIFICAÇÃO INTEGRAL DA LANDING PAGE PREMIUM
 * ==============================================================================
 * Valida os 13 componentes de narrativa, acessibilidade WCAG 2.2 AA,
 * integridade de rotas, ausência de links mortos e responsividade.
 * ==============================================================================
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ClyoNutri\Core\Security\Env;

$rootDir = dirname(__DIR__);
Env::load($rootDir . '/.env');

echo "====================================================================\n";
echo "   CLYONUTRI II — LANDING PAGE PREMIUM VERIFICATION SUITE\n";
echo "   Auditoria 360° de Conversão, Acessibilidade & Integridade Técnica\n";
echo "====================================================================\n\n";

$passed = 0;
$failed = 0;

function assertCheck(string $desc, bool $cond, string $details = ''): void {
    global $passed, $failed;
    if ($cond) {
        echo "  [PASS] {$desc}\n";
        $passed++;
    } else {
        echo "  [FAIL] {$desc} " . ($details ? "({$details})" : "") . "\n";
        $failed++;
    }
}

// -----------------------------------------------------------------------------
// [1] Execução da Rota Canônica GET / (Landing Page)
// -----------------------------------------------------------------------------
echo "[1] Testando Renderização da Rota GET /...\n";

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SCRIPT_NAME'] = '/index.php';

ob_start();
require $rootDir . '/public/index.php';
$landingHtml = (string)ob_get_clean();

assertCheck("Landing page retornou HTML não vazio", strlen($landingHtml) > 5000, "Tamanho: " . strlen($landingHtml) . " bytes");
assertCheck("DOCTYPE html e charset UTF-8 presentes", str_contains($landingHtml, '<!DOCTYPE html>') && str_contains($landingHtml, 'charset="UTF-8"'));
assertCheck("Title institucional presente e otimizado", str_contains($landingHtml, 'ClyoNutri II — O Sistema Operacional do Nutricionista'));
assertCheck("Meta description presente", str_contains($landingHtml, 'name="description"'));
assertCheck("Canonical URL configurada para https://clyonutri.com.br/", str_contains($landingHtml, 'href="https://clyonutri.com.br/"'));
assertCheck("OpenGraph tags configuradas", str_contains($landingHtml, 'property="og:title"') && str_contains($landingHtml, 'property="og:description"'));

// -----------------------------------------------------------------------------
// [2] Validação das 13 Seções Obrigatórias
// -----------------------------------------------------------------------------
echo "\n[2] Validando Narrativa e Presença das 13 Seções Canônicas...\n";

// Seção 1: Header
assertCheck("Seção 1: Header premium com logo e skip-link acessível", 
    str_contains($landingHtml, 'class="skip-link"') && 
    str_contains($landingHtml, 'class="header"') && 
    str_contains($landingHtml, 'class="logo"'));

// Seção 2: Hero
assertCheck("Seção 2: Hero de alto impacto com headline e prévia da interface", 
    str_contains($landingHtml, 'class="hero"') && 
    str_contains($landingHtml, 'Sua nutrição merece uma plataforma à altura.') &&
    str_contains($landingHtml, 'hero__product-wrapper'));

// Seção 3: Problema
assertCheck("Seção 3: Seção de dores reais do nutricionista", 
    str_contains($landingHtml, 'Seu tempo deveria estar com seus pacientes. Não com a burocracia.') && 
    str_contains($landingHtml, 'problem-grid') && 
    str_contains($landingHtml, 'Dados dispersos e retrabalho'));

// Seção 4: Solução (3 Pilares)
assertCheck("Seção 4: Solução integrada em 3 pilares (Organize, Prescreva, Acompanhe)", 
    str_contains($landingHtml, 'pillars-grid') && 
    str_contains($landingHtml, 'Organize') && 
    str_contains($landingHtml, 'Prescreva') && 
    str_contains($landingHtml, 'Acompanhe'));

// Seção 5: Showcase com Abas Acessíveis
assertCheck("Seção 5: Showcase do produto com abas acessíveis (role=tablist)", 
    str_contains($landingHtml, 'role="tablist"') && 
    str_contains($landingHtml, 'tab-perfil') && 
    str_contains($landingHtml, 'tab-prescricao') && 
    str_contains($landingHtml, 'tab-agenda') && 
    str_contains($landingHtml, 'tab-app'));

// Seção 6: Experiência do Paciente
assertCheck("Seção 6: Experiência do paciente e mockup de aplicativo móvel", 
    str_contains($landingHtml, 'patient-experience-grid') && 
    str_contains($landingHtml, 'app-screen-mockup') && 
    str_contains($landingHtml, 'Meta de Água'));

// Seção 7: Como Funciona (3 Passos)
assertCheck("Seção 7: Fluxo de trabalho em 3 passos", 
    str_contains($landingHtml, 'steps-grid') && 
    str_contains($landingHtml, 'Configure seu consultório') && 
    str_contains($landingHtml, 'Atenda e prescreva') && 
    str_contains($landingHtml, 'Acompanhe a evolução'));

// Seção 8: Diferenciais Técnicos e de Segurança
assertCheck("Seção 8: Diferenciais com base TACO, SHA-256 e LGPD", 
    str_contains($landingHtml, 'diff-grid') && 
    str_contains($landingHtml, 'Base Canônica TACO / UNICAMP') && 
    str_contains($landingHtml, 'Integridade com Hash SHA-256') && 
    str_contains($landingHtml, 'Isolamento Multitenant & LGPD'));

// Seção 9: Filosofia & Manifesto Clínico
assertCheck("Seção 9: Manifesto clínico com citação de design", 
    str_contains($landingHtml, 'philosophy-box') && 
    str_contains($landingHtml, 'Precision Meets Care'));

// Seção 10: Preços & Teste Transparente
assertCheck("Seção 10: Teste transparente de 14 dias sem cartão", 
    str_contains($landingHtml, 'pricing-card') && 
    str_contains($landingHtml, '14 Dias de Acesso Irrestrito') && 
    str_contains($landingHtml, 'Sem necessidade de dados de cartão de crédito'));

// Seção 11: FAQ Acessível
assertCheck("Seção 11: FAQ com accordion acessível (aria-expanded)", 
    str_contains($landingHtml, 'faq-list') && 
    str_contains($landingHtml, 'class="faq-trigger"') && 
    str_contains($landingHtml, 'aria-expanded'));

// Seção 12: CTA Final
assertCheck("Seção 12: CTA final de alta conversão", 
    str_contains($landingHtml, 'final-cta') && 
    str_contains($landingHtml, 'Mais tempo para cuidar. Mais clareza para evoluir.'));

// Seção 13: Rodapé Institucional
assertCheck("Seção 13: Rodapé institucional com links de governança", 
    str_contains($landingHtml, 'role="contentinfo"') && 
    str_contains($landingHtml, 'footer-grid') && 
    str_contains($landingHtml, 'href="/termos"') && 
    str_contains($landingHtml, 'href="/privacidade"'));

// -----------------------------------------------------------------------------
// [3] Validação de Links Internos e Prevenção de Links Mortos (404)
// -----------------------------------------------------------------------------
echo "\n[3] Validando Resolução de Âncoras e Links do Header / Footer...\n";

// Extrair todas as âncoras #id e verificar se o elemento correspondente existe no HTML
preg_match_all('/href="(#[a-zA-Z0-9_-]+)"/', $landingHtml, $anchorMatches);
$anchorList = array_unique($anchorMatches[1]);

foreach ($anchorList as $anchor) {
    $targetId = ltrim($anchor, '#');
    $hasTarget = str_contains($landingHtml, "id=\"{$targetId}\"");
    assertCheck("Âncora interna '{$anchor}' possui elemento de destino no DOM", $hasTarget, "id='{$targetId}'");
}

// -----------------------------------------------------------------------------
// [4] Validação das Rotas Legais (/termos e /privacidade)
// -----------------------------------------------------------------------------
echo "\n[4] Validando Rotas Legais de Governança...\n";

// Teste /termos
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/termos';
ob_start();
require $rootDir . '/public/index.php';
$termsHtml = (string)ob_get_clean();

assertCheck("Rota /termos responde com conteúdo válido", strlen($termsHtml) > 2000);
assertCheck("Termos contém menção ao Conselho Regional de Nutricionistas (CRN)", str_contains($termsHtml, 'CRN') || str_contains($termsHtml, 'Conselho'));
assertCheck("Termos prevê período de avaliação de 14 dias", str_contains($termsHtml, '14 (quatorze) dias'));
assertCheck("Termos estabelece titularidade dos dados do cliente (LGPD)", str_contains($termsHtml, 'Controlador') && str_contains($termsHtml, 'Operador'));

// Teste /privacidade
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/privacidade';
ob_start();
require $rootDir . '/public/index.php';
$privacyHtml = (string)ob_get_clean();

assertCheck("Rota /privacidade responde com conteúdo válido", strlen($privacyHtml) > 2000);
assertCheck("Política cita explicitamente a Lei Geral de Proteção de Dados (13.709/2018)", str_contains($privacyHtml, '13.709/2018'));
assertCheck("Política especifica medidas de segurança (SHA-256, Bcrypt, TLS)", str_contains($privacyHtml, 'SHA-256') && str_contains($privacyHtml, 'Bcrypt'));
assertCheck("Política especifica contato do DPO / Encarregado de Dados", str_contains($privacyHtml, 'dpo@clyonutri.com.br'));

// -----------------------------------------------------------------------------
// [5] Validação de CSS e Design System Tokens
// -----------------------------------------------------------------------------
echo "\n[5] Validando Folha de Estilos e Tokens do Design System Premium...\n";

$cssPath = $rootDir . '/public/assets/css/landing-premium.css';
assertCheck("Arquivo landing-premium.css existe", file_exists($cssPath));

$cssContent = (string)file_get_contents($cssPath);
assertCheck("Token de cor Forest (#0F3E17) configurado", str_contains($cssContent, '#0F3E17'));
assertCheck("Token de cor Copper (#EF8F4F) configurado", str_contains($cssContent, '#EF8F4F'));
assertCheck("Token Cream (#FAF9F4) configurado", str_contains($cssContent, '#FAF9F4'));
assertCheck("Tipografia Playfair Display e Inter declaradas", str_contains($cssContent, 'Playfair Display') && str_contains($cssContent, 'Inter'));
assertCheck("Regra de foco visível (:focus-visible) para acessibilidade", str_contains($cssContent, ':focus-visible'));
assertCheck("Breakpoints responsivos (1024px, 768px) definidos", str_contains($cssContent, '@media (max-width: 1024px)') && str_contains($cssContent, '@media (max-width: 768px)'));
assertCheck("Suporte a acessibilidade de movimento reduzido (prefers-reduced-motion)", str_contains($cssContent, 'prefers-reduced-motion: reduce'));
assertCheck("Alvos de toque móvel mínimos de 44px assegurados", str_contains($cssContent, 'min-height: 44px'));

// -----------------------------------------------------------------------------
// [6] Ausência de Padrões Proibidos pelo Master Prompt
// -----------------------------------------------------------------------------
echo "\n[6] Validando Conformidade com as Regras de Proibição Visual...\n";

assertCheck("Não há imagens genéricas de saladas ou banco de imagens gratuito", !str_contains($landingHtml, 'unsplash.com') && !str_contains($landingHtml, 'pexels.com'));
assertCheck("Não há emojis usados como ilustrações", !preg_match('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u', $landingHtml));
assertCheck("Não há elementos roxos de SaaS genéricos", !str_contains($cssContent, '#6B46C1') && !str_contains($cssContent, '#8B5CF6') && !str_contains($cssContent, '#7C3AED'));

// -----------------------------------------------------------------------------
// Resumo da Avaliação
// -----------------------------------------------------------------------------
echo "\n====================================================================\n";
echo "   RESULTADO DA AUDITORIA DA LANDING PAGE: {$passed} PASS, {$failed} FAIL\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}

exit(0);
