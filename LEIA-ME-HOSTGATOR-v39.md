# ClyoNutri v39 · SQL da migração 60 corrigido (28/10/2026)

> **Nota (v40): a v40 não muda este SQL.** Os três arquivos `database/manual/atualizacao-v39-*.sql` seguem iguais, byte a byte, e valem como estão. Veja o `LEIA-ME-HOSTGATOR-v40.md`.

**SQL novo na v39: SIM, mas só para quem ainda não aplicou a migração 60.** O `atualizacao-v37-completa.sql` falhou em produção com **#1215 "Não pode acrescentar uma restrição de chave estrangeira"** no `CREATE TABLE subscription_plan_changes`. A v39 troca os três arquivos de SQL da v37 por três arquivos novos, `database/manual/atualizacao-v39-*.sql`, que criam as mesmas duas tabelas **sem chave estrangeira**. O código do sistema não muda (só a migração 60 e o instalador, para ficarem iguais ao SQL). O SQL **não foi rodado em produção** por mim.

A v39 é a **v38** (sha256 `06dd32abcc782236af74f23bb9d8e7abe764da334f76c670d389671b767fd97a`) mais essa correção. Tudo o mais é byte a byte o da v38 (veja `COMPARACAO-V38-V39.txt`). O pacote não traz `.env` nem chaves.

## O que aconteceu

- A v37 criava `subscription_plan_changes` com **quatro chaves estrangeiras**: para `clinics`, `clinic_subscriptions` e duas para `saas_plans`.
- **Causa confirmada com o dump da estrutura real do seu banco (28/10 18:56):** em produção **todos os `id` e `clinic_id` são `bigint(20)`** (`clinics`, `clinic_subscriptions`, `saas_plans`, `users`…, todas InnoDB, utf8mb4_unicode_ci), e o SQL da v37 declarava as colunas novas como **`INT`**. No MySQL, chave estrangeira exige tipo **idêntico** nos dois lados (`INT` não casa com `BIGINT`), então ele recusa com #1215. O banco do instalador/testes usa `INT`, por isso o erro não apareceu nos meus testes anteriores.
- **Reproduzido fielmente:** recriei no MySQL 5.7.44 uma cópia da **estrutura** do seu banco (106 tabelas, 172 chaves estrangeiras, sem nenhum dado pessoal) e rodei a v37 nela: o `completa` da v37 e também a migração PHP da v38 falham com o mesmo `ERROR 1215 (HY000): Cannot add foreign key constraint`, na mesma linha do `CREATE TABLE`. A v39 passa nessa mesma cópia.
- Pelo dump, **o banco continua como antes**: `subscription_plan_changes`, `account_deletion_requests` e a migração 60 **não existem** (a última migração registrada é a 59). O phpMyAdmin parou no primeiro erro e nada foi criado.
- A correção: **sem chave estrangeira não há tipo que precise bater** (as colunas novas ficam `INT`, que guarda até 2,1 bilhões, e os `id` reais são muito menores). O sistema não precisa delas (ele confere clínica, assinatura e plano em PHP antes de gravar, e nada no sistema apaga clínicas ou planos). Os índices de todas as colunas foram mantidos, então as consultas não ficam mais lentas.
- A tabela `account_deletion_requests` já era sem chave estrangeira. Agora as duas seguem a mesma regra: o histórico e o registro de pedidos de exclusão **ficam** mesmo que alguém apague uma clínica ou uma assinatura à mão no banco.

## Como rodar (phpMyAdmin, banco de produção, só depois do seu OK)

Como o `completa` da v37 parou **no primeiro `CREATE TABLE`**, o banco **não mudou** (confirmado no dump). **Não precisa desfazer nada.** Se uma das tabelas existir por algum motivo, o novo `completa` também trata (testado). Faça um backup novo antes, como sempre.

1. **`atualizacao-v39-preflight.sql`** (só lê). Resultado 1, coluna `situacao`:
   - esperado agora: **"OK — PRONTO PARA MIGRAR (migração 60)"** com `tabela_trocas_ja_existe = 0`, `tabela_exclusao_ja_existe = 0`, `migracao_59_registrada = 1`, `migracao_60_registrada = 0`;
   - "OK — ESTADO PARCIAL (só uma das duas tabelas existe)": pode seguir, o completa cria a que falta;
   - "OK — as duas tabelas já existem; o completa só registra a migração 60": pode seguir;
   - "FALHA — faltam tabelas base…" ou "ATENÇÃO — migração 59 (último login) não registrada…": **pare** e me mande o resultado.
   - Resultado 2 (informativo): engine, collation e tipo do `id` de `clinics`, `clinic_subscriptions` e `saas_plans`. Em produção deve mostrar `InnoDB`, `utf8mb4_unicode_ci` e `bigint(20)`. Não precisa me mandar, a menos que o resultado 1 não seja "OK".
2. **`atualizacao-v39-completa.sql`**. Esperado no final: uma linha com **`1`, `1` e `1`** (duas tabelas e migração 60 registrada). Pode rodar de novo sem efeito.
3. **`atualizacao-v39-verificacao.sql`**. Esperado: `situacao = OK — migração 60 aplicada`, tabelas `1` e `1`, migração `1`, colunas **18** e **22**, índices únicos **2** e **2**.

Se o `completa` der **qualquer** erro, pare, não rode de novo "no escuro" e me mande a mensagem inteira e o resultado do preflight.

Os três arquivos também estão soltos, fora do zip, para rodar já, **sem reinstalar o código**. O código da v38 abre normalmente sem o SQL (os botões de troca e de exclusão avisam que ainda não estão disponíveis) e passa a usar as tabelas assim que elas existem, sem reextrair nada.

## O que muda nos arquivos

- `database/manual/atualizacao-v39-preflight.sql`, `-completa.sql`, `-verificacao.sql`: novos. Os `atualizacao-v37-*.sql` **saíram do pacote** (o completa falhava).
- `database/migrations/2026_10_28_000060_create_plan_changes_and_account_deletion_requests.php`: sem chaves estrangeiras; cria os índices `idx_plan_changes_subscription`, `idx_plan_changes_from_plan` e `idx_plan_changes_to_plan` no lugar dos que as chaves criavam sozinhas. Mesmo nome e número da migração.
- `database/instalacao_mysql8.sql`: o mesmo, na tabela `subscription_plan_changes`.
- `LEIA-ME-HOSTGATOR-v37.md` e `-v38.md`: aviso no topo e nomes dos arquivos de SQL atualizados; a frase sobre exclusão em cascata do histórico deixou de valer.
- `tests/v37_plano_perfil_test.php` (não vai no zip): verificações novas (sem chave estrangeira, índices, instalador, SQL v39).

## Antes de subir os arquivos

1. **Backup** do banco e dos arquivos, como sempre.
2. **`.env`** e **`storage/`**: não mexa. O zip só traz `storage/.htaccess` e pastas vazias.
3. **Arquivos:** pelo Gerenciador de Arquivos, envie `clyonutri-v39.zip` para `/home3/maic6824/clyonutri.com`, use **Extract** (sobrescrever) e apague o zip do servidor. Limpe o cache e abra com Ctrl+F5. Como o código é o da v38, subir os arquivos é opcional se o seu objetivo era só destravar o SQL.

## Conferência depois do SQL (conta "TESTE")

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Titular de uma clínica **TESTE**: Configurações > Assinatura abre, a troca de plano registra um protocolo `TRC-…` e aparece no histórico; o botão de pedido de exclusão registra um protocolo e **nada é apagado**.
- Super admin: `/admin/comercial#trocas-de-plano` mostra a troca.

## Validação e limites

- SQL v39 testado em **MySQL 5.7.44**, primeiro numa **cópia da estrutura do seu banco real** (dump de 28/10): banco como está hoje; só `account_deletion_requests`; só `subscription_plan_changes`; as duas sem migração; só uma tabela com a migração 60 já registrada (caso de um cliente SQL que continua após erro); segunda execução sem efeito; e a migração PHP 60 nesse banco (antes: #1215; agora: ok). Depois, em bancos sintéticos: banco sem as tabelas; só `subscription_plan_changes`; só `account_deletion_requests`; as duas sem a migração registrada; tudo já aplicado (segunda execução sem efeito); e um banco com tabelas-base "hostis" (`clinics.id` BIGINT UNSIGNED, `saas_plans.id` INT UNSIGNED, `clinic_subscriptions` MyISAM), onde o SQL da v37 repete o #1215 e o da v39 funciona. A migração PHP 60 também foi rodada duas vezes nesse banco.
- **Não rodei nada no banco real de produção** (só na cópia da estrutura, sem dados). Dados reais do dump não foram copiados para relatórios.
- Sem chave estrangeira, o MySQL não impede mais apagar uma clínica que tenha histórico; o histórico fica órfão. Nenhuma tela do sistema apaga clínica, então isso só acontece por manutenção manual.
- Todos os limites da v37 e da v38 continuam valendo (sem gateway, texto jurídico da exclusão é rascunho, etc.).

## O que vem no zip

- `clyonutri-v39.zip`: o sistema inteiro com `vendor/` pronto. Arquivos 0644, pastas 0755. `clyonutri-v39.zip.sha256` ao lado.
- Na raiz: LEIA-ME v34 a v39, `COMPARACAO-V34-V35.txt` a `V38-V39`, `VALIDACAO-V35.txt`, `VALIDACAO-V36.txt`, `RELEASE_INFO.json`.
- Não vão no zip: testes, o app mobile, `.git`, `.env` e dados de `storage/`.

## Voltar atrás

Extraia por cima o `clyonutri-v38.zip` e limpe o cache. No banco, as duas tabelas são novas e vazias: não precisa desfazer nada (a v38 as ignora se existirem).
