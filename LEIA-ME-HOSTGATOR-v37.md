# ClyoNutri v37 · Plano e conta no perfil (28/10/2026)

> **ATENÇÃO (v39): os arquivos `atualizacao-v39-*.sql` citados abaixo foram SUBSTITUÍDOS pelos `atualizacao-v39-*.sql`.** O `atualizacao-v39-completa.sql` falhou em produção com o erro #1215 (chave estrangeira) e não vai mais no pacote. Use o caminho de SQL do `LEIA-ME-HOSTGATOR-v39.md`.


**SQL novo na v37: SIM, uma migração (a 60), com três arquivos.** São duas tabelas novas e vazias. Nada é alterado nem apagado nas tabelas que já existem. **Não rode o SQL antes do seu OK.** Sem o SQL, o sistema continua funcionando normalmente; só os botões novos de troca de plano e de exclusão da conta respondem "ainda não está disponível neste ambiente" (testado). O SQL das assinaturas faltantes da v32 também segue aguardando o seu OK, sem mudança.

A v37 é a **sua v36** (sha256 `4949fa630adb5ee287c030c23b8820417cdc37d4e8a33344768174ee2da3dc6c`, que já traz a v34 e a v35) mais o trabalho de **plano e conta no perfil da clínica**. Os arquivos da v36 que a v37 não toca são byte a byte os seus; a lista completa está em `COMPARACAO-V36-V37.txt`. O `SubscriptionService.php` da v36 **não foi editado**: a troca de plano é feita por uma classe nova que usa o serviço existente, então o seu ajuste da v36 e o trabalho novo convivem. O pacote não traz `.env` nem chaves.

## O que muda para a nutricionista (titular ou administradora da clínica)

Em **Configurações > Perfil** aparece o bloco **Plano e conta** e, em **Configurações > Assinatura**, as telas completas:

1. **Plano atual**, com o preço de referência, a situação (em teste, ativa, cancelada), a data de fim do período e o **uso contra o limite**: profissionais, pacientes ativos e armazenamento. Quem não é titular nem administradora (por exemplo, uma nutricionista da equipe) vê o resumo e **nenhum botão**. A recepção não abre o perfil, como antes.
2. **Trocar de plano** (upgrade ou downgrade). Cada plano mostra o preço, os limites e a diferença de referência.
3. **Cancelar o plano**: o acesso segue até o fim do período. Reaproveita o cancelamento que já existia (protocolo, motivo, não duplica ao reenviar).
4. **Pedir a exclusão da conta e dos dados** (LGPD). É um botão separado, de outra cor e em outra seção, para ninguém confundir com cancelar o plano.

### Troca de plano: como funciona

- **Não há gateway de pagamento.** A troca é **registrada**; a cobrança é combinada manualmente com a clínica. A tela diz isso com todas as letras e **não gera fatura nem boleto**. O super admin é avisado (aviso novo "Troca de plano feita pela clínica", com protocolo `TRC-AAAAMMDD-XXXXXX`).
- **Upgrade vale na hora.**
- **Downgrade também vale na hora, mas só se o uso atual couber nos limites do plano de destino** (profissionais, pacientes ativos e armazenamento). Se não couber, a troca é **barrada** e a tela explica o que excede ("O plano X inclui 1 profissional e a equipe tem 3 membros ativos. Desative 2 antes de trocar."). **Decisão tomada: imediato, e não no fim do período**, porque o sistema não tem ciclo de cobrança automático nem rotina para agendar a troca; agendar criaria uma promessa que ninguém executa. Se você preferir "no fim do período", é uma mudança à parte.
- A direção (upgrade ou downgrade) vem do **preço do catálogo**. Plano de mesmo preço é recusado. Assinatura cancelada não troca de plano. As datas do teste e do período **não mudam**; o preço de referência da assinatura passa a ser o do plano novo.
- A contagem de "profissionais" é a que o sistema já usava: **todos os membros ativos da clínica, inclusive a recepção**. Por isso um downgrade para o START (1 profissional) exige desativar a equipe além do titular. Isso já era assim na tela de assinatura; não foi mudado.
- **Histórico de trocas** (tabela `subscription_plan_changes`): protocolo, de/para, preço de referência, uso no momento, quem pediu, quando. A mesma chave de envio não duplica a troca.

### Cancelar o plano

Só titular ou administradora. Usa `SubscriptionService::cancelSubscription` sem mudança: o acesso continua até o fim do período, com protocolo e motivo. O texto antigo da tela de assinatura (que fala em cobrança no cartão, "sem fidelidade" e reembolso) **foi mantido como estava**; veja "O que ficou de fora".

### Pedir a exclusão da conta e dos dados (LGPD)

- **Nada é apagado, nunca, por esta tela e por nenhuma rotina.** O botão **registra um pedido** para o super admin tratar. Mesmo quando o super admin encerra o pedido, o sistema não apaga nada: a exclusão, se for feita, é manual e fora do sistema.
- Confirmação em três passos: a **senha** da pessoa, a frase **EXCLUIR MINHA CONTA** e a caixa "Li o aviso". Há limite de tentativas.
- O pedido recebe **protocolo `EXC-AAAAMMDD-XXXXXX`** e **prazo de 15 dias** (referência de resposta; confirme com o jurídico). Só há um pedido aberto por clínica.
- **A clínica pode retirar o pedido** enquanto ele estiver pendente ou em análise.
- Situações: pendente, em análise, encerrado (com registro de pelo menos 10 caracteres), retirado.
- O texto do aviso legal está marcado **"RASCUNHO PARA REVISÃO DE UM ADVOGADO"** na própria tela. Ele chama a atenção para a **guarda obrigatória de prontuários** (profissionais de saúde têm dever legal de guarda, e a LGPD admite a retenção por obrigação legal). **O texto e o prazo precisam de revisão jurídica antes de valerem como política.**
- Tabela própria `account_deletion_requests`, sem chave estrangeira para `clinics` de propósito: o registro do pedido (protocolo, datas, quem pediu) sobrevive se a clínica for removida um dia. A tabela `data_subject_requests` existente é por paciente e não serve para a clínica.

## O que muda para o super admin

- **Comercial** (`/admin/comercial`): duas seções novas, **Trocas de plano** (para quem tem `billing.read`: super admin e financeiro) e **Pedidos de exclusão de conta** (só super admin, `users.manage`). O super admin marca o pedido "em análise" e depois "encerra" com o registro do que foi feito. O financeiro **não vê nem altera** os pedidos de exclusão.
- **Clínicas** (`/admin/clinicas`): a clínica com pedido aberto ganha o selo "Pedido de exclusão em aberto" (ou "em análise").
- **Central de notificações:** três avisos novos (troca de plano, pedido de exclusão, pedido retirado), só para o super admin, como os outros. A lista de avisos e a tela de preferências já os incluem. Agora são 17 avisos.
- **Auditoria:** troca, cancelamento, pedido, retirada, análise, encerramento e tentativas com senha errada ficam em `audit_logs`.
- **Segurança:** CSRF em todas as ações, só POST, limite por clínica e usuário por hora (troca 10, cancelamento 5, pedido de exclusão 5, retirada 10), tudo isolado por clínica. Mensagens sem senha, e-mail ou dado clínico.

## Antes de subir

1. **Backup** do banco e dos arquivos, como sempre.
2. **Caminho do SQL** conforme a versão que está no ar (veja o LEIA-ME da sua versão atual, que segue no pacote):
   - **Da v36, v35 ou v34:** só o SQL da v37 (abaixo). A v34 a v36 não trazem SQL.
   - **Da v33 a v30:** o SQL das assinaturas faltantes (aguardando o seu OK, `database/manual/assinaturas-faltantes-*.sql`) e depois o da v37.
   - **Da v29 ou anterior:** siga o "Antes de subir" do `LEIA-ME-HOSTGATOR-v34.md` (SQL 56/57, 58 e 59) e depois o da v37.
3. **SQL da v37, no phpMyAdmin, no banco de produção, só depois do seu OK**, nesta ordem:
   1. `database/manual/atualizacao-v39-preflight.sql`: só lê. Deve dizer "OK — PRONTO PARA MIGRAR (migração 60)".
   2. `database/manual/atualizacao-v39-completa.sql`: cria `subscription_plan_changes` e `account_deletion_requests` e registra a migração 60. Pode rodar duas vezes (não duplica).
   3. `database/manual/atualizacao-v39-verificacao.sql`: deve mostrar as duas tabelas e a migração 60.
4. **Se subir os arquivos antes do SQL**, nada quebra (testado): o perfil e a assinatura abrem; trocar plano e pedir exclusão avisam que ainda não estão disponíveis; o plano não muda. Rode o SQL e passa a funcionar, sem reextrair.
5. **`.env`:** não mexa. **`storage/`:** não mexa. O zip não contém `.env` nem dado de `storage/`; só `storage/.htaccess` e pastas vazias, então a extração não apaga nada.
6. **Arquivos:** pelo Gerenciador de Arquivos, envie `clyonutri-v37.zip` para `/home3/maic6824/clyonutri.com`, use **Extract** (sobrescrever) e apague o zip do servidor. Limpe o cache e abra com Ctrl+F5.

## Conferência depois do deploy (só com contas "TESTE")

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Entre como titular de uma clínica **TESTE**: em Configurações > Perfil aparece o bloco **Plano e conta**, com o uso contra o limite. Em Configurações > Assinatura aparecem os planos, a frase de cobrança manual e o histórico.
- Faça um **upgrade** na clínica TESTE: mensagem com protocolo `TRC-…`, preço novo, aviso no sino do super admin e linha em `/admin/comercial#trocas-de-plano`. Tente um **downgrade** com a equipe acima do limite: o sistema barra e explica.
- Peça a **exclusão** na clínica TESTE (senha, frase e caixa): protocolo `EXC-…`, prazo de 15 dias, aviso ao super admin, pedido em `/admin/comercial#pedidos-conta`. **Confira que nada foi apagado.** Retire o pedido pela tela da clínica.
- Entre como nutricionista comum da clínica TESTE: vê o plano, sem botões. Entre como financeiro TESTE: vê as trocas, não os pedidos de exclusão.
- **Não use clínica real para testar a exclusão ou o cancelamento.**
- Biblioteca de receitas (v36): abra `/receitas` como usuário de teste; os seis exemplos aparecem e uma segunda visita não os repete.

## Validação e limites (leia)

- **Testes:** teste novo `tests/v37_plano_perfil_test.php` (135 verificações) e a suíte completa (119 arquivos). Falham os **mesmos arquivos que falham na sua v36 pura** (45 de 46; a diferença é um teste instável que passou). Nenhuma falha nova. Os detalhes e a explicação de cada falha herdada estão no relatório de entrega. Três testes antigos tiveram contagens fixas atualizadas (uma tabela que agora tem 17 avisos).
- **Teste de fumaça em modo produção, MySQL 5.7.44, dados sintéticos "TESTE": 78 verificações, 0 falhas**, com as 29 capturas de tela (desktop e celular). **Não houve homologação em produção.** Não houve teste de envio real de e-mail (sem SMTP de produção).
- **Sem gateway:** nenhuma cobrança é feita, cancelada ou estornada pelo sistema. A troca de plano só muda o registro e o preço de referência; **a cobrança manual é por sua conta**.
- **Limites de plano não são aplicados em lugar nenhum além da troca de plano.** Um plano START com 20 profissionais continua funcionando; só a troca de plano confere o uso. `canMutateClinicalData` segue sem uso.
- **Texto jurídico da exclusão é rascunho** e precisa de advogado (guarda de prontuário, prazos, base legal). O prazo de 15 dias é uma referência, não uma obrigação confirmada.
- O sistema **não apaga dados de nenhum pedido**; quem executa uma exclusão real é você, fora do sistema, com backup.
- O aviso de e-mail para a clínica (confirmação de troca ou de pedido) **não foi feito**; a confirmação é na tela.
- As telas antigas de assinatura ainda falam em cobrança no cartão, "sem fidelidade" e reembolso (CDC), o que não é verdade sem gateway. **Mantive como estava** e deixo a decisão de redação para você.
- A coluna "por <usuário>" em `/admin/clinicas` é o último login da clínica, não quem fez a troca; a autoria da troca está no histórico e na auditoria.
- Não há tela de detalhe de clínica; as seções novas ficam em Comercial.
- Na manutenção do banco: (corrigido na v39) as duas tabelas, `subscription_plan_changes` e `account_deletion_requests`, não têm chave estrangeira; se uma clínica for apagada à mão, o histórico dela e o registro de pedidos de exclusão **ficam** no banco.
- A instalação limpa (`database/instalacao_mysql8.sql`) foi atualizada à mão no mesmo estilo do arquivo; o portão MySQL do release (`fingerprints_identical`) já diferia na sua v36 pura (posição de `trial_ends_at`, `migrations.executed_at`) e segue assim; não é causado pela v37.
- `RELEASE_INFO.json` foi refeito (o da v36 ainda falava da v34).

## O que vem no zip

- `clyonutri-v37.zip`: o sistema inteiro com `vendor/` pronto (idêntico ao da v36). Não há `composer` no HostGator. Arquivos 0644, pastas 0755.
- `clyonutri-v37.zip.sha256`.
- Na raiz do zip, os documentos herdados: LEIA-ME v34, v35, v36 e este, `COMPARACAO-V34-V35.txt`, `COMPARACAO-V35-V36.txt`, `COMPARACAO-V36-V37.txt`, `VALIDACAO-V35.txt`, `VALIDACAO-V36.txt`, `RELEASE_INFO.json`.
- Não vão no zip: testes, o app mobile, `.git`, `.env` e dados de `storage/`.

## Voltar atrás

Extraia por cima o `clyonutri-v36.zip` e limpe o cache. Os blocos novos somem do perfil, do Comercial e da lista de clínicas. As duas tabelas novas podem ficar no banco sem efeito; os pedidos e as trocas gravados ficam guardados. **Não é preciso desfazer o SQL.** Se quiser mesmo assim, depois de voltar o código, e **só com a certeza de que não há pedido de exclusão que precise ser guardado**: `DROP TABLE account_deletion_requests; DROP TABLE subscription_plan_changes; DELETE FROM migrations WHERE migration = '2026_10_28_000060_create_plan_changes_and_account_deletion_requests';`. Isso apaga o histórico de trocas e os pedidos já registrados. Os avisos novos que já estiverem em `notification_events` (`recipient_type = 'staff'`) ficam sem efeito.
