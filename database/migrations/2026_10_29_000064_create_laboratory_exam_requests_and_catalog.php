<?php

declare(strict_types=1);

/**
 * Migration 64 (NI2-529, NI2-530, NI2-531, NI2-532, NI2-533): Módulo de Solicitação de Exames Laboratoriais
 * para Acompanhamento Nutricional.
 *
 * Criação das tabelas de domínio clínico, catálogo regulatório e auditoria:
 *  1. exam_regulatory_policies: matriz versionada de políticas e conformidade com Lei 8.234/1991 e Resolução CFN 306/2003.
 *  2. exam_catalog: catálogo curado de exames laboratoriais com relevância nutricional, sinônimos e tipos de amostra.
 *  3. exam_requests: solicitações de exames laboratoriais vinculadas a paciente, profissional e tenant.
 *  4. exam_request_items: itens de exames de cada solicitação.
 *  5. exam_audit_logs: trilha imutável de auditoria de acessos e eventos regulatórios do módulo.
 *
 * Idempotente e compatível com MySQL 5.7 e SQLite.
 */
class CreateLaboratoryExamRequestsAndCatalog
{
    public function up(PDO $db): void
    {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $pk = $mysql ? 'BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $big = $mysql ? 'BIGINT(20)' : 'INTEGER';
        $text = $mysql ? 'LONGTEXT' : 'TEXT';
        $engine = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        // 1. Tabela de Políticas Regulatórias
        $db->exec("CREATE TABLE IF NOT EXISTS exam_regulatory_policies (
            id {$pk},
            policy_version VARCHAR(20) NOT NULL UNIQUE,
            legal_basis {$text} NOT NULL,
            statement {$text} NOT NULL,
            disclaimer_coverage {$text} NOT NULL,
            is_approved_clinical TINYINT(1) NOT NULL DEFAULT 1,
            is_approved_legal TINYINT(1) NOT NULL DEFAULT 1,
            effective_from DATE NOT NULL,
            created_at DATETIME NOT NULL
        ){$engine}");

        // 2. Catálogo Curado de Exames
        $db->exec("CREATE TABLE IF NOT EXISTS exam_catalog (
            id {$pk},
            code VARCHAR(60) NOT NULL UNIQUE,
            canonical_name VARCHAR(255) NOT NULL,
            short_name VARCHAR(100) NULL,
            category VARCHAR(100) NOT NULL,
            sample_type VARCHAR(100) NOT NULL,
            fasting_hours INT NULL,
            nutritional_indication {$text} NOT NULL,
            synonyms {$text} NULL,
            search_text {$text} NOT NULL,
            is_laboratorial TINYINT(1) NOT NULL DEFAULT 1,
            is_nutritional_scope TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            version INT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ){$engine}");

        // 3. Solicitações de Exames
        $db->exec("CREATE TABLE IF NOT EXISTS exam_requests (
            id {$pk},
            clinic_id {$big} NOT NULL,
            patient_id {$big} NOT NULL,
            professional_id {$big} NOT NULL,
            policy_version VARCHAR(20) NOT NULL,
            request_number VARCHAR(50) NOT NULL UNIQUE,
            clinical_justification {$text} NOT NULL,
            diagnostic_hypothesis_nutritional VARCHAR(255) NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'draft',
            professional_snapshot {$text} NOT NULL,
            patient_snapshot {$text} NOT NULL,
            coverage_disclaimer_acknowledged TINYINT(1) NOT NULL DEFAULT 0,
            issued_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            cancellation_reason {$text} NULL,
            pdf_hash VARCHAR(64) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ){$engine}");

        // 4. Itens da Solicitação
        $db->exec("CREATE TABLE IF NOT EXISTS exam_request_items (
            id {$pk},
            exam_request_id {$big} NOT NULL,
            exam_id {$big} NOT NULL,
            exam_canonical_name VARCHAR(255) NOT NULL,
            exam_code VARCHAR(60) NOT NULL,
            sample_type VARCHAR(100) NOT NULL,
            specific_instructions VARCHAR(500) NULL,
            matched_synonym VARCHAR(255) NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        ){$engine}");

        // 5. Trilha de Auditoria
        $db->exec("CREATE TABLE IF NOT EXISTS exam_audit_logs (
            id {$pk},
            clinic_id {$big} NOT NULL,
            user_id {$big} NOT NULL,
            action VARCHAR(50) NOT NULL,
            entity_type VARCHAR(50) NOT NULL,
            entity_id {$big} NOT NULL,
            ip_address VARCHAR(45) NULL,
            details {$text} NULL,
            created_at DATETIME NOT NULL
        ){$engine}");

        // Índices de performance e isolamento
        $this->index($db, $mysql, 'exam_catalog', 'idx_exam_catalog_category', '(category)');
        $this->index($db, $mysql, 'exam_catalog', 'idx_exam_catalog_active', '(is_active)');
        $this->index($db, $mysql, 'exam_requests', 'idx_exam_requests_clinic_patient', '(clinic_id, patient_id)');
        $this->index($db, $mysql, 'exam_requests', 'idx_exam_requests_professional', '(professional_id)');
        $this->index($db, $mysql, 'exam_requests', 'idx_exam_requests_status', '(status)');
        $this->index($db, $mysql, 'exam_request_items', 'idx_exam_request_items_req', '(exam_request_id)');
        $this->index($db, $mysql, 'exam_audit_logs', 'idx_exam_audit_clinic_user', '(clinic_id, user_id)');

        // Seed da Política Regulatória Inicial (v2026.1), como na u3 já aplicada em produção. Desde a v46 a emissão
        // não lê is_approved_clinical/is_approved_legal; a política vale pelo texto (base legal e aviso de cobertura).
        $stmt = $db->query("SELECT COUNT(*) FROM exam_regulatory_policies WHERE policy_version = '2026.1'");
        if ((int)$stmt->fetchColumn() === 0) {
            $insert = $db->prepare("INSERT INTO exam_regulatory_policies 
                (policy_version, legal_basis, statement, disclaimer_coverage, is_approved_clinical, is_approved_legal, effective_from, created_at)
                VALUES (?, ?, ?, ?, 1, 1, '2026-01-01', CURRENT_TIMESTAMP)");
            $insert->execute([
                '2026.1',
                'Lei Federal nº 8.234/1991 (art. 4º, inciso VIII) e Resolução CFN nº 306/2003.',
                'O nutricionista é legalmente habilitado a solicitar exames laboratoriais estritamente necessários ao acompanhamento dietoterápico e avaliação nutricional, sendo vedada a solicitação de exames invasivos não laboratoriais (como exames de imagem, endoscopias e biópsias) e a realização de diagnóstico clínico médico.',
                'Atenção: A cobertura de exames laboratoriais solicitados por nutricionistas depende das cláusulas contratuais de cada operadora de plano de saúde e das diretrizes da ANS/laboratórios locais. O ClyoNutri II não garante cobertura automática nem reembolso.',
            ]);
        }

        // Seed do Catálogo Curado de Exames com Relevância Nutricional
        $this->seedInitialCatalog($db);
    }

    private function seedInitialCatalog(PDO $db): void
    {
        $stmt = $db->query("SELECT COUNT(*) FROM exam_catalog");
        if ((int)$stmt->fetchColumn() > 0) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $exams = [
            [
                'code' => 'HEMOGRAMA_COMPLETO',
                'canonical_name' => 'Hemograma completo com contagem de plaquetas',
                'short_name' => 'Hemograma completo',
                'category' => 'Hematologia',
                'sample_type' => 'Sangue total (EDTA)',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação de anemias nutricionais (ferropriva, megaloblástica por carência de B12/folato), estado imune e contagem celular de base.',
                'synonyms' => 'hemograma, hemoglobina, hematocrito, leucograma, plaquetas, serie vermelha, contagem celular',
            ],
            [
                'code' => 'GLICEMIA_JEJUM',
                'canonical_name' => 'Glicemia de jejum',
                'short_name' => 'Glicemia',
                'category' => 'Glicemia e Metabolismo',
                'sample_type' => 'Plasma fluoretado',
                'fasting_hours' => 8,
                'nutritional_indication' => 'Avaliação do metabolismo glicídico basal, rastreamento de resistência à insulina e monitoramento do plano dietético no diabetes/pré-diabetes.',
                'synonyms' => 'glicose, acucar no sangue, glicemia basal, glicemia de jejum',
            ],
            [
                'code' => 'HEMOGLOBINA_GLICADA',
                'canonical_name' => 'Hemoglobina glicada (HbA1c)',
                'short_name' => 'HbA1c',
                'category' => 'Glicemia e Metabolismo',
                'sample_type' => 'Sangue total (EDTA)',
                'fasting_hours' => 0,
                'nutritional_indication' => 'Controle glicêmico retrospectivo dos últimos 90 a 120 dias para ajuste na ingestão de carboidratos e índice glicêmico da dieta.',
                'synonyms' => 'hba1c, a1c, hemoglobina glicosilada, media glicemica',
            ],
            [
                'code' => 'INSULINA_JEJUM',
                'canonical_name' => 'Insulina basal em jejum',
                'short_name' => 'Insulina de jejum',
                'category' => 'Glicemia e Metabolismo',
                'sample_type' => 'Soro',
                'fasting_hours' => 8,
                'nutritional_indication' => 'Avaliação de hiperinsulinemia e cálculo de índices de resistência insulínica (HOMA-IR, HOMA-BETA) para adequação macronutricional.',
                'synonyms' => 'insulina, homa ir, homa beta, insulinemia, curva de insulina',
            ],
            [
                'code' => 'PERFIL_LIPIDICO',
                'canonical_name' => 'Perfil lipídico (Colesterol total, HDL, LDL, VLDL e Triglicerídeos)',
                'short_name' => 'Lipidograma completo',
                'category' => 'Perfil Lipídico',
                'sample_type' => 'Soro',
                'fasting_hours' => 12,
                'nutritional_indication' => 'Diagnóstico dietoterápico de dislipidemias, estratificação de risco cardiometabólico e direcionamento na proporção de ácidos graxos (saturados, mono e poli-insaturados).',
                'synonyms' => 'lipidograma, colesterol, triglicerideos, hdl, ldl, vldl, lipidios, gordura no sangue',
            ],
            [
                'code' => 'FERRITINA_SERICA',
                'canonical_name' => 'Ferritina sérica',
                'short_name' => 'Ferritina',
                'category' => 'Micronutrientes e Vitaminas',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação das reservas orgânicas de ferro corporal e marcador inflamatório de fase aguda para prescrição e intervenção alimentar rica em ferro e facilitadores de absorção.',
                'synonyms' => 'ferritina, reserva de ferro, estoque de ferro',
            ],
            [
                'code' => 'FERRO_SERICO',
                'canonical_name' => 'Ferro sérico',
                'short_name' => 'Ferro',
                'category' => 'Micronutrientes e Vitaminas',
                'sample_type' => 'Soro',
                'fasting_hours' => 8,
                'nutritional_indication' => 'Determinação da concentração de ferro circulante para cálculo de saturação de transferrina e diagnóstico diferencial nutricional.',
                'synonyms' => 'ferro serico, ferro circulante, sideremia',
            ],
            [
                'code' => 'VITAMINA_B12',
                'canonical_name' => 'Vitamina B12 (Cobalamina)',
                'short_name' => 'Vitamina B12',
                'category' => 'Micronutrientes e Vitaminas',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Monitoramento do estado nutricional em vegetarianos, veganos, pós-cirurgia bariátrica e idosos para prevenção de manifestações hematológicas e neurológicas.',
                'synonyms' => 'b12, cobalamina, cianocobalamina, vit b12',
            ],
            [
                'code' => 'VITAMINA_D_25OH',
                'canonical_name' => '25-Hidroxivitamina D [25(OH)D]',
                'short_name' => 'Vitamina D',
                'category' => 'Micronutrientes e Vitaminas',
                'sample_type' => 'Soro',
                'fasting_hours' => 0,
                'nutritional_indication' => 'Avaliação do status corporal de colecalciferol, metabolismo ósseo de cálcio/fósforo e imunomodulação nutricional.',
                'synonyms' => 'vitamina d, 25 hidroxivitamina d, vit d, 25-oh-d, calcidiol',
            ],
            [
                'code' => 'ACIDO_FOLICO',
                'canonical_name' => 'Ácido fólico sérico (Folato)',
                'short_name' => 'Folato / Ácido fólico',
                'category' => 'Micronutrientes e Vitaminas',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação do metabolismo de um carbono, metilação e aporte dietético de vegetais folhosos escuros, gestação e risco de anemia macrocítica.',
                'synonyms' => 'folato, vitamina b9, b9, folacina, acido folico',
            ],
            [
                'code' => 'UREIA',
                'canonical_name' => 'Ureia sérica',
                'short_name' => 'Ureia',
                'category' => 'Função Renal',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação do catabolismo proteico, equilíbrio de nitrogênio e tolerância à prescrição de dietas hiperproteicas ou hipoproteicas.',
                'synonyms' => 'ureia, nitrogenio ureico, bun',
            ],
            [
                'code' => 'CREATININA_SERICA',
                'canonical_name' => 'Creatinina sérica com estimativa da Taxa de Filtração Glomerular (eTFG)',
                'short_name' => 'Creatinina',
                'category' => 'Função Renal',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação da função de filtração renal, cálculo da depuração para dimensionamento seguro da carga proteica da dieta e hidratação.',
                'synonyms' => 'creatinina, taxa de filtracao glomerular, tfg, clearence de creatinina',
            ],
            [
                'code' => 'TGO_AST',
                'canonical_name' => 'Transaminase Oxalacética (TGO / AST)',
                'short_name' => 'TGO / AST',
                'category' => 'Função Hepática',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Marcador de integridade hepática e muscular, monitorando intervenções nutricionais em esteatose hepática metabólica (EHNA).',
                'synonyms' => 'tgo, ast, aspartato aminotransferase',
            ],
            [
                'code' => 'TGP_ALT',
                'canonical_name' => 'Transaminase Alanina (TGP / ALT)',
                'short_name' => 'TGP / ALT',
                'category' => 'Função Hepática',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Marcador específico de integridade hepatocelular para acompanhamento dietoterápico na perda de peso e controle de gordura no fígado.',
                'synonyms' => 'tgp, alt, alanina aminotransferase, enzimas do figado',
            ],
            [
                'code' => 'GAMA_GT',
                'canonical_name' => 'Gama Glutamiltransferase (Gama-GT)',
                'short_name' => 'Gama-GT',
                'category' => 'Função Hepática',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Marcador de estresse oxidativo, sobrecarga metabólica hepática e consumo de etanol para conduta nutricional desintoxicante.',
                'synonyms' => 'ggt, gamagt, gama glutamil transferase',
            ],
            [
                'code' => 'ACIDO_URICO',
                'canonical_name' => 'Ácido úrico sérico',
                'short_name' => 'Ácido úrico',
                'category' => 'Glicemia e Metabolismo',
                'sample_type' => 'Soro',
                'fasting_hours' => 8,
                'nutritional_indication' => 'Avaliação do metabolismo das purinas e frutose, orientando a modulação dietética de alimentos ricos em purinas, frutos do mar e açúcares simples.',
                'synonyms' => 'acido urico, urato, uricemia, gota',
            ],
            [
                'code' => 'PROTEINAS_TOTAIS_FRACIONADAS',
                'canonical_name' => 'Proteínas totais e frações (Albumina e Globulinas)',
                'short_name' => 'Proteínas totais e frações',
                'category' => 'Nutrição e Proteínas',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação do estado de desnutrição proteico-calórica, pressão oncótica e síntese hepática para ajuste proteico dietético.',
                'synonyms' => 'albumina, globulina, proteinas totais, relacao a g, fracoes proteicas',
            ],
            [
                'code' => 'SODIO_POTASSIO',
                'canonical_name' => 'Eletrólitos séricos (Sódio e Potássio)',
                'short_name' => 'Sódio e Potássio',
                'category' => 'Eletrólitos e Minerais',
                'sample_type' => 'Soro',
                'fasting_hours' => 0,
                'nutritional_indication' => 'Equilíbrio hidroeletrolítico, conduta dietética em hipertensão (DASH), doença renal ou atletas em desidratação.',
                'synonyms' => 'sodio, potassio, eletrolitos, ionograma',
            ],
            [
                'code' => 'MAGNESIO_SERICO',
                'canonical_name' => 'Magnésio sérico',
                'short_name' => 'Magnésio',
                'category' => 'Eletrólitos e Minerais',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação de cofator enzimático essencial em vias energéticas e sensibilidade insulínica, orientando consumo de sementes, oleaginosas e folhas verdes.',
                'synonyms' => 'magnesio, mg serico, magnesemia',
            ],
            [
                'code' => 'ZINCO_SERICO',
                'canonical_name' => 'Zinco sérico',
                'short_name' => 'Zinco',
                'category' => 'Micronutrientes e Vitaminas',
                'sample_type' => 'Soro',
                'fasting_hours' => 8,
                'nutritional_indication' => 'Avaliação de oligoelemento antioxidante, função imunológica, cicatrização e paladar na conduta alimentar.',
                'synonyms' => 'zinco, zn, zinco plasmatico',
            ],
            [
                'code' => 'TSH_T4_LIVRE',
                'canonical_name' => 'Hormônio Tireoestimulante (TSH) e Tiroxina Livre (T4 Livre)',
                'short_name' => 'TSH e T4 Livre',
                'category' => 'Tireoide e Hormonal',
                'sample_type' => 'Soro',
                'fasting_hours' => 4,
                'nutritional_indication' => 'Avaliação do ritmo metabólico basal na determinação do gasto energético total e orientações quanto ao iodo, selênio e bociogênicos da dieta.',
                'synonyms' => 'tsh, t4 livre, tireoide, perfil tireoidiano, tiroxina',
            ],
            [
                'code' => 'URINA_TIPO_1_EAS',
                'canonical_name' => 'Urina tipo 1 (EAS / Elementos Anormais e Sedimento)',
                'short_name' => 'EAS / Urina tipo 1',
                'category' => 'Urinálise',
                'sample_type' => 'Urina (jato médio)',
                'fasting_hours' => 0,
                'nutritional_indication' => 'Rastreamento de glicosúria, proteinúria, densidade urinária para hidratação e presença de cristais urinários (uratos, oxalato de cálcio) para conduta preventiva de litíase.',
                'synonyms' => 'eas, urina 1, urina tipo 1, sumario de urina, sedimento urinario',
            ]
        ];

        $ins = $db->prepare("INSERT INTO exam_catalog 
            (code, canonical_name, short_name, category, sample_type, fasting_hours, nutritional_indication, synonyms, search_text, is_laboratorial, is_nutritional_scope, is_active, version, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1, ?, ?)");

        foreach ($exams as $exam) {
            $searchText = $this->buildSearchText($exam['canonical_name'], $exam['short_name'], $exam['category'], $exam['synonyms']);
            $ins->execute([
                $exam['code'],
                $exam['canonical_name'],
                $exam['short_name'],
                $exam['category'],
                $exam['sample_type'],
                $exam['fasting_hours'],
                $exam['nutritional_indication'],
                $exam['synonyms'],
                $searchText,
                $now,
                $now,
            ]);
        }
    }

    private function buildSearchText(string $name, ?string $short, string $cat, string $synonyms): string
    {
        $raw = "{$name} {$short} {$cat} {$synonyms}";
        $clean = mb_strtolower($raw, 'UTF-8');
        $clean = (string)preg_replace('/[áàãâä]/u', 'a', $clean);
        $clean = (string)preg_replace('/[éèêë]/u', 'e', $clean);
        $clean = (string)preg_replace('/[íìîï]/u', 'i', $clean);
        $clean = (string)preg_replace('/[óòõôö]/u', 'o', $clean);
        $clean = (string)preg_replace('/[úùûü]/u', 'u', $clean);
        $clean = (string)preg_replace('/[ç]/u', 'c', $clean);
        return preg_replace('/\s+/', ' ', trim($clean));
    }

    private function index(PDO $db, bool $mysql, string $table, string $name, string $cols): void
    {
        if ($mysql) {
            $stmt = $db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND INDEX_NAME = '{$name}'");
            if ((int)$stmt->fetchColumn() === 0) {
                $db->exec("ALTER TABLE {$table} ADD INDEX {$name} {$cols}");
            }
        } else {
            $db->exec("CREATE INDEX IF NOT EXISTS {$name} ON {$table} {$cols}");
        }
    }
}
