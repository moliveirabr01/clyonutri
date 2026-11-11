# ClyoNutri v38 · Cabeçalho do plano sem a frase de cobrança (28/10/2026)

> **ATENÇÃO (v39): o SQL da v37 citado aqui falhou em produção (#1215) e foi substituído pelos três arquivos `database/manual/atualizacao-v39-*.sql`.** Veja o `LEIA-ME-HOSTGATOR-v39.md`.


**SQL novo na v38: NÃO.** A v38 não muda banco, migração nem SQL. O SQL da v37 (migração 60, os três arquivos `database/manual/atualizacao-v39-*.sql`) é **o mesmo, byte a byte**, e **continua aguardando o seu OK**; não foi rodado em produção. A v38 funciona com ou sem ele. O SQL das assinaturas faltantes da v32 também segue aguardando o seu OK, sem mudança.

A v38 é a **v37** (sha256 `c78d168177840d6733b56344cecfb7fddd3d431567a89e37e89ccfa586302d32`) com **uma única mudança de código**: no cabeçalho do bloco **Seu plano**, em **Configurações > Perfil**, saiu a frase **"A cobrança é combinada manualmente com a nossa equipe."** O resto do cabeçalho ficou igual: nome do plano, selo de situação, preço de referência, data, uso contra os limites e os três botões. Tudo o mais é byte a byte o da v37 (veja `COMPARACAO-V37-V38.txt`). O pacote não traz `.env` nem chaves.

## O que mudou na tela

Antes: "R$ 99,00 por mês de referência, período até 07/11/2026. A cobrança é combinada manualmente com a nossa equipe."
Agora: "R$ 99,00 por mês de referência, período até 07/11/2026."

Arquivo: `views/settings/_plano_resumo.php` (uma linha removida). Vale para os três estados do plano (em teste, ativo, cancelado). Quem não é titular nem administradora continua sem botões, como antes (esse caso nunca teve a frase).

## Onde a explicação de cobrança manual AINDA aparece (mantida de propósito)

Você pediu para tirar só do cabeçalho, então **não mexi nestes pontos**. Se quiser tirar algum, é um pedido à parte:

1. **Tela de assinatura**, `/configuracoes/assinatura`, cartão **"Como fica a cobrança"** (`views/settings/_plano_trocar.php`, linha 21): "Ainda não existe cobrança automática no ClyoNutri. Ao trocar de plano, a mudança fica registrada aqui com um número de protocolo e a nossa equipe entra em contato para combinar a diferença de valor. O sistema não gera fatura nem cobra nada sozinho."
2. **Tela de assinatura**, em cada plano disponível: "Acréscimo de referência de R$ X por mês, combinado com a equipe." (`_plano_trocar.php`, linha 78).
3. **Confirmação antes da troca** (janela do botão "Fazer upgrade/downgrade"): "... A troca vale na hora e a cobrança será combinada com a nossa equipe." (`_plano_trocar.php`, linha 82).
4. **Mensagem depois da troca** (resposta na tela e aviso): "... A cobrança é combinada manualmente: a nossa equipe entra em contato para acertar a diferença. Protocolo TRC-…" (`src/Domain/Subscription/PlanChangeService.php`, linha 358).
5. **Super admin**, `/admin/comercial#trocas-de-plano`: "A troca já vale para o consultório. A cobrança é manual: entre em contato para acertar a diferença de valor. Não há fatura gerada pelo sistema." (`views/admin/_comercial_trocas_pedidos.php`, linha 19).
6. Comentários de código e o `LEIA-ME-HOSTGATOR-v37.md` (não aparecem para ninguém no sistema).

Um ponto de atenção: com a frase fora do cabeçalho, quem só abre o Perfil não vê mais o aviso de que não há cobrança automática; ele só aparece ao abrir a tela de assinatura ou trocar de plano.

## Antes de subir

1. **Backup** do banco e dos arquivos, como sempre.
2. **SQL: nada novo.** Se a v37 já está no ar, não há SQL a rodar por causa da v38. Se estiver numa versão anterior, o caminho de SQL é o do `LEIA-ME-HOSTGATOR-v37.md` (SQL da v37, só depois do seu OK; a v38 abre normalmente sem ele, e os botões de troca e de exclusão avisam que ainda não estão disponíveis).
3. **`.env`** e **`storage/`**: não mexa. O zip só traz `storage/.htaccess` e pastas vazias.
4. **Arquivos:** pelo Gerenciador de Arquivos, envie `clyonutri-v38.zip` para `/home3/maic6824/clyonutri.com`, use **Extract** (sobrescrever) e apague o zip do servidor. Limpe o cache e abra com Ctrl+F5.

## Conferência depois do deploy (conta "TESTE")

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Entre como titular de uma clínica **TESTE** e abra Configurações > Perfil: o bloco **Seu plano** mostra o preço e a data **sem** a frase de cobrança manual, com o uso contra o limite e os três botões.
- Abra Configurações > Assinatura: o cartão **Como fica a cobrança** continua lá.

## Validação e limites

- **Teste novo** `tests/v38_cabecalho_plano_sem_cobranca_test.php` (23 verificações): o cabeçalho não tem a frase nos três estados, mantém preço, data, uso e botões, e os outros pontos acima continuam com o texto.
- **Suíte completa** (120 arquivos): as **mesmas 45 falhas herdadas** da v37; nenhuma nova. `dashboard_review_fixes_test` é instável (falhou na rodada completa, passou isolado duas vezes). Nenhum teste existente checava a frase do cabeçalho, então nenhum teste antigo precisou mudar.
- **Teste de fumaça** em modo produção, MySQL 5.7.44, dados sintéticos "TESTE", com as capturas do cabeçalho antes e depois (desktop e celular). **Não houve homologação em produção.**
- Todos os limites da v37 continuam valendo (sem gateway, limites de plano só conferidos na troca, texto jurídico da exclusão é rascunho, etc.); veja o `LEIA-ME-HOSTGATOR-v37.md`.

## O que vem no zip

- `clyonutri-v38.zip`: o sistema inteiro com `vendor/` pronto (idêntico ao da v37). Arquivos 0644, pastas 0755. `clyonutri-v38.zip.sha256` ao lado.
- Na raiz: LEIA-ME v34 a v38, `COMPARACAO-V34-V35.txt`, `V35-V36`, `V36-V37` e `V37-V38`, `VALIDACAO-V35.txt`, `VALIDACAO-V36.txt`, `RELEASE_INFO.json`.
- Não vão no zip: testes, o app mobile, `.git`, `.env` e dados de `storage/`.

## Voltar atrás

Extraia por cima o `clyonutri-v37.zip` e limpe o cache. Não há nada a desfazer no banco.
