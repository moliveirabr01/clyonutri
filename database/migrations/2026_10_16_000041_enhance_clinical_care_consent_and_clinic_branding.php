<?php

declare(strict_types=1);

/**
 * Migration 41 (v18 / Auditoria de Navegação Assistida):
 * 1. Insere o termo de consentimento oficial para atendimento clínico (purpose_code = 'clinical_care').
 * 2. Adiciona campos de identidade visual, endereço e cabeçalho na tabela clinics.
 * 3. Adiciona coluna device_check_mode em teleconsultation_sessions para suporte a modo somente áudio.
 * 4. Povoa medidas caseiras essenciais com equivalência em gramas (food_household_measures).
 * 5. Insere artigos de ajuda para teleconsulta e aplicativo do paciente (helpdesk_articles).
 */
class EnhanceClinicalCareConsentAndClinicBranding
{
    public function up(PDO $db): void
    {
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

        // 1. Termo de consentimento oficial para assistência clínica (LGPD)
        $stmt = $db->prepare("SELECT COUNT(*) FROM consent_terms WHERE purpose_code = 'clinical_care' AND version = '2026.10-r1'");
        $stmt->execute();
        if ((int)$stmt->fetchColumn() === 0) {
            $insert = $db->prepare("
                INSERT INTO consent_terms (version, title, purpose_code, term_text, is_active, created_at)
                VALUES (:version, :title, :purpose, :text, 1, CURRENT_TIMESTAMP)
            ");
            $insert->execute([
                'version' => '2026.10-r1',
                'title' => 'Termo de Consentimento para Assistência Nutricional e Tratamento de Dados (LGPD)',
                'purpose' => 'clinical_care',
                'text' => 'Concordo com o tratamento de dados pessoais e dados de saúde para fins exclusivos de assistência nutricional, elaboração de plano alimentar, acompanhamento clínico e cumprimento de obrigações legais e regulatórias do Conselho Federal e Regionais de Nutricionistas (CFN/CRN), nos termos da Lei Geral de Proteção de Dados Pessoais (LGPD - Lei nº 13.709/2018). As informações clínicas permanecem sob sigilo profissional no prontuário do consultório, podendo ser acessadas ou retificadas mediante solicitação ao profissional responsável.',
            ]);
        }

        // 2. Colunas complementares para a tabela clinics (identidade do consultório e documentos)
        $clinicCols = [
            'logo_path' => 'VARCHAR(255) NULL',
            'address_street' => 'VARCHAR(255) NULL',
            'address_number' => 'VARCHAR(30) NULL',
            'address_complement' => 'VARCHAR(100) NULL',
            'address_neighborhood' => 'VARCHAR(100) NULL',
            'address_city' => 'VARCHAR(100) NULL',
            'address_state' => 'VARCHAR(10) NULL',
            'address_zip' => 'VARCHAR(20) NULL',
            'document_header_text' => 'TEXT NULL',
            'document_footer_text' => 'TEXT NULL',
        ];

        foreach ($clinicCols as $col => $type) {
            try {
                $db->exec("ALTER TABLE clinics ADD COLUMN {$col} {$type}");
            } catch (\Throwable) {
                // Coluna já existe
            }
        }

        // 3. Coluna device_check_mode em teleconsultation_sessions
        try {
            $db->exec("ALTER TABLE teleconsultation_sessions ADD COLUMN device_check_mode VARCHAR(30) DEFAULT 'full'");
        } catch (\Throwable) {
            // Coluna já existe
        }

        // 4. Medidas caseiras com equivalência em gramas
        $insertMeasure = $driver === 'mysql'
            ? "INSERT IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent) VALUES (:fid, :measure, :grams)"
            : "INSERT OR IGNORE INTO food_household_measures (food_id, measure_name, grams_equivalent) VALUES (:fid, :measure, :grams)";
        
        $mStmt = $db->prepare($insertMeasure);

        // Mapear alimentos comuns por nome e taco_code para vincular medidas caseiras
        $measuresList = [
            // Banana prata
            ['foods_like' => 'Banana%prata%', 'measure' => 'unidade média (100g)', 'grams' => 100.0],
            ['foods_like' => 'Banana%prata%', 'measure' => 'unidade média', 'grams' => 100.0],
            ['foods_like' => 'Banana%prata%', 'measure' => 'unidade pequena (65g)', 'grams' => 65.0],
            ['foods_like' => 'Banana%prata%', 'measure' => 'unidade grande (130g)', 'grams' => 130.0],
            ['foods_like' => 'Banana%prata%', 'measure' => 'fatia', 'grams' => 15.0],
            // Banana nanica
            ['foods_like' => 'Banana%nanica%', 'measure' => 'unidade média', 'grams' => 100.0],
            ['foods_like' => 'Banana%nanica%', 'measure' => 'unidade pequena', 'grams' => 70.0],
            // Pão francês
            ['foods_like' => 'Pão%francês%', 'measure' => 'unidade (50g)', 'grams' => 50.0],
            ['foods_like' => 'Pão%francês%', 'measure' => 'unidade', 'grams' => 50.0],
            ['foods_like' => 'Pão%francês%', 'measure' => 'fatia (30g)', 'grams' => 30.0],
            // Ovo de galinha
            ['foods_like' => 'Ovo%galinha%cozido%', 'measure' => 'unidade (50g)', 'grams' => 50.0],
            ['foods_like' => 'Ovo%galinha%cozido%', 'measure' => 'unidade', 'grams' => 50.0],
            // Feijão carioca
            ['foods_like' => 'Feijão%carioca%cozido%', 'measure' => 'concha média (130g)', 'grams' => 130.0],
            ['foods_like' => 'Feijão%carioca%cozido%', 'measure' => 'concha média', 'grams' => 130.0],
            ['foods_like' => 'Feijão%carioca%cozido%', 'measure' => 'colher de sopa (25g)', 'grams' => 25.0],
            // Feijão preto
            ['foods_like' => 'Feijão%preto%cozido%', 'measure' => 'concha média (130g)', 'grams' => 130.0],
            ['foods_like' => 'Feijão%preto%cozido%', 'measure' => 'concha média', 'grams' => 130.0],
            // Frango peito
            ['foods_like' => 'Frango%peito%sem pele%', 'measure' => 'filé médio (100g)', 'grams' => 100.0],
            ['foods_like' => 'Frango%peito%sem pele%', 'measure' => 'filé grande (150g)', 'grams' => 150.0],
            ['foods_like' => 'Frango%peito%sem pele%', 'measure' => 'pedaço pequeno (50g)', 'grams' => 50.0],
            // Maçã
            ['foods_like' => 'Maçã%Fuji%', 'measure' => 'unidade média (130g)', 'grams' => 130.0],
            ['foods_like' => 'Maçã%Fuji%', 'measure' => 'unidade média', 'grams' => 130.0],
            ['foods_like' => 'Maçã%Fuji%', 'measure' => 'unidade pequena (90g)', 'grams' => 90.0],
            // Arroz cozido
            ['foods_like' => 'Arroz%branco%cozido%', 'measure' => 'colher de sopa cheia (30g)', 'grams' => 30.0],
            ['foods_like' => 'Arroz%branco%cozido%', 'measure' => 'xícara de chá (150g)', 'grams' => 150.0],
        ];

        foreach ($measuresList as $m) {
            $fStmt = $db->prepare("SELECT id FROM foods WHERE name LIKE :pattern LIMIT 1");
            $fStmt->execute(['pattern' => $m['foods_like']]);
            $foodId = $fStmt->fetchColumn();
            if ($foodId) {
                try {
                    $mStmt->execute([
                        'fid' => (int)$foodId,
                        'measure' => $m['measure'],
                        'grams' => $m['grams'],
                    ]);
                } catch (\Throwable) {}
            }
        }

        // 5. Artigos de ajuda para categorias vazias (teleconsulta e aplicativo)
        $articles = [
            [
                'category' => 'teleconsulta',
                'title' => 'Como funciona a sala de teleconsulta e o teste de dispositivos?',
                'summary' => 'Guia passo a passo para o nutricionista e o paciente realizarem consultas por vídeo com teste de câmera, microfone e modo de contingência em áudio.',
                'content' => "A teleconsulta do ClyoNutri permite atendimentos remotos seguros, criptografados de ponta a ponta.\n\n### Como iniciar a teleconsulta:\n1. Acesse sua agenda e clique na consulta agendada como modalidade Teleconsulta.\n2. Clique em **Abrir Sala de Teleconsulta** para ingressar na recepção virtual.\n3. O paciente recebe um link de acesso exclusivo e acessa a sala de espera sem precisar instalar aplicativos pesados.\n4. Na sala de espera, o paciente realiza o teste de câmera e microfone e concorda com o Termo de Teleconsulta (LGPD).\n\n### E se a câmera do paciente falhar?\nO paciente tem a opção de clicar em **Entrar apenas com microfone (áudio)** ou **Continuar sem câmera**. O profissional é avisado na tela sobre o estado do dispositivo do paciente e pode conduzir o atendimento normalmente.",
            ],
            [
                'category' => 'teleconsulta',
                'title' => 'O que fazer se a câmera ou microfone não forem autorizados no navegador?',
                'summary' => 'Solução rápida para permissões bloqueadas no Google Chrome, Safari, Firefox e navegadores móveis.',
                'content' => "Quando o navegador bloqueia a câmera ou o microfone, um ícone de cadeado ou câmera bloqueada aparece na barra de endereço.\n\n### Como liberar o acesso:\n1. No computador: clique no ícone de cadeado ao lado de https://clyonutri.com na barra de endereço.\n2. Ative as permissões de **Câmera** e **Microfone** para 'Permitir'.\n3. Recarregue a página da sala de espera.\n4. No celular: vá em Configurações do aparelho > Aplicativos > Navegador > Permissões e autorize a Câmera e o Microfone.",
            ],
            [
                'category' => 'app_paciente',
                'title' => 'Como orientar o paciente no acesso ao link web e aplicativo?',
                'summary' => 'Como gerar convite com link direto, QR code e orientar o paciente para acessar prescrições e diário.',
                'content' => "O ClyoNutri oferece acesso imediato para o paciente sem atritos.\n\n### Como enviar o convite:\n1. No prontuário do paciente, acesse a aba **Convite do App** ou **Acesso do Paciente**.\n2. O sistema gera um link web direto e um QR code de acesso válido.\n3. Você pode clicar em **Copiar Link** ou enviar por WhatsApp/e-mail.\n4. Pelo link web, o paciente consegue visualizar o plano alimentar publicado, registrar água e refeições em qualquer celular ou computador.",
            ],
        ];

        $insArt = $db->prepare("
            INSERT INTO helpdesk_articles (category, title, slug, summary, content_markdown, is_published, view_count, created_at, updated_at)
            VALUES (:cat, :title, :slug, :summary, :content, 1, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        foreach ($articles as $art) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $art['title']) ?? '');
            $slug = trim($slug, '-');
            $check = $db->prepare("SELECT COUNT(*) FROM helpdesk_articles WHERE slug = :slug");
            $check->execute(['slug' => $slug]);
            if ((int)$check->fetchColumn() === 0) {
                try {
                    $insArt->execute([
                        'cat' => $art['category'],
                        'title' => $art['title'],
                        'slug' => $slug,
                        'summary' => $art['summary'],
                        'content' => $art['content'],
                    ]);
                } catch (\Throwable) {}
            }
        }
    }

    public function down(PDO $db): void
    {
        // Não-destrutivo: termos de consentimento e medidas caseiras são dados mestres
    }
}
