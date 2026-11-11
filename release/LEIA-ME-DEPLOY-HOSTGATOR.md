# Deploy HostGator — ClyoNutri v50 Consolidado

**Versão da Release:** v50 Consolidada  
**Data:** 01/11/2026  
**Histórias Unificadas:**
1. **NI2-223:** [P0] Anamnese estruturada completa com 12 blocos clínicos, regras condicionais, versionamento imutável e resumo clínico (`AnamnesisService.php`, `views/anamnesis/version.php`, Migration 69 / SQL).
2. **NI2-523:** Avisos clínicos por população no cálculo energético (`EnergyCalculator.php`, `EnergyTargetService.php` — gestantes, adolescentes em Cunningham, idosos $\ge 65$ anos).
3. **NI2-433:** Autoagendamento self-service com Portal do Paciente (`BookingService.php`, `BookingController.php`, `views/booking/public_booking.php`, rotas de contexto e confirmação autenticada no app).
4. **NI2-487:** Notificações transacionais outbox assíncronas com garantia anti-stale (`AppointmentConfirmationPublisher.php`, deduplicação atômica e proteção de disparo no agendamento).
5. **NI2-514 (Fases 1, 2 e 3):** Prescrição dietética avançada, modelos de cardápio, importação CSV de pacientes com normalização DDI +55 e expurgo LGPD.

---

## 1. Pré-Requisitos e Precheck no Banco (phpMyAdmin)

1. Faça backup completo dos arquivos e exporte o banco pelo phpMyAdmin no cPanel.
2. Execute o script `PRECHECK-SOMENTE-LEITURA.sql` no banco da aplicação.
3. Se a tabela `anamneses` ainda não possuir as colunas da NI2-223, execute no phpMyAdmin:
   `database/manual/atualizacao-v50-ni2-223-anamnese-estruturada.sql`
4. Preserve o `.env` atual da hospedagem (com as chaves `APP_KEY`, credenciais de banco e SMTP).

---

## 2. Instruções de Atualização via cPanel (Gerenciador de Arquivos)

1. No Gerenciador de Arquivos do cPanel, acesse a pasta raiz da aplicação (ex.: `/home3/maic6824/clyonutri.com`).
2. Envie o pacote `clyonutri-v50-consolidado-hostgator.zip` para essa pasta.
3. Clique em **Extract** (Substituir arquivos existentes).
4. O zip contém a árvore de arquivos normalizada sem pasta intermediária e sem arquivos temporários Mac (`.DS_Store`, `__MACOSX`).
5. Apague o `.zip` do servidor após a extração.
6. Se houver cache de opcode/PHP no servidor, limpe-o e recarregue a página no navegador.

---

## 3. Validação Pós-Deploy

1. **Autoagendamento (NI2-433 / NI2-487):**
   - Acesse `/agendar/ID_DO_PROFISSIONAL` para verificar slots e reservas temporárias.
   - Acesse pelo Portal do Paciente autenticado para confirmar sem criar paciente duplicado.
2. **Anamnese Estruturada (NI2-223):**
   - Abra a ficha de um paciente e acesse `/pacientes/{id}/anamnese`.
   - Preencha os blocos clínicos com condicionais (cirurgias, medicamentos, fumo, álcool, sono, atividade física).
   - Salve o rascunho e promova para versão final, confirmando a imutabilidade histórica e o comparador de versões.
3. **Cálculo Energético (NI2-523):**
   - No editor de plano alimentar, configure paciente gestante, adolescente ou idoso e confira os alertas clínicos específicos emitidos no card de energia.
4. **Notificações:**
   - Execute o cron `bin/cron_scheduler.php` e observe o processamento da fila sem duplicação ou mensagens obsoletas.
