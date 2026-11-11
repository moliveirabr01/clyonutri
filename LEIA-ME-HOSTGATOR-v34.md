# ClyoNutri · Pacote v34 (27/10/2026)

**SQL novo na v34: NÃO.** A v34 não traz migração nem arquivo SQL novo. A central de notificações da equipe usa as tabelas que já existem desde a migração 52 (`notification_events`, `notification_deliveries`, `notification_preferences`), com `recipient_type = 'staff'`. A última migração continua sendo a 59. **O SQL das assinaturas faltantes da v32 continua valendo e continua aguardando o seu OK** (os três arquivos `assinaturas-faltantes-*.sql` seguem em `database/manual/`, sem mudança). Não rode nada de SQL por causa da v34.

A v34 é a v33 completa mais a **central de notificações do backoffice**: o super admin passa a ser avisado, dentro do console, de cada clínica nova, teste grátis, chamado, ação sobre usuário e conta travada, com um sino no topo e a tela `/admin/notificacoes`. O resto do sistema é byte a byte o da v33 (a comparação arquivo por arquivo está em `COMPARACAO-V33-V34.txt`). Origem do código: o conteúdo do `clyonutri-v33.zip` (sha256 `d505c22ec60bb0df3fe3344d758b8b17efc3c84fec1b580d925716cdf6a3ff7f`). O pacote não traz `.env` nem chaves.

Serve por cima da v33 e das demais versões listadas em "Antes de subir" (com o SQL de estrutura de cada caminho, quando houver).

## O que a v34 acrescenta: central de notificações da equipe

### O que já existia e o que é novo

- **Já existia:** a infraestrutura de avisos da NI2-95 (tabelas, fila de e-mail com reenvio, preferências), usada só para pacientes e clínicas, e o cron de um minuto que a processa.
- **Novo:** a regra de "quem da plataforma é avisado de quê", o sino e as telas, as preferências por tipo da equipe, e a geração dos avisos no ponto em que cada coisa acontece. Nenhuma tabela nem cron novo.

### Quem é avisado

**Só os super admins ativos**, por padrão, em todos os tipos. Cada tipo tem também uma permissão mínima de leitura (por exemplo, o de clínica nova exige `clinics.read`), conferida na hora de gerar e na hora de abrir. Suporte, financeiro, auditor, operador e administrador de operação **não recebem aviso nenhum** hoje; para abrir um tipo a outro perfil basta mudar uma linha do catálogo em `StaffNotificationService::EVENTS` (decisão sua, não feita). Quem faz uma ação (por exemplo, suspender um usuário) **não recebe o próprio aviso**; o outro super admin recebe.

### Os 14 avisos (o que gera cada um)

| Aviso | Quando nasce | Link |
|---|---|---|
| Nova clínica e novo profissional cadastrados | cadastro público de clínica | `/admin/clinicas` filtrado pelo nome |
| Teste grátis iniciado | assinatura de teste criada | `/admin/comercial#assinantes` |
| Teste grátis perto de acabar (3 dias) | varredura, 3 dias antes do fim | idem |
| Teste grátis encerrado | varredura, depois do fim sem virar assinatura (janela de 3 dias para trás) | idem |
| Assinatura cancelada | cancelamento pela clínica | idem |
| Novo chamado no Service Desk | chamado aberto (só protocolo, tipo, prioridade e categoria; **nunca assunto nem descrição**) | `/admin/servicedesk` |
| Novo usuário da equipe criado / Convite aceito | criação do convite / definição da senha | ficha do usuário |
| Usuário suspenso, reativado, bloqueado, liberado | ação do backoffice (o autor não recebe) | ficha do usuário |
| Senha redefinida pelo backoffice | redefinição pelo backoffice | ficha do usuário |
| Conta da equipe travada por tentativas de entrada | limite de erros de senha numa conta **da equipe** (um aviso por travamento) | ficha do usuário |

Cada aviso é gerado **depois** que a ação principal terminou e dentro de um bloqueio que engole qualquer erro: **uma falha do aviso nunca derruba o cadastro nem a ação** (testado com um banco que falha de propósito). Como rede de segurança, a varredura (`sweep`) refaz os avisos de cadastros e chamados recentes que tenham ficado sem aviso; ela roda no cron de um minuto que já existe e, sob demanda, ao abrir a central (no máximo uma vez a cada 10 minutos; marcador em `storage/staff_notifications_last_sweep`). Avisos repetidos não são criados (chave de unicidade por destinatário). **Atenção na primeira subida:** a primeira varredura gera os avisos de testes que acabaram nos últimos 3 dias e dos que acabam nos próximos 3.

### Sino, lista e preferências

- **Sino** no topo do console, com o número de não lidos (99+ no máximo). Uma consulta por página; não há atualização automática nem JavaScript novo.
- **`/admin/notificacoes`**: lista paginada (20 por página, como as outras do backoffice), filtro por tipo e por lidas/não lidas, botão **Abrir** (marca como lido e leva ao recurso, só para destinos permitidos ao perfil) e **Marcar todas como lidas**. Tudo que muda dado é POST com token CSRF, e cada pessoa só mexe nos próprios avisos.
- **`/admin/notificacoes/preferencias`**: liga e desliga cada tipo (padrão: tudo ligado). Desligar significa que o aviso **deixa de ser criado** para você; o outro super admin continua recebendo.
- **"Também por e-mail":** só funciona com SMTP configurado (veja a seção de e-mail abaixo). Sem SMTP a caixa fica **desabilitada**, a tela explica isso e **não promete envio**; qualquer valor gravado antes é ignorado. Com SMTP, o aviso sai pela fila existente, com reenvio (3 tentativas) e só se continuar não lido e com o destinatário ativo. O e-mail é curto e **genérico**: título fixo e, se `APP_URL` estiver definido, um link para `/admin/notificacoes`. **Não leva nome, e-mail, clínica nem motivo.** Nunca há push para a equipe.

### O que aparece no aviso (e o que não aparece)

Nome da clínica, nome e e-mail do profissional que se cadastrou, nome e perfil de quem foi alvo de uma ação, protocolo do chamado. O motivo digitado numa suspensão, bloqueio ou redefinição aparece no aviso para o **outro super admin** (a trilha de auditoria já o guarda). **Nunca** aparecem: dado clínico, senha, link ou código de convite ou de redefinição, assunto ou descrição de chamado, a senha tentada num login. Tudo é escapado na tela.

### Retenção

Aviso **lido há mais de 90 dias** é removido por uma limpeza diária feita pelo mesmo cron (marcador `storage/staff_notifications_retention_last_run`). Aviso **não lido nunca** é removido. A **auditoria (`audit_logs`) nunca é tocada**. Avisos de pacientes e clínicas não são afetados.

### O que ficou de fora, e por quê

- **Troca de plano:** não existe fluxo nem gateway que a faça no sistema; só há o cancelamento (que gera aviso). Quando existir a troca, o aviso entra no catálogo.
- **Eventos de pagamento** (falha, atraso): não há integração de cobrança.
- **Falhas de login de usuários comuns de clínica:** não geram aviso (qualquer pessoa poderia encher a central de lixo digitando e-mails). Só conta **da equipe** travada avisa.
- **Reenviar convite e encerrar sessões:** não geram aviso nesta versão.

### Voltar da v34 para a v33
Sem SQL. Extraia por cima o `clyonutri-v33.zip` e limpe o cache. O sino e `/admin/notificacoes` somem; os avisos já gravados ficam na tabela `notification_events` (`recipient_type = 'staff'`), sem efeito, e voltam a aparecer se você subir a v34 de novo. Nada de dados de clínica foi alterado.

## Herdado da v33: gestão de usuários (continua valendo)

### Quem pode fazer o quê

Há uma permissão nova, `users.manage`, dada **só ao super admin**. Ela libera: criar usuário da equipe, redefinir senha, bloquear, liberar bloqueio e reenviar convite. As permissões que já existiam continuam: suspender e reativar (super admin, administrador de operação e operador) e encerrar sessões (`sessions.revoke`). Suporte e auditor de segurança **só leem** a lista e a ficha (o financeiro não tem acesso a usuários, como antes); os botões de ação nem aparecem para eles e o servidor também nega.

| Ação | Super admin | Administrador de operação | Operador | Suporte, auditor, financeiro |
|---|---|---|---|---|
| Ver lista e ficha | sim | sim | sim | suporte e auditor sim; financeiro não |
| Suspender e reativar | sim | sim | sim | não |
| Encerrar sessões | sim | sim | sim | não |
| Redefinir senha, bloquear, liberar, criar usuário da equipe | sim | **não** | **não** | não |
| Histórico administrativo na ficha (exige `audit.read`) | sim | sim | sim | só o auditor |

### Onde fica

- `/admin/usuarios`: a lista ganhou os filtros de **perfil** e de **status** (Ativo, Suspenso, Bloqueado, Convite pendente), quatro cartões de status, selos coloridos, o "Último login" (como na v30) e a paginação de antes. O botão "Novo usuário da equipe" aparece só para quem pode criar. Cada linha tem um botão **Gerenciar** que abre a ficha.
- `/admin/usuarios/{id}`: a ficha do usuário, com os dados, as ações permitidas para o seu perfil e o **Histórico administrativo** (quando, ação, quem fez, motivo, IP).
- `/admin/usuarios/novo`: criação de usuário da equipe.

Os botões de suspender/reativar que ficavam soltos na lista foram trocados por "Gerenciar" (mudança de comportamento, ver abaixo).

### Regras de cada ação

**Todas** exigem motivo (5 a 300 caracteres), uma tela de confirmação com o que vai acontecer, uma caixa "Entendi" marcada e um POST com token CSRF. Nenhuma ação roda por link (GET); o GET só mostra a tela de confirmação. Cada ação vale só para o login daquela pessoa. Pacientes, prontuários e dados da clínica não são tocados e **nenhum usuário é apagado**.

- **Suspender / Reativar.** Pausa administrativa. Suspender encerra as sessões e os acessos de app na hora; a senha continua a mesma e **reativar é direto** (a pessoa volta com a senha de antes). 
- **Bloquear.** Corte de segurança, **distinto de suspenso**. Corta o login e as sessões e os acessos de app na hora. O bloqueado **não consegue se liberar sozinho**: nem o "Esqueci minha senha" funciona para ele. **Só o super admin libera.** Liberar devolve a conta ao estado ativo, **invalida a senha antiga** e gera um link de uso único (60 minutos) para a pessoa criar uma senha nova. Ou seja: para voltar é preciso trocar a senha.
- **Redefinir senha.** Só para usuário ativo (suspenso: reative antes; bloqueado: use Liberar; convite pendente: reenvie o convite). Invalida a senha atual, encerra todas as sessões e acessos de app, invalida os links antigos e gera um link novo de uso único, válido por 60 minutos.
- **Encerrar sessões.** Já existia; agora também exige motivo e confirmação, recusa a própria conta e contas de perfil igual ou superior (exceto super admin sobre outro super admin), e também remove os acessos de app (tokens) da pessoa.
- **Criar usuário da equipe.** Nome, e-mail e perfil. Perfis oferecidos: Administrador de operação, Operador, Suporte, Financeiro e Auditor de segurança (cada um com a descrição do que faz). O e-mail precisa ser único. **Ninguém digita senha:** a conta nasce como "Convite pendente" e a pessoa cria a própria senha por um link de uso único válido por 72 horas. Reenviar o convite gera um link novo e derruba o anterior.

### Senha e links (como é guardado)

- **A senha nunca aparece nem é guardada em texto puro**, nem pelo admin, nem no banco, nem no log. O link tem um código aleatório; no banco fica **só o hash (SHA-256)** do código, na tabela `password_resets` que já existia. Cada link vale uma vez e expira (60 minutos no reset e na liberação, 72 horas no convite). Gerar um novo link invalida os anteriores.
- **Entrega do link.** Se o servidor de e-mail (SMTP) estiver configurado e entregar, o link vai por e-mail e **não é mostrado**. Se o e-mail não sair (ou não houver SMTP), o link aparece **uma única vez**, na tela logo depois da ação, para o super admin copiar e enviar por um canal seguro. Essa tela não é guardada em cache e não repassa o endereço (`no-store`, `no-referrer`, `noindex`). Se o admin sair da tela, **não dá para ver de novo**: repita a ação para gerar outro link. O link **não vai** para auditoria nem para log de erro. Os modos de e-mail `log` e `null` nunca são usados para links (senão o link ficaria num arquivo de log): com eles o link é mostrado na tela.
- **Senha forte.** Ao definir a senha, a equipe precisa de pelo menos 12 caracteres, com letras e também número ou símbolo, sem a parte do e-mail antes do `@` e sem um caractere repetido. Usuários de clínica continuam com o mínimo de 8 de antes.
- **E-mail em produção.** Não há SMTP de produção provado (NI2-463/NI2-481), então **a entrega por e-mail desses links não foi comprovada**. Na prática, hoje o link aparece na tela uma vez. Quando o SMTP do Titan estiver configurado (seção abaixo), passa a sair por e-mail.

### Proteções

- Ninguém bloqueia, suspende ou redefine a **própria** conta por aqui.
- **O último super admin ativo não pode ser suspenso nem bloqueado.**
- Cada pessoa só gerencia perfis **estritamente abaixo** do seu (administrador de operação não age sobre outro administrador de operação nem sobre operador acima dele, e ninguém age sobre super admin, exceto outro super admin). **Decisão a confirmar:** um super admin **pode** suspender, bloquear e redefinir a senha de **outro** super admin (nunca a própria), porque senão uma conta de super admin comprometida não teria como ser contida pela tela. O risco é um super admin agir contra o outro; tudo fica na auditoria.
- Não existe exclusão de usuário.
- **Super admin não é oferecido no formulário de criação**: criar super admin pela tela seria um caminho de escalada de privilégio. Continua sendo criado só no servidor, com `bin/create_superadmin.php`.
- Limite de frequência: 40 ações por operador a cada 10 minutos e 5 links por usuário por hora. Todas as ações exigem CSRF. São ações da plataforma: não mexem em nada da clínica do usuário (vínculo com a clínica, pacientes e prontuários ficam como estão) e a auditoria guarda a clínica de quem executou.
- **Mensagem de login para suspenso ou bloqueado.** É a mesma para os dois, sem dizer qual: "Seu acesso está indisponível no momento. Fale com o suporte da ClyoNutri para saber como regularizar." Ela só aparece quando a **senha está correta**; com senha errada a resposta continua "E-mail ou senha incorretos.", então ninguém descobre o estado de uma conta que não é sua. O link de "Esqueci minha senha" não funciona para suspenso nem bloqueado.

### Auditoria

Cada ação grava em `audit_logs`: quem fez, quem sofreu, a ação, o motivo, o IP e o estado antes e depois, **sem senha, link nem código**. O histórico aparece na ficha do usuário. **Atenção:** a trilha é só de acréscimo **pela aplicação** (o código não edita nem apaga linhas); **o banco não impede** um UPDATE/DELETE feito direto por quem tem acesso ao MySQL. Não trate como imutável no nível do banco. A tela do menu continua chamada "Auditoria imutável" (nome que já existia).

### Aceite dos Termos e a equipe nova (leia antes de ligar `LEGAL_REACCEPTANCE_ENABLED`)

`LEGAL_REACCEPTANCE_ENABLED` **continua desligado e a v33 não liga**. A equipe criada por aqui (os cinco perfis acima) **não é desviada** para `/aceite-termos`, mesmo com a opção ligada: o desvio ignora perfis da plataforma. Por outro lado, a página `/aceite-termos` responde **403** para a equipe, então **nenhum aceite de Termos é registrado para esses usuários**. Se o jurídico exigir aceite da equipe, isso hoje não existe e é decisão a tomar. A pendência da recepcionista (ela é desviada e fica sem acesso com a opção ligada) **continua como na v27 a v32**.

### Mudanças de comportamento (para quem já usava)

1. O botão de suspender/reativar da lista foi trocado por **Gerenciar**; suspender/reativar agora pedem motivo e confirmação em duas etapas.
2. A rota antiga `POST /admin/usuarios/{id}/status` **não altera mais nada** (redireciona para a ficha). Não use favoritos nem scripts antigos que a chamavam.
3. **Encerrar sessões** agora recusa a própria conta e perfis iguais ou superiores (exceto super admin sobre outro super admin), pede motivo e também remove os acessos de app.
4. O filtro de status da lista aceita também "Bloqueado" e "Convite pendente".
5. Quem não tem permissão de uma ação continua sendo mandado ao painel com um aviso (comportamento que já existia) e o acesso negado fica na auditoria.
6. Coluna "Membros" de `/admin/clinicas`: **não foi tocada** (continua mostrando 0; ver pendências).

### Voltar da v33 para a v32 (herdado)
Sem SQL. Extraia por cima o `clyonutri-v32.zip`. Usuários "Bloqueado" ou "Convite pendente" criados na v33 **continuam sem conseguir entrar na v32** (o login da v32 só aceita status `active`), então voltar não reabre acesso de ninguém. Para reativar alguém depois de voltar, é preciso trocar o status direto no banco, com cuidado.

## SQL das assinaturas faltantes (veio na v32, **segue aguardando o seu OK**)

**O problema.** Clínicas criadas antes da regra que dá o teste de 14 dias no cadastro ficaram sem linha em `clinic_subscriptions`. No Comercial e em `/admin/clinicas` elas aparecem como "Sem assinatura" e não entram na tabela "Assinantes e planos". O sistema só cria o teste dessas clínicas se alguém entrar em Configurações > Assinatura, e a partir daí conta 14 dias de **aquele dia**.

**O que o SQL faz.** Cria **uma** assinatura de teste para cada clínica **ativa** que **não tem nenhuma**:

- Plano de teste padrão do sistema (`PRO-02`), com preço e ciclo copiados do catálogo (`saas_plans`), igual ao que o cadastro faz.
- Não cria fatura, não cobra e não manda e-mail.
- Clínica que já tem assinatura, de qualquer situação (ativa, em teste, expirada ou cancelada), **não é tocada**. O arquivo não tem `UPDATE` nem `DELETE`.
- Clínica suspensa não recebe. Se for reativada, o sistema cria o teste sozinho na primeira visita a Configurações > Assinatura.
- Se o plano `PRO-02` não existir no catálogo, o SQL não insere nada e mostra "FALHA — o plano PRO-02 não existe".
- Pode ser repetido: a segunda execução insere 0 linhas e não muda nenhuma.
- Fica só no SQL manual. **Não há migração nova** (a última continua sendo a 59), de propósito: o `bin/migrate.php` aplicaria uma correção de dados sozinho, no deploy, sem você decidir a data-base.

**Decida a data-base antes de rodar.** O arquivo tem uma linha `SET @modo = 'data_criacao';` no começo. Dois jeitos:

| Modo | O que acontece | Quando escolher |
|---|---|---|
| `data_criacao` (padrão) | O teste de 14 dias conta da **criação da clínica**. Se o prazo ainda não acabou, a clínica fica **Em teste** (com os dias que restam); se já passou, fica **Teste expirado**. A RL NUTRIÇÃO (criada em 19/10/2026) ficaria Em teste até 02/11/2026. | Você quer que o painel reflita a realidade: quem criou há mais de 14 dias aparece como expirado. |
| `hoje` | Todas as clínicas sem assinatura ganham **14 dias a partir do momento em que o SQL roda**, todas como Em teste. | Você prefere dar uma folga a clientes antigos e só começar a contar agora. |

Para trocar, edite essa linha (`'data_criacao'` por `'hoje'`) **nos dois arquivos**, preflight e completa, antes de rodar. O preflight mostra, clínica por clínica, o resultado dos dois modos lado a lado.

**O que "teste expirado" faz hoje no sistema (conferido no código).** Nada bloqueia. A função `canMutateClinicalData`, que bloquearia novos cadastros de quem está expirado ou cancelado, **existe, mas nenhum ponto do sistema a chama**. A tela de Configurações > Assinatura mostra um aviso vermelho ("Seu período de teste de 14 dias terminou") e diz que o uso continua normal, e o cron de expiração só marca como expirado quem está em teste com prazo vencido. Hoje, portanto, o único efeito de uma clínica antiga ficar "Teste expirado" é esse aviso e o selo no painel. **O risco:** se uma versão futura ligar `canMutateClinicalData`, as clínicas expiradas deixariam de poder criar pacientes e consultas. Quem não tem linha nenhuma continua liberado. Se isso preocupa, use o modo `hoje`.

**Efeito no painel:** quem ficar Em teste aparece no filtro "Em teste" e passa a ter plano em `/admin/clinicas`. O cartão "Assinaturas ativas" e o MRR **não mudam**, porque teste e expirada não contam.

**Ordem dos três arquivos** (todos em `database/manual/`, no phpMyAdmin, no banco de produção):

1. `assinaturas-faltantes-preflight.sql`: só leitura. Mostra quantas clínicas ativas estão sem assinatura, qual é o plano, se ele existe e a prévia clínica a clínica. Siga só se a situação mostrar "OK".
2. `assinaturas-faltantes-completa.sql`: executa. No fim mostra quantas assinaturas criou e a contagem por situação.
3. `assinaturas-faltantes-verificacao.sql`: só leitura. Confirma que não sobrou clínica ativa sem assinatura e que não há assinatura duplicada. Pode ser rodado a qualquer hora.

O SQL calcula a hora de Brasília a partir de UTC, então não depende do fuso do servidor MySQL. Exige a migração 57 (coluna `trial_ends_at`); se faltar, o preflight avisa e o completa não insere nada.

**Se algo sair diferente do esperado:** o único efeito do SQL são linhas novas em `clinic_subscriptions`. Para desfazer, apague apenas as linhas criadas na execução (elas têm `created_at` igual ao horário em que o SQL rodou), por exemplo `DELETE FROM clinic_subscriptions WHERE created_at >= 'AAAA-MM-DD HH:MM:00' AND clinic_id IN (...)` com os ids que o preflight listou. Guarde a lista do preflight. O backup do passo 1 também cobre isso.

## Leia antes de decidir subir (herdado da v28, continua valendo)

A v34 leva junto os e-mails transacionais da NI2-481. Das sete jornadas de e-mail, **só uma foi provada em fluxo real** (banco, servidor HTTP e SMTP de verdade): recuperação de senha do paciente com o aviso de senha alterada. As outras seis têm só teste unitário e **não foram validadas em fluxo real**:

- convite de primeiro acesso do paciente por e-mail;
- aviso de senha alterada do profissional;
- aviso de alteração do e-mail de acesso;
- avisos de suspensão, reativação e revogação administrativa;
- e-mails de confirmação, cancelamento e reagendamento de consulta.

**A NI2-481 continua em "A fazer" e o épico não está aprovado para release.** Subir a v34 leva essas seis jornadas para produção sem a prova equivalente. O último login não muda isso.

## E-mail só sai com SMTP configurado
- Com `MAIL_DRIVER=log` ou `null` (ou sem SMTP), nenhum e-mail é enviado; o sistema só registra. É seguro subir o código assim.
- Para enviar de verdade, configure no `.env` o SMTP do Titan:
  ```
  MAIL_DRIVER=smtp
  MAIL_HOST=smtp.titan.email
  MAIL_PORT=465
  MAIL_ENCRYPTION=ssl
  MAIL_USERNAME=suporte@clyonutri.com
  MAIL_PASSWORD=...senha da caixa...
  MAIL_FROM_ADDRESS=nao-responda@clyonutri.com
  MAIL_FROM_NAME="ClyoNutri"
  ```
  Antes de ligar o envio, resolva SPF, DKIM e DMARC do remetente (NI2-463), senão os e-mails caem no spam.
- O link de recuperação sai do `APP_URL` (precisa estar `https://clyonutri.com`).
- **Notificações da equipe (v34):** a opção "também por e-mail" em `/admin/notificacoes/preferencias` só habilita com esse SMTP configurado e o envio **não foi provado em produção** (NI2-481). O link do e-mail para a central só aparece com `APP_URL` definido.

## Aceite dos Termos (atenção)
`LEGAL_REACCEPTANCE_ENABLED` vem desligado. **Não ligue essa opção antes de decidir o caso da recepcionista:** a página `/aceite-termos` responde 403 para esse perfil, e com a opção ligada a recepcionista é desviada para lá e fica sem acesso. Já era assim na v27 a v32. Sobre a equipe criada na v33, veja a seção "Aceite dos Termos e a equipe nova" no começo deste arquivo.

## HSTS
Se o `.env` ainda tem `HSTS_MAX_AGE=300`, a extração não altera esse valor. A subida gradual do HSTS (86400 e depois 1 ano) segue o roteiro da v27f e continua pendente.

## Privacidade: decisão pendente
O registro do último login (data e hora das contas de profissionais e equipe, visível para operadores da plataforma, e o nome de quem acessou na visão da clínica) **ainda não consta no texto da política de privacidade**. O texto precisa ser decidido por você e pelo jurídico, incluindo a base legal e o prazo (hoje a data é só sobrescrita a cada login, sem histórico). Enquanto isso não for decidido, considere se quer subir a v34 ou deixar a migração 59 para depois: sem ela o resto da v33 funciona e a coluna mostra "Indisponível".

## Antes de subir (nesta ordem)
1. **Backup.** Guarde o zip da versão no ar e exporte o banco pelo phpMyAdmin. Faça isso sempre, mesmo quando a atualização não tem SQL.
2. **Descubra de onde você vem e rode o SQL correspondente**, no phpMyAdmin, ANTES de extrair os arquivos. Todos os arquivos ficam em `database/manual/` dentro do zip. Todos podem ser repetidos sem efeito (usam `information_schema`, sem `ADD COLUMN IF NOT EXISTS`, compatíveis com MySQL 5.7). **O último passo de todas as linhas, as assinaturas faltantes, só depois do seu OK.**

   | Versão instalada | O que rodar, nesta ordem |
   |---|---|
   | **v33**, **v32**, **v31** ou **v30** | Nada de estrutura (a migração 59 já está lá e a v34 não traz nenhuma). Só as assinaturas faltantes, **quando você der o OK**: `assinaturas-faltantes-preflight.sql`, `assinaturas-faltantes-completa.sql` e `assinaturas-faltantes-verificacao.sql`. Esse SQL não depende do código; os três arquivos podem ser abertos direto do zip. As novidades da v34 e da v33 só chegam com a extração do zip (passo 5). |
   | v29 ou v28 | `atualizacao-ultimo-login-preflight.sql` e `atualizacao-ultimo-login-completa.sql` (migração 59); depois as assinaturas faltantes. |
   | v27c, v27d, v27e ou v27f | `atualizacao-v28-preflight.sql`, `atualizacao-v28-completa.sql` (migração 58); depois `atualizacao-ultimo-login-preflight.sql` e `atualizacao-ultimo-login-completa.sql` (migração 59); depois as assinaturas faltantes. |
   | v24b, v25 ou v26 | `atualizacao-v27-preflight.sql`, `atualizacao-v27-completa.sql` (migrações 56 e 57); depois `atualizacao-v28-preflight.sql`, `atualizacao-v28-completa.sql` (58); depois `atualizacao-ultimo-login-preflight.sql`, `atualizacao-ultimo-login-completa.sql` (59); depois as assinaturas faltantes. |

   Em todos os casos, rode cada preflight primeiro (só leitura) e siga só se mostrar "OK". O preflight da v28 não confere as migrações 56 e 57: ele diz "PRONTO" mesmo num banco que não as tem (a coluna `migracao_57_registrada` mostra 0). O preflight do último login avisa com "ATENÇÃO" se a migração 58 não estiver registrada; nesse caso volte uma linha na tabela acima.
   Resumo: preflight e SQL 56/57, depois 58, depois 59, depois as assinaturas faltantes, sempre nessa ordem.
3. **Se rodar o SQL depois de extrair os arquivos** (ordem trocada): não quebra nada. O sistema funciona e o login funciona (inclusive o cadastro); só a coluna "Último login" mostra "Indisponível" e nenhuma data é gravada. Rode o SQL 59 e a coluna passa a funcionar sozinha, sem reextrair. A exceção são as migrações 56/57 e 58, que seguem obrigatórias **antes** dos arquivos: sem a tabela da 58, a recuperação de senha do paciente falha.
4. **`.env`.** Confira `APP_URL=https://clyonutri.com`. Decida o SMTP (seção acima).
5. **Arquivos.** Pelo Gerenciador de Arquivos, envie o `clyonutri-v34.zip` para `/home3/maic6824/clyonutri.com`, use **Extract** (confirme sobrescrever) e apague o zip do servidor. **Não mexa no `.env` nem em `storage/`.** O zip não contém o `.env` nem nenhum arquivo de dados dentro de `storage/` (só o `storage/.htaccess` e pastas vazias), então a extração não apaga nada do que está lá.
6. Limpe o cache e abra com Ctrl+F5 (Cmd+Shift+R no Mac).

## Conferência depois do deploy
- **Central de notificações (v34), só com contas "TESTE":** entre como super admin: o sino aparece no topo e o item "Notificações" no menu. Faça um cadastro de clínica de teste em `/cadastro` (nome "TESTE ..."): o sino passa a mostrar 1 e `/admin/notificacoes` mostra "Nova clínica cadastrada". Clique em **Abrir**: o aviso fica lido e você cai em `/admin/clinicas` filtrado. Abra `/admin/notificacoes/preferencias`: sem SMTP, as caixas "Também por e-mail" ficam desabilitadas, com a explicação. Entre com um perfil de suporte "TESTE": sem sino e sem avisos. **Não suspenda nem bloqueie contas reais para testar.** Na primeira abertura podem aparecer avisos de testes que acabam ou acabaram há poucos dias (esperado).
- **Gestão de usuários (v33), só com contas "TESTE" ou a sua própria sessão de super admin:** abra `/admin/usuarios`. Aparecem os cartões Ativos, Suspensos, Bloqueados e Convites pendentes, os filtros de perfil e status e o botão "Novo usuário da equipe". Abra a ficha de um usuário "TESTE" (botão Gerenciar) e confira o histórico. Crie um usuário de Suporte de teste: o link do convite aparece uma vez (ou chega por e-mail, se o SMTP estiver configurado); abra o link numa janela anônima, defina uma senha de 12 ou mais caracteres e entre; o usuário passa a "Ativo". Bloqueie esse usuário de teste: ele não entra mais, e com a senha certa vê "Seu acesso está indisponível no momento...". Entre como super admin, libere: a senha antiga deixa de valer e aparece um link novo. **Não teste em conta de cliente real.**
- Entre com um perfil de suporte "TESTE": a ficha de usuário abre, mas sem botões de ação.
- **Depois do SQL das assinaturas faltantes:** a verificação mostra "OK — todas as clínicas ativas têm assinatura". Em `/admin/clinicas` a "RL NUTRIÇÃO E PERFORMANCE" mostra o plano `PRO-02` com o selo "Em teste" (ou "Teste expirado", conforme o modo), em vez de "Sem assinatura". Em `/admin/comercial`, a tabela "Assinantes e planos" lista a clínica, o cartão "Assinaturas ativas" e o MRR ficam iguais aos de antes. Rode o completa uma segunda vez: ele precisa dizer "nada a fazer".
- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Entre como superadmin e abra `/admin/usuarios`: aparece a coluna "Último login" e os filtros "Qualquer acesso" e "Ordem". Logo depois do SQL, quase todas as contas mostram "Sem registro"; isso é o esperado.
- Saia, entre com uma conta de profissional **"TESTE"** (nunca com conta de cliente real para testar) e volte ao `/admin/usuarios`: a conta mostra "agora há pouco" (ou "há N min") com a data e a hora de Brasília. Em `/admin/clinicas`, a clínica dessa conta mostra o mesmo horário e o nome do usuário.
- Se a coluna mostrar "Indisponível", o SQL 59 ainda não foi rodado (ou rodou em outro banco).
- Abra `/admin/comercial`: depois do catálogo aparece o bloco **"Assinantes e planos"**. Com o filtro situação "Ativa", o total ao lado dos filtros deve ser igual ao cartão "Assinaturas ativas", e o número de cada plano no catálogo deve ser o total da tabela filtrada por aquele plano. Clique no número "Assinantes ativos" de um plano: a tabela abre já filtrada.
- Confira com o seu painel de antes: se o cartão mostrava 3 assinaturas ativas, a tabela mostra 3 clínicas ativas, e a soma dos valores mensais delas é o MRR do cartão.
- Abra `/admin/clinicas`: aparece a coluna "Plano" (para superadmin, operação, operador e financeiro).
- Entre com um perfil de suporte "TESTE": `/admin/comercial` continua negado e `/admin/clinicas` abre sem a coluna "Plano".
- Os quatro cartões do Service Desk e do Comercial continuam com caixa e cor (v30).
- Conferência da v29 (paginação em `/admin/clinicas`, cartões do painel) continua valendo.

## Voltar atrás
Extraia por cima o zip da versão anterior (v33, v32, v31, v30 ou a que estava no ar) e limpe o cache. A v34 não mexeu no banco, então voltar não pede SQL (sobre a central de notificações, veja "Voltar da v34 para a v33"; sobre usuários bloqueados e convidados, veja "Voltar da v33 para a v32"). O SQL das assinaturas faltantes só acrescentou linhas em `clinic_subscriptions`, e as versões anteriores funcionam com elas; **não precisa desfazer**. Se quiser desfazer mesmo assim, veja "Se algo sair diferente do esperado" no começo deste arquivo. Versões anteriores à v30 ignoram o campo do último login, então **a coluna `users.last_login_at` pode ficar no banco sem problema**; não precisa desfazer o SQL 59. Se mesmo assim quiser remover o campo, depois de voltar o código: `DROP INDEX idx_users_last_login ON users;`, `ALTER TABLE users DROP COLUMN last_login_at;` e `DELETE FROM migrations WHERE migration = '2026_10_27_000059_add_last_login_at_to_users';`. Isso apaga as datas de último login já registradas. A migração 58 só acrescentou uma tabela; as versões anteriores funcionam com ela.

## Pendências conhecidas (não são da v34, ou ficam para decisão)
- **Rodar o SQL das assinaturas faltantes e escolher a data-base** (`data_criacao` ou `hoje`): aguardam o seu OK.
- Clínica nova sempre recebe o teste no cadastro, mas só se o plano `PRO-02` existir no catálogo. Sem ele o cadastro funciona e a assinatura nasce na primeira visita a Configurações > Assinatura.
- A função `canMutateClinicalData` (bloqueio de cadastro para teste expirado ou cancelado) não é usada em lugar nenhum. Decidir se um dia será ligada muda o peso de "Teste expirado".
- **Coluna "Membros" de `/admin/clinicas`:** mostra sempre 0 (a tela lê `members_count`, mas a consulta devolve `active_members_count`). Já era assim na v28 e na v29. A correção é de uma linha e **não está nesta v34**, porque não foi aprovada. O bloco "Tenants recentes" do painel também fica vazio.
- Texto da política de privacidade para o último login (seção acima).
- **Sem situação "inadimplente":** o modelo de assinaturas só conhece ativa, em teste, teste expirado e cancelada. Se você quiser enxergar inadimplência, é preciso definir primeiro o que isso significa no sistema.
- O nome da clínica na tabela leva para a lista de clínicas filtrada pelo nome, porque não existe tela de detalhe da clínica.
- O último login não tem histórico e só conhece logins feitos depois do SQL. Quem fica logado por sessão longa sem entrar de novo não atualiza a data.
- `/admin/metricas-adesao` não tem paginação. Ao revogar uma sessão em `/admin/acessos`, a tela volta para a primeira página.
- O padrão de 20 itens por página nas listas do backoffice e a lista de contagem de pacientes (o cartão de pacientes não é link, de propósito) seguem como decisão sua.
- `public/assets/js/teleconsulta-webrtc.js` carrega `https://unpkg.com/@daily-co/daily-js` quando a clínica usa o provedor Daily. Já era assim; fica como decisão em aberto.
- Perfil recepcionista e `LEGAL_REACCEPTANCE_ENABLED`: ver a seção acima.
- **Central de notificações (v34):** a v34 **não foi homologada em produção** (teste de fumaça em MySQL 5.7.44 com dados sintéticos); entrega por e-mail dos avisos não comprovada (sem SMTP de produção); troca de plano e pagamentos não geram aviso (não existem no sistema); só o super admin recebe; quem desliga um tipo deixa de ter o aviso criado (não dá para recuperar depois); a primeira varredura gera avisos de testes de até 3 dias atrás.
- **Gestão de usuários (v33):** entrega dos links por e-mail não comprovada (sem SMTP de produção); a trilha de auditoria não é imutável no nível do banco; a equipe não registra aceite de Termos; super admin só é criado no servidor; um bloqueado só volta pelo super admin. A v33 **não foi homologada em produção**: o teste de fumaça foi em MySQL 5.7.44 com dados sintéticos.
- NI2-481 em "A fazer", sem SMTP de produção (NI2-463).
- O script destrutivo `saneamento_assinaturas_trial_ni2_450.sql` continua fora do pacote, de propósito.
- `.env.example` e `storage/.htaccess` (restaurados na v29) continuam no pacote.

## O que vem no zip
- `clyonutri-v34.zip`: o sistema inteiro, com o `vendor/` pronto (idêntico ao da v33 a v29; `composer.json` e `composer.lock` não mudaram). Comparado com a v33: 3 arquivos novos (`src/Domain/Notification/StaffNotificationService.php`, `views/admin/notificacoes.php` e `views/admin/notificacoes-preferencias.php`), 10 arquivos existentes alterados (rotas, `AdminController`, o layout do console, o cron, e os pontos de origem de cada aviso), e o `RELEASE_INFO.json` e este LEIA-ME trocados. A lista completa, arquivo por arquivo, está em `COMPARACAO-V33-V34.txt`. **Nenhum arquivo SQL, migração ou instalador mudou.**
- `clyonutri-v34.zip.sha256`: para conferir o arquivo.
- Não vão no zip: testes, o app mobile, `.git`, `node_modules`, arquivos de desenvolvimento, `.env` e dados de `storage/` (só o `storage/.htaccess` e pastas vazias). Arquivos `0644`, pastas `0755`.

## Conferir o arquivo
No Mac: `shasum -a 256 clyonutri-v34.zip` e compare com o conteúdo de `clyonutri-v34.zip.sha256`.
