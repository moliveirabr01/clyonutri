# Clyo Nutri v54.15: NI2-635, uso da anamnese adaptativa (desligada)

- **Pacote:** `clyonutri-v54-15-hostgator.zip`
- **Data:** 03/11/2026
- **Base:** v54.14 (inclui a v54.14; a produção está na v54.13).
- **Banco:** sem mudança. Usa as tabelas da migração 78, que já rodou na v54.12. **Não há SQL para rodar.**

## Importante: nada muda para os usuários ao instalar
Tudo desta versão fica atrás da chave global `feature_flag_clinical_protocols`. Ela **continua 0** e não deve ser ligada agora.

**Com a chave desligada:**
- a anamnese não mostra o bloco novo;
- a aba "Protocolos clínicos" não aparece nas configurações da clínica;
- os endereços novos da clínica respondem 404;
- só o console da plataforma vê o catálogo, vazio.

**O pacote não traz nenhum protocolo clínico.** Os testes usam só protocolos de TESTE, sem conteúdo clínico. Pré-requisitos para ligar a chave:
1. As perguntas de cada protocolo foram escritas e curadas, e aprovadas formalmente por nutricionista habilitado(a).
2. A exportação LGPD foi conferida.
3. O teste com usuários foi feito.

## Governança do catálogo (console da plataforma)
**Menu Protocolos clínicos** (`/admin/protocolos`, permissão `settings.manage`):
- **Registrar:** código, nome, tipo (condição ou perfil não-diagnóstico), fase e se exige revisão especializada.
- **Rascunho:** definição em JSON com prévia das perguntas e referências clínicas. Definição inválida é recusada com o motivo, e o texto digitado volta no formulário.
- **Enviar para revisão:** a versão deixa de ser editável.

**Aprovar (quatro olhos):** quem escreveu a versão não a aprova; o servidor recusa mesmo um envio forçado. A aprovação exige:
- nome do(a) nutricionista;
- CRN com região (ex.: `CRN-3 12345`);
- data de revisão em até 24 meses;
- parecer especializado, na fase C;
- referências preenchidas;
- marcação "Revisei o conteúdo clínico".

Aprovar aposenta a versão anterior.

**Aposentar** com motivo: a versão deixa de aparecer, e as respostas antigas ficam guardadas.

**Trilha:** criada, enviada, aprovada e aposentada ficam na lista de eventos.

**Configurações globais:** linha nova "Anamnese adaptativa por protocolos clínicos (NI2-635)", com a chave.

## Protocolo por clínica
Com a chave ligada, **Configurações › Protocolos clínicos** lista os protocolos aprovados. Só o titular ou um nutricionista gestor liga ou desliga cada protocolo na clínica. Desligar guarda as respostas já gravadas.

## NI2-639/640: condições do paciente
Fica no fim da página da anamnese, no bloco **"Anamnese adaptativa: condições e módulos"**.

**Sugestões a partir do texto:** o botão "Procurar sugestões no texto da anamnese vigente" lê a queixa, as patologias, as observações e os sintomas da versão concluída. Ele **só sugere**:
- a sugestão não é diagnóstico e não ativa nada;
- o profissional confirma com status (relatada, em investigação, documentada) e origem (autorrelato, pré-consulta, avaliação, laudo);
- ou descarta, com motivo.

**Registro direto:** protocolo, status, origem, evidência e validade.

**Regras:**
- "Documentada" exige laudo ou avaliação profissional.
- Condição vencida não ativa módulo.
- Encerrar exige motivo, que fica no histórico.

**Quem mexe:** só nutricionista da clínica, com CRN cadastrado. Outra clínica não vê nem altera.

## NI2-642/652: módulos na anamnese
Cada condição confirmada abre o módulo correspondente, na versão aprovada vigente.

- **Perguntas:** organizadas por seção. Pergunta usada por mais de um módulo aparece uma vez, com "Usada por: …".
- **Perguntas condicionais:** aparecem conforme as respostas.
- **Progresso:** "x de y obrigatórias" por módulo.

**Salvar rascunho:** não substitui a última versão concluída, e a tela avisa que há rascunho.

**Concluir módulos:** exige todas as obrigatórias e nenhum erro. Se faltar algo:
- a tela diz o que falta;
- o que foi digitado volta no formulário.

Cada gravação é uma linha nova; nada é sobrescrito.

**Sinais de alerta:** aparecem como alerta, por exemplo "Avaliação urgente: …". Eles **encaminham** e não indicam conduta.

**Layout:** funciona em 375px sem rolagem lateral.

## NI2-655: LGPD dos dados de protocolo
- **Exportação do paciente:** inclui as condições, o histórico de cada condição e as respostas dos módulos.
- **Anonimização:** apaga as notas do histórico e as evidências em texto livre das condições.
- **Leitura auditada:** abrir o bloco gera `PROTOCOL_DATA_VIEWED` só com o paciente, os módulos e a quantidade de condições, sem conteúdo clínico.
- **Também auditados:** ligar ou desligar um protocolo na clínica (`CLINIC_PROTOCOL_TOGGLED`) e cada passo do catálogo.

## Verificação (fora do zip)
- **Portão:** `tests/run_release_gate.sh`. Teste novo `v5415_ni2_635_uso_test.php` (17/17) e jornada nova `p5418` (35 verificações). A jornada cobre:
  - chave desligada: 404 e nada gravado;
  - catálogo com autora e revisora diferentes; recusas da autoaprovação e do CRN sem região;
  - chave global ligada pelo console; protocolo ligado pela clínica;
  - sugestão → confirmação → módulo;
  - conclusão recusada e depois aceita; rascunho; alerta;
  - outra clínica bloqueada; 375px; sem erro de JavaScript ou de CSP.
- **MariaDB 10.11 local** (`tests/mysql/ni2_635_mysql_smoke.php`), com as consultas novas: bloco da anamnese, exportação e anonimização LGPD e listagens do console.

## Arquivos
**Novos:**
- `src/Application/Controllers/ClinicalProtocolController.php`
- `views/admin/protocolos.php`
- `views/admin/protocolo.php`
- `views/settings/protocols.php`
- `views/patients/_adaptive_anamnesis.php`

**Alterados:**
- `src/Application/Controllers/AdminController.php`
- `src/Application/Controllers/DashboardController.php` (bloco na anamnese)
- `src/Domain/Privacy/LgpdService.php`
- `public/index.php` (rotas)
- `views/layouts/admin-shell.php`
- `views/admin/configuracoes.php`
- `views/settings/_tabs.php`
- `views/layouts/professional-shell.php` (v54.15)
- `public/assets/css/clinical-journeys.css`

## Instalação
1. Envie o zip pelo Gerenciador de Arquivos e extraia sobre a pasta atual.
2. Não há SQL.
3. Confira o rodapé: **v54.15**.
4. Confira em Configurações globais que a chave "Anamnese adaptativa por protocolos clínicos" está **Suspensa**.
