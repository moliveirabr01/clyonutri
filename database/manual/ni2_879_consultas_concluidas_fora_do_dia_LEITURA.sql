-- NI2-879 (v54.72) · SOMENTE LEITURA · MySQL 5.7 / MariaDB
-- Lista as consultas concluídas em um dia diferente do agendado (efeito do defeito A-04 antes da v54.72).
-- Não altera nada. O prontuário é imutável: os registros listados ficam como estão.
-- A partir da v54.72 a ficha do paciente já mostra a data real dessas consultas, com "(agendada para dd/mm)".
-- Rodar no phpMyAdmin, aba SQL, no banco de produção. Guardar o resultado para o relatório da história.

SELECT
    a.clinic_id,
    a.id                    AS consulta_id,
    a.patient_id,
    a.scheduled_at          AS agendada_para,
    h.concluida_em,
    DATEDIFF(a.scheduled_at, h.concluida_em) AS dias_de_diferenca
FROM appointments a
JOIN (
    SELECT appointment_id, MAX(created_at) AS concluida_em
    FROM appointment_status_history
    WHERE to_status = 'completed'
    GROUP BY appointment_id
) h ON h.appointment_id = a.id
WHERE a.status = 'completed'
  AND DATE(a.scheduled_at) <> DATE(h.concluida_em)
ORDER BY a.clinic_id, h.concluida_em;
