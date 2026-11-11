-- =============================================================================
-- ClyoNutri II — AUDITORIA NI2-602 (SOMENTE LEITURA)
-- Avaliações antropométricas que podem ter sido gravadas no paciente errado pelo modal
-- "Registrar 1ª avaliação" do perfil (defeito presente da migração 48, chat, em 22/10/2026, até a v54.1).
--
-- Como o defeito acontecia: o formulário do perfil usava o id de um PROFISSIONAL da clínica como id do
-- paciente. A gravação só acontecia quando existia, na mesma clínica, um paciente com esse mesmo número.
--
-- Esta consulta lista os SUSPEITOS: avaliações gravadas desde 22/10/2026 em paciente cujo id é igual ao id
-- de um profissional ativo da mesma clínica. Ser suspeito NÃO prova o erro: confira cada linha com o
-- profissional (peso/altura compatíveis com o paciente? houve atendimento nesse dia?).
-- Não altera nada. Banco: maic6824_Clyonutri2.
-- =============================================================================

SELECT
    a.id                AS avaliacao_id,
    a.clinic_id,
    c.name              AS clinica,
    a.patient_id        AS gravada_no_paciente_id,
    pa.name             AS gravada_no_paciente,
    a.weight_kg,
    a.height_cm,
    a.assessed_at,
    a.created_at,
    ua.name             AS registrada_por,
    pr.id               AS profissional_com_mesmo_id,
    up.name             AS nome_desse_profissional
FROM `maic6824_Clyonutri2`.`anthropometric_assessments` a
JOIN `maic6824_Clyonutri2`.`patients` pa       ON pa.id = a.patient_id
JOIN `maic6824_Clyonutri2`.`clinics` c         ON c.id = a.clinic_id
JOIN `maic6824_Clyonutri2`.`professionals` pr  ON pr.id = a.patient_id
JOIN `maic6824_Clyonutri2`.`users` up          ON up.id = pr.user_id
JOIN `maic6824_Clyonutri2`.`clinic_members` cm ON cm.user_id = pr.user_id AND cm.clinic_id = a.clinic_id
LEFT JOIN `maic6824_Clyonutri2`.`professionals` pa_prof ON pa_prof.id = a.professional_id
LEFT JOIN `maic6824_Clyonutri2`.`users` ua     ON ua.id = pa_prof.user_id
WHERE a.created_at >= '2026-10-22 00:00:00'
ORDER BY a.created_at DESC;

-- Resumo: quantas avaliações suspeitas por clínica (esperado: 0 na maioria).
SELECT a.clinic_id, COUNT(*) AS suspeitas
FROM `maic6824_Clyonutri2`.`anthropometric_assessments` a
JOIN `maic6824_Clyonutri2`.`professionals` pr  ON pr.id = a.patient_id
JOIN `maic6824_Clyonutri2`.`clinic_members` cm ON cm.user_id = pr.user_id AND cm.clinic_id = a.clinic_id
WHERE a.created_at >= '2026-10-22 00:00:00'
GROUP BY a.clinic_id
ORDER BY suspeitas DESC;
