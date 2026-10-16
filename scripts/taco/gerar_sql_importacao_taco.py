#!/usr/bin/env python3
"""Gera o SQL autônomo (phpMyAdmin) da TACO completa a partir de database/seeds/taco/taco_4a_edicao_tabela1.csv.
Mesma lógica de src/Domain/Food/TacoCatalogImporter.php. Uso: python3 gerar_sql_importacao_taco.py SAIDA.sql"""
import csv, hashlib, os, re, sys
ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
CSV = os.path.join(ROOT, 'database/seeds/taco/taco_4a_edicao_tabela1.csv')
PHP = open(os.path.join(ROOT, 'src/Domain/Food/TacoCatalogImporter.php'), encoding='utf-8').read()
LINKS = re.findall(r"'([^']+)' => (\d+),", PHP.split('LINKS = [')[1].split('];')[0])
rows = list(csv.DictReader(open(CSV, encoding='utf-8')))
assert len(rows) == 597
sha = hashlib.sha256(open(CSV, 'rb').read()).hexdigest()
q = lambda s: "'" + s.replace('\\', '\\\\').replace("'", "''") + "'"
nul = lambda s: q(s) if s else 'NULL'
REFS = [('meal_plan_items','food_id'),('meal_plan_substitutions','food_id'),('recipe_ingredients','food_id'),('food_household_measures','food_id'),('food_equivalence_items','food_id')]
o = []
w = o.append
w(f"""-- ============================================================================
-- ClyoNutri — Importação da TACO completa (597 alimentos; 593 inseridos/atualizados)
-- Fonte: NEPA-UNICAMP. Tabela Brasileira de Composição de Alimentos – TACO.
--        4. ed. rev. e ampl. Campinas: NEPA-UNICAMP, 2011. (reprodução permitida com citação da fonte)
-- PDF oficial: https://www.nepa.unicamp.br/wp-content/uploads/sites/27/2023/10/taco_4_edicao_ampliada_e_revisada.pdf
--   SHA-256 do PDF: 2002aec5615b5b1395aaa8fa675635bbb7f712c33f278af5e332f1cac8f108c8
-- Dados (Tabela 1, por 100 g): database/seeds/taco/taco_4a_edicao_tabela1.csv  SHA-256 {sha}
--
-- COMO USAR: phpMyAdmin > banco do ClyoNutri > Importar > este arquivo (utf8mb4). Pode rodar de novo
-- quantas vezes quiser: não duplica, não apaga e não toca alimentos cadastrados pelas clínicas.
-- Os alimentos de referência que já existiam são vinculados ao número TACO pelo nome e recebem só os
-- nutrientes oficiais (nome, porção, medidas caseiras e alergênicos ficam iguais).
-- Tr (traço) e NA (não aplicável) => 0. "*" (em reavaliação) e célula em branco => 0, marcado em nutrient_flags.
-- Alimentos com energia/macros "*" na TACO NÃO são inseridos: 450, 457, 458, 591.
--
-- DESFAZER: o caminho seguro é restaurar o backup feito antes. Sem backup, este bloco remove só os
-- alimentos TACO inseridos que nenhum plano/receita/medida/equivalência usa (descomente para rodar):
--   DELETE f FROM foods f WHERE f.taco_code IS NOT NULL AND f.clinic_id IS NULL
--     AND f.name NOT IN ({', '.join(q(n) for n, _ in LINKS)})
""" + ''.join(f"--     AND NOT EXISTS (SELECT 1 FROM {t} r WHERE r.{c} = f.id)\n" for t, c in REFS) + """--   ;
--   (as colunas taco_code/nutrient_flags podem ficar; são ignoradas pela versão anterior do sistema)
-- ============================================================================
SET NAMES utf8mb4;

-- 1) Estrutura (migration 34). DDL fora da transação; só cria o que faltar.
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'taco_code');
SET @s := IF(@c = 0, 'ALTER TABLE foods ADD COLUMN taco_code INT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND COLUMN_NAME = 'nutrient_flags');
SET @s := IF(@c = 0, 'ALTER TABLE foods ADD COLUMN nutrient_flags VARCHAR(255) NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
SET @c := (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'foods' AND INDEX_NAME = 'uq_foods_taco_code');
SET @s := IF(@c = 0, 'ALTER TABLE foods ADD UNIQUE INDEX uq_foods_taco_code (taco_code)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
INSERT INTO migrations (migration) SELECT '2026_10_15_000034_add_taco_code_to_foods' FROM DUAL
  WHERE NOT EXISTS (SELECT 1 FROM migrations WHERE migration = '2026_10_15_000034_add_taco_code_to_foods');

START TRANSACTION;

-- 2) Vincula os alimentos de referência já existentes (globais) ao número TACO, pelo nome.
""")
for n, c in LINKS:
    w(f"SET @id := (SELECT MIN(id) FROM foods WHERE clinic_id IS NULL AND is_custom = 0 AND source = 'TACO' AND taco_code IS NULL AND name = {q(n)});\n"
      f"SET @t := (SELECT COUNT(*) FROM foods WHERE taco_code = {c});\n"
      f"UPDATE foods SET taco_code = {c} WHERE id = @id AND @t = 0 AND clinic_id IS NULL;\n")
w("\n-- 3) Insere/atualiza os alimentos TACO (chave: taco_code). Nunca altera linhas de clínica.\n")
imp = [r for r in rows if r['import'] == '1']
G = lambda col: f"{col} = IF(clinic_id IS NULL AND is_custom = 0, VALUES({col}), {col})"
for i in range(0, len(imp), 100):
    w("INSERT INTO foods (clinic_id, name, category, source, energy_kcal, protein_g, lipid_g, carbohydrate_g, fiber_g, serving_size_g, serving_unit, is_custom, taco_code, nutrient_flags) VALUES\n")
    w(',\n'.join(f"(NULL, {q(r['name'])}, {q(r['category'])}, 'TACO', {r['energy_kcal']}, {r['protein_g']}, {r['lipid_g']}, {r['carbohydrate_g']}, {r['fiber_g']}, 100, 'g', 0, {r['taco_code']}, {nul(r['nutrient_flags'])})" for r in imp[i:i+100]))
    w("\nON DUPLICATE KEY UPDATE " + ', '.join(G(c) for c in ['energy_kcal','protein_g','lipid_g','carbohydrate_g','fiber_g','nutrient_flags'])
      + ", category = IF(clinic_id IS NULL AND is_custom = 0, COALESCE(category, VALUES(category)), category);\n\n")
w("-- 4) Alimentos com energia/macros \"*\" (não inseridos): se já existem vinculados, só recebem a marcação.\n")
for r in rows:
    if r['import'] != '1':
        w(f"UPDATE foods SET nutrient_flags = {nul(r['nutrient_flags'])} WHERE taco_code = {r['taco_code']} AND clinic_id IS NULL AND is_custom = 0;\n")
w("""
COMMIT;

-- 5) Conferência (esperado: taco_vinculados = 594 (593 + leite desnatado de referência vinculado ao nº 457), ou 593 sem ele; clinica_com_taco = 0)
SELECT COUNT(*) AS taco_vinculados, SUM(clinic_id IS NOT NULL) AS clinica_com_taco FROM foods WHERE taco_code IS NOT NULL;
""")
open(sys.argv[1], 'w', encoding='utf-8').write(''.join(o))
