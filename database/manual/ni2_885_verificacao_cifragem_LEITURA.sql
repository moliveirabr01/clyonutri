-- NI2-885 (v54.76): verificação SÓ LEITURA da cifragem em repouso. Esperado depois do script: todas as contagens = 0.
-- Resultado NULL = tabela vazia (também conta como 0).
-- Selecione o banco do sistema no phpMyAdmin antes de executar. Não altera nada.
SELECT 'anamneses' AS tabela,
  SUM(chief_complaint IS NOT NULL AND chief_complaint <> '' AND chief_complaint NOT LIKE 'enc:v1:%') +
  SUM(objectives IS NOT NULL AND objectives <> '' AND objectives NOT LIKE 'enc:v1:%') +
  SUM(family_history IS NOT NULL AND family_history <> '' AND family_history NOT LIKE 'enc:v1:%') +
  SUM(pathologies IS NOT NULL AND pathologies <> '' AND pathologies NOT LIKE 'enc:v1:%') +
  SUM(surgeries_details IS NOT NULL AND surgeries_details <> '' AND surgeries_details NOT LIKE 'enc:v1:%') +
  SUM(medications_details IS NOT NULL AND medications_details <> '' AND medications_details NOT LIKE 'enc:v1:%') +
  SUM(supplements_details IS NOT NULL AND supplements_details <> '' AND supplements_details NOT LIKE 'enc:v1:%') +
  SUM(routine IS NOT NULL AND routine <> '' AND routine NOT LIKE 'enc:v1:%') +
  SUM(food_preferences IS NOT NULL AND food_preferences <> '' AND food_preferences NOT LIKE 'enc:v1:%') +
  SUM(restrictions IS NOT NULL AND restrictions <> '' AND restrictions NOT LIKE 'enc:v1:%') +
  SUM(allergies IS NOT NULL AND allergies <> '' AND allergies NOT LIKE 'enc:v1:%') +
  SUM(symptoms IS NOT NULL AND symptoms <> '' AND symptoms NOT LIKE 'enc:v1:%') +
  SUM(habits IS NOT NULL AND habits <> '' AND habits NOT LIKE 'enc:v1:%') +
  SUM(alcohol_details IS NOT NULL AND alcohol_details <> '' AND alcohol_details NOT LIKE 'enc:v1:%') +
  SUM(smoking_details IS NOT NULL AND smoking_details <> '' AND smoking_details NOT LIKE 'enc:v1:%') +
  SUM(sleep_notes IS NOT NULL AND sleep_notes <> '' AND sleep_notes NOT LIKE 'enc:v1:%') +
  SUM(referenced_exams IS NOT NULL AND referenced_exams <> '' AND referenced_exams NOT LIKE 'enc:v1:%') +
  SUM(clinical_history IS NOT NULL AND clinical_history <> '' AND clinical_history NOT LIKE 'enc:v1:%') +
  SUM(notes IS NOT NULL AND notes <> '' AND notes NOT LIKE 'enc:v1:%') +
  SUM(structured_data_json IS NOT NULL AND structured_data_json <> '' AND structured_data_json NOT LIKE 'enc:v1:%') AS campos_em_claro
FROM anamneses
UNION ALL
SELECT 'patients',
  SUM(notes IS NOT NULL AND notes <> '' AND notes NOT LIKE 'enc:v1:%') +
  SUM(allergies IS NOT NULL AND allergies <> '' AND allergies NOT LIKE 'enc:v1:%')
FROM patients
UNION ALL
SELECT 'consultation_note_drafts', SUM(evolution_summary IS NOT NULL AND evolution_summary <> '' AND evolution_summary NOT LIKE 'enc:v1:%')
FROM consultation_note_drafts
UNION ALL
SELECT 'clinical_records', SUM(content_json IS NOT NULL AND content_json <> '' AND content_json NOT LIKE 'enc:v1:%')
FROM clinical_records;
