# ClyoNutri II · v54.72 · LEIA-ME para a HostGator

**Base:** v54.71 · **História:** NI2-879 (A-04) · **Mudança de banco:** nenhuma

## O que muda para o nutricionista

Antes, uma consulta marcada para outro dia podia ser iniciada e concluída hoje, e o prontuário, a agenda e a ficha ficavam com a data agendada (por exemplo "Consulta #2 (16/12/2026)" e "Realizada" em dezembro).

A partir da v54.72:

1. **Sala da consulta.** Ao abrir uma consulta de outro dia, aparecem duas opções. Sem escolher uma, a consulta não começa.
   - **Remarcar esta consulta para agora:** a data passa a ser hoje e a original fica no histórico. Se já houver outra consulta neste horário, o sistema avisa e só remarca com "Confirmo o encaixe".
   - **Criar o atendimento de hoje e manter o agendamento:** abre um atendimento novo agora e deixa o agendado como está.
2. **As duas opções valem a qualquer hora**, inclusive fora da jornada configurada (almoço, noite), e **não mandam aviso de agendamento ao paciente**, que já está sendo atendido.
3. **Clicar duas vezes ou reenviar a página não duplica** o atendimento.
4. **Prontuário** sempre com a data em que a consulta foi concluída.
5. **Ficha do paciente:** linha do tempo, tabela de consultas, "Último atendimento" e "Última consulta concluída" mostram quando a consulta aconteceu, com "(agendada para dd/mm)" quando for diferente. Isso também corrige a exibição das consultas antigas que tiveram o problema. Os registros antigos não são alterados.
6. Início antecipado **no mesmo dia** continua como antes (só a confirmação "Confirmo que quero iniciar antes do horário").

## Pacotes

| Arquivo | Uso |
|---|---|
| `clyonutri-v54-72-hostgator.zip` | Pacote completo. Mesmo procedimento das versões anteriores. |
| `clyonutri-v54-72-parcial-hostgator.zip` | Só os arquivos alterados sobre a **v54.71**. Use apenas se a produção estiver na v54.71. |

Confira o SHA-256 de cada zip com o arquivo `.sha256` ao lado.

## Passo a passo (gerenciador de arquivos do cPanel)

1. **Backup:** baixe a pasta do site e exporte o banco pelo phpMyAdmin.
2. Envie o zip para a pasta do site e extraia por cima. **Não apague nem substitua o `.env`** (o pacote não traz `.env`, só `.env.example`).
3. **Banco:** nenhum SQL obrigatório.
4. Abra `https://clyonutri.com/login` e confira no rodapé do console: **v54.72**.

### SQL opcional, somente leitura
`database/manual/ni2_879_consultas_concluidas_fora_do_dia_LEITURA.sql` lista as consultas concluídas num dia diferente do agendado (o efeito do defeito antes desta versão). Não altera nada. Rode no phpMyAdmin, aba SQL, e guarde o resultado se quiser registrar na NI2-879.

## Conferência rápida depois de subir (com um paciente TESTE)

1. Agende uma consulta TESTE para daqui a alguns dias.
2. Abra a consulta e clique em "Iniciar atendimento agora" **sem escolher opção**: a página pede a escolha e a consulta continua agendada.
3. Escolha "Criar o atendimento de hoje…" e inicie: abre um atendimento novo de hoje; o agendamento futuro continua na agenda.
4. Conclua com um texto qualquer: o prontuário sai "Evolução Clínica — Consulta #N (data de hoje)".
5. Entre com o Administrador: o console abre direto, sem loop (comportamento da v54.71 mantido).

## Se precisar voltar

Reenvie o pacote da v54.71 por cima. Não há mudança de banco a desfazer.

## Arquivos alterados em relação à v54.71

- `src/Domain/Appointment/AppointmentService.php`: regras de início fora do dia, conclusão, data efetiva e checagem de conflito
- `src/Application/Controllers/DashboardController.php`: início pela sala e "Último atendimento"
- `src/Application/View/ClinicalContext.php`: "Última consulta concluída"
- `src/Domain/Patient/PatientRepository.php`: linha do tempo e tabela de consultas
- `views/consultation/room.php`: opções, aviso de conflito e mensagens
- `views/patients/longitudinal.php`, `views/patients/_clinical_context.php`, `views/patients/_operational_strip.php`: data efetiva
- `public/assets/css/clinical-record.css`: estilo das opções (o parâmetro de versão do CSS mudou em `room.php`, `medical_record.php` e `anamnesis/form_page.php`)
- `src/Core/Release/BuildIdentity.php`, `RELEASE_INFO.json`, `config/build-manifest.json`: versão v54.72
- `database/manual/ni2_879_consultas_concluidas_fora_do_dia_LEITURA.sql`: novo, opcional, somente leitura

Fora do pacote (ficam no repositório): `bin/run-tests` com asserções ligadas e sentinela, `tests/ni2_879_consulta_outro_dia_test.php` e ajustes em `tests/v5467_ni2_856_aud360_test.php` e `tests/ni2_695_journeys.php`.
