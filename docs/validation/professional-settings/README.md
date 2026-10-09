# Configurações profissionais — 09/10/2026

Implementado cadastro (nome, e-mail, celular, consultório, especialidade, apresentação), identificação (CRN e região), disponibilidade semanal (dias, início/fim, pausa opcional e duração padrão) e alteração de senha autenticada.

E-mail exige senha atual quando modificado; alterações de e-mail/CRN removem o estado de verificação anterior. Somente owner/admin altera nome do consultório. Dados e disponibilidade têm verificação de revisão para evitar sobrescrita por abas antigas. Disponibilidade explícita é aplicada na criação e alteração de horários da agenda, sem modificar consultas existentes. Ausência de disponibilidade configurada mantém o funcionamento anterior até o profissional salvar suas escolhas.

Senha exige credencial atual, confirmação, tamanho de 8 a 72 bytes para bcrypt e diferença da anterior. Há limite de cinco tentativas incorretas por sessão/usuário em 15 minutos. Senhas não são repopuladas na interface nem registradas na auditoria. Atualização usa transação e comparação do hash anterior; revoga recuperação pendente, sessões persistidas e tokens pessoais de usuário. A versão de autenticação também revoga sessões PHP antigas nas rotas protegidas, incluindo sessões legadas com versão zero. Recuperação por token aumenta a mesma versão. A sessão atual é renovada e retorna ao login. Login preserva espaços da senha.

Migração `2026_10_09_000024_add_professional_account_settings` aplicada ao banco local: celular e versão de autenticação no usuário. A disponibilidade reutiliza o registro de preferências do onboarding e sua configuração detalhada é preservada ao revisar o passo de preferências.

Validação: 21 verificações aprovadas contra HTTP de produção, SQLite isolado e Chrome. Inclui gravação de CRN/cadastro, senha atual para e-mail, conflito de revisão, horários inválidos, pausa, dias fechados, término fora do expediente, duração padrão, CSRF, senha incorreta/confirmação, navegação após alterar senha, sessão anterior revogada, nova credencial e senha antiga rejeitada. Tela examinada em 375 e 1280 pixels. Resultados em `checks.json`; imagens `settings-375.png` e `settings-1280.png`.

Regressões de recuperação de acesso, onboarding e primeira consulta passaram em bases descartáveis. Sintaxe PHP e `git diff --check` passaram. Teste reproduzível: `node tests/professional_settings_http_test.cjs`; depende de Chrome e Playwright em `/tmp/cfit276-browser/node_modules/playwright` e cria/remove sua própria base. Nenhuma credencial real foi alterada durante a validação.
