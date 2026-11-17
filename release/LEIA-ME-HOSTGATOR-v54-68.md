# ClyoNutri v54.68 · correções do reteste da auditoria 360° (HostGator)

O pacote `clyonutri-v54-68-hostgator.zip` é **completo**: leva só o código que roda no servidor, sem `tests/`, `docs/` nem `scripts/`. Deve ser aplicado sobre a **v54.65** que está em produção e já inclui tudo o que veio na v54.66 e na v54.67.

Há **uma mudança de banco**, a migration 096, que só acrescenta colunas. O SQL manual dela está em `database/manual/`.

## O que muda

### Correções do épico NI2-856 (vindas da v54.67)
- **Financeiro:** valores com vírgula são gravados certo.
- **Aceite de transcrição:** quem aceita é o paciente, pelo portal ou por link com código OTP. O profissional não aceita no lugar dele.
- **MFA obrigatório** para os usuários de plataforma no `/admin`.
- **Prontuário ligado à consulta:** cada consulta registra a anamnese, a avaliação e o plano dela.
- **Cifragem:** alergias e observações do paciente, além do prontuário, ficam cifradas no banco.
- **Agenda:** ao confirmar ou cancelar, a agenda continua no mesmo dia.
- **Outros:** reimportação de planilha, eventos de produção sem texto clínico e gate de testes no build.

### Corrigido nesta versão (reteste de 16/11/2026)
- **Alergias voltam a funcionar (era P0).** Na v54.67 a alergia cifrada aparecia como `enc:v1:…` nas telas e a trava de alérgenos deixava passar, por exemplo, leite para paciente alérgico a leite. Agora a alergia aparece legível em todas as telas e a trava volta a bloquear.
- **O código OTP do aceite de transcrição é enviado por e-mail.** Na v54.67 ele nunca saía.
- **Códigos de recuperação do MFA:** logo depois de cadastrar o MFA aparece uma tela com 8 códigos, mostrada uma única vez. Só dá para continuar depois de marcar "Guardei os códigos".
- **Teste encerrado:** o painel e a Assinatura explicam a regra:
  - **até 3 dias depois do fim do teste:** tudo funciona normalmente;
  - **depois disso:** a conta fica em modo leitura;
  - **para assinar:** pelo checkout, quando o Asaas estiver ativo, ou pelo e-mail do suporte.
- **Pequenos ajustes:**
  - um plano antigo aparece como "Substituído" (antes "Superseded");
  - a anamnese tenta salvar de novo sozinha depois de uma falha de rede ou de servidor;
  - o e-mail de nova senha cumprimenta pelo primeiro nome, sem "Dra.".
- **Versão:** a versão é v54.68 em todo lugar (rodapé, `RELEASE_INFO.json` e manifesto do pacote).

## Antes de começar
- **`.env` de produção:** confira que tem `CLINICAL_ENCRYPTION_KEY` preenchida. Ela já é usada pela anamnese desde a v54.45.
  - **Se estiver vazia, pare:** sem ela, cadastrar ou editar paciente dá erro.
  - **Nunca troque essa chave:** os dados cifrados com ela ficam ilegíveis.
- **Aplicativo autenticador:** quem entra no `/admin` (superadmin, operador, suporte, auditor) precisa de um no celular, como Google Authenticator, 1Password ou Authy. No primeiro acesso depois da atualização, o sistema pede para cadastrar.
- **E-mail:** para o OTP chegar ao paciente, o `.env` precisa de `MAIL_DRIVER=smtp` e dos dados SMTP. Sem isso, o paciente aceita pelo portal.

## Ordem

### 1. Backup
1. **Banco:** no cPanel, em **Backup**, baixe o backup do banco `maic6824_Clyonutri2`.
2. **Arquivos:** no Gerenciador de Arquivos, compacte `/home3/maic6824/clyonutri.com` (sem `storage/uploads`, se estiver grande) e baixe o zip.
3. **`.env`:** guarde o `.env` de produção à parte.
4. **Cron:** pause a tarefa do cron do `bin/cron_scheduler.php`.

### 2. Manutenção
Em `/admin/configuracoes`, ponha **Modo de manutenção da plataforma** em **Ativo**.

### 3. Preflight (só leitura)
No phpMyAdmin, com o banco selecionado, importe `database/manual/atualizacao-v54-68-preflight.sql` (utf-8). Confira o resultado:
- **P1:** MySQL 5.7.x.
- **P2:** a `2026_11_15_000095_reteste_ux_catalog_cleanup` aparece e a 096 não. **Se a 095 faltar, pare**: falta a atualização da v54.63.
- **P3:** 6.
- **P4:** 0. Se vier 14, a 096 já foi aplicada e o passo 4 só a registra.
- **P5:** anote os usuários de plataforma. São eles que vão cadastrar MFA.

### 4. Atualização do banco
Importe `database/manual/atualizacao-v54-68-completa.sql`. O script é idempotente: se cair no meio, importe de novo.

Depois importe `database/manual/atualizacao-v54-68-verificacao.sql` e confira:
- **V1:** 14.
- **V2:** 1.
- **V3:** 0 e 0.

O código v54.65 continua funcionando com o banco novo, porque a atualização só acrescenta colunas.

### 5. Arquivos
1. Na pasta `/home3/maic6824/clyonutri.com`, envie o `clyonutri-v54-68-hostgator.zip` e extraia sobrescrevendo.
   - O `.env` não é tocado.
   - Em `storage/`, o pacote só traz pastas vazias e os `.htaccess`.
2. Apague o zip do servidor.
3. Confira as permissões: arquivos 644, pastas 755 e `storage/` com escrita.

### 6. Cache
1. Limpe o OPcache, se o cPanel oferecer. Se não oferecer, espere 1 a 2 minutos.
2. Recarregue com Ctrl+Shift+R.

### 7. Conferência
- **Rodapé:** o menu mostra **v54.68**.
- **Alergia (paciente TESTE):** cadastre a alergia "leite".
  - A ficha mostra "Alergias: leite", **não** `enc:v1:…`.
  - No plano dele, incluir "Leite desnatado" é bloqueado com conflito de lactose.
- **MFA:** entre no `/admin` com um usuário de plataforma.
  1. Cadastre o MFA com o autenticador.
  2. Guarde os 8 códigos de recuperação.
  3. Marque "Guardei os códigos" e continue.
- **Aceite de transcrição (só com SMTP configurado):** numa consulta de paciente TESTE, peça o aceite. O e-mail com o código chega ao paciente.

### 8. Fim da manutenção
1. Volte o **Modo de manutenção** para **Inativo**.
2. Reative o cron.

## Cifrar os dados que já existem (opcional, pode ser depois)
O que já está no banco continua em texto claro e o sistema lê normalmente. Só o que for criado ou editado a partir de agora sai cifrado. Para cifrar o resto sem SSH, use o **Cron** do cPanel uma única vez:

1. **Backup:** faça um backup do banco antes.
2. **Simulação:** no cPanel, abra **Cron Jobs** e crie uma tarefa para daqui a 2 minutos com o comando abaixo.
   ```
   /usr/local/bin/php /home3/maic6824/clyonutri.com/bin/encrypt_clinical_records.php --dry-run > /home3/maic6824/clyonutri.com/storage/logs/cifragem-simulacao.txt 2>&1
   ```
3. **Conferir a simulação:** depois que rodar, **apague a tarefa**. Abra `storage/logs/cifragem-simulacao.txt` e confira duas coisas:
   - aparece "SIMULAÇÃO";
   - não há erro.
4. **Execução:** repita com o mesmo comando, sem `--dry-run` e com saída em `cifragem-execucao.txt`. Depois **apague a tarefa**.
5. **Verificação:** abra 2 ou 3 pacientes com alergia. As alergias aparecem legíveis e a trava continua funcionando.

O script é idempotente: o que já está cifrado não é mexido. Ele lê o `.env` sozinho e para se a `CLINICAL_ENCRYPTION_KEY` faltar em produção. Se o cPanel indicar outro caminho para o PHP, use o caminho dele.

## Reversão
Volte os arquivos do backup do passo 1. O banco pode ficar como está, porque as colunas novas não atrapalham a v54.65.

**Atenção:** registros criados ou editados enquanto a v54.68 estava no ar ficam cifrados. A v54.65 lê a anamnese cifrada, mas **não** lê a alergia e as observações cifradas do paciente. Se for voltar, avise antes para eu preparar o caminho.

## O que foi e o que não foi testado
- **Testado:**
  - local, com MariaDB 10.11 no modo do MySQL 5.7 e Chromium, só com dados TESTE;
  - SQL da 096 rodado duas vezes seguidas, sem erro;
  - varredura de 460 páginas sem nenhum `enc:v1:`;
  - trava de alérgenos, MFA com códigos de recuperação, OTP pelo e-mail (driver de log) e retentativa da anamnese com o banco fora do ar.
- **Não testado:** MySQL 5.7 real, HostGator, SMTP real e checkout real do Asaas.
