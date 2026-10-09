# NI2-294 — implementação e evidências

Referência: https://caplybr.atlassian.net/browse/NI2-294 — `[PX-01] Cliente móvel não consegue autenticar com seu contrato atual`.
Critérios consultados no Jira autenticado em 08/10/2026 (America/Sao_Paulo). Nenhuma alteração de status ou comentário foi enviada à issue.

**Resultado global: BLOCKED. Release: NOT APPROVED. Validação independente: NOT TESTED.**

Foi implementado e executado o acesso por e-mail e senha para paciente com credencial previamente cadastrada. Isso não conclui o provisionamento/recuperação nem certifica uma aplicação nativa ou uma implantação em produção.

## Critérios de aceite e limites da evidência

| Critério transcrito da issue | Resultado comprovado | Classificação e lacunas |
| --- | --- | --- |
| Cliente permite credencial forte; paciente ativo entra, acessa plano vigente e encerra sessão; entrada inválida não gera token. | Cliente TypeScript compilado + interface Expo web compilada enviaram senha à API real. Plano publicado apareceu com refeição e quantidade persistidas; rascunho não apareceu. Logout removeu token. Entradas inválidas não persistiram tokens. | **PASS** no fluxo web com credencial existente. Provisionamento/recuperação: **BLOCKED**. iOS/Android: **NOT TESTED**. |
| Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados. | Paciente autorizado acessou seus dados. Sem credencial, paciente inativo, clínica inativa, token revogado/expirado/sem expiração/tipo não paciente foram recusados. Outro tenant não recebeu plano/água; parâmetros de paciente/clínica não sobrescreveram identidade. Bearer de paciente não abriu dashboard profissional. Identificador ambíguo falhou fechado. | **PASS** nos endpoints/cenários enumerados em `evidence/http-client-results.json`. Não constitui auditoria integral de todas as rotas/papéis do SaaS. |
| Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha. | Interface mostrou autenticação em andamento com botão desabilitado; erro de senha; plano real; ausência real de plano; falha de evolução distinta de ausência; erro de logout com servidor desligado; recuperação da saída após reinício; retorno à entrada por token expirado. | **PASS** no cliente web observado. Interfaces nativas e acessibilidade completa: **NOT TESTED**. |
| Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados. | Jornada clínica: **32/32**; ciclo de planos/prescrições: **47/47**. Teste HTTP conferiu planos e presença da versão publicada preservados. Login usa valores das fundações `clinical-botanical.css`. | Regressões executadas: **PASS**. Ratificação formal dos tokens: **NOT TESTED**; não há evidência de aprovação do design. Critério integral: **NOT TESTED**. |

## O que mudou

- `mobile/App.tsx`: entrada real, senha mascarada, validação de campos, indicador de autenticação, erros, sessão em memória e navegação após saída/expiração.
- `mobile/src/services/api.ts`: contrato exige e-mail/senha, valida resposta de autenticação, trata falha de transporte/JSON e 401. Logout só confirma após revogação; se a rede falhar, mantém o token para tentar novamente.
- `mobile/src/screens/PatientHomeScreen.tsx`: encerra a sessão do aplicativo; mostra falhas de diário/evolução; distingue falha inicial de ausência de plano. Removeu afirmações fixas de consentimento/autenticação no perfil.
- `public/index.php`: autenticação exige paciente e clínica ativos e tipo de token `patient`; identificador omitido não corresponde a telefone vazio; identidade ambígua é recusada; tipos malformados são rejeitados; expiração de novos tokens usa UTC.
- Entrada Expo e configuração TypeScript adicionadas; dependências de web compatíveis com o Expo 51 existente e lockfile adicionados para compilar e executar o mesmo cliente.

A API existente de PIN/OTP não foi substituída. O mecanismo escolhido e comprovado nesta entrega é **senha**; PIN/OTP e seus fluxos de emissão/recuperação não foram certificados.

## Infraestrutura e autenticidade

Execução local do código de produção: PHP 8.5.10, SQLite com migrações oficiais, HTTP TCP real e cliente TypeScript compilado. A interface foi exportada pelo Expo e executada no navegador integrado em `http://127.0.0.1:8082`.

As identidades, clínicas e prescrições usadas são **fixtures sintéticas explicitamente identificadas**, inseridas em banco temporário separado. Não são respostas simuladas: as requisições passam pelo `public/index.php`, verificam hash da senha, persistem token SHA-256, consultam dados relacionais e revogam tokens. O plano da fixture foi criado/publicado pelo serviço de domínio, com versão persistida. Nenhum `fetch`, provedor ou endpoint é substituído por mock nesta suíte.

O host de verificação `tests/support/ni2_294_ui_router.php` serve apenas o export Expo e encaminha `/api/*` ao front controller real. Ele não implementa respostas de API e não é configuração de implantação. Para web, o export deve compartilhar origem com `/api` ou usar uma origem de API explicitamente configurada com CORS apropriado. Para dispositivo nativo, configurar `EXPO_PUBLIC_API_URL` para o endereço HTTPS acessível da API; localhost refere-se ao próprio dispositivo.

Tokens ficam na memória do cliente; reiniciar o aplicativo exige nova autenticação. Persistência comprovada é a persistência relacional de sessão/dados no servidor. Armazenamento seguro de sessão nativa não foi implementado/certificado.

## Reprodução automática

Na raiz do repositório, com PHP, Composer dependencies e Node/npm disponíveis:

```sh
cd mobile
npm ci --no-audit --no-fund
cd ..
./tests/ni2_294_verify.sh
```

O script executa os comandos e grava os resultados individuais:

```sh
cd mobile
npm run typecheck
npm run test:auth
EXPO_PUBLIC_API_URL=/ ./node_modules/.bin/expo export --platform web
cd ..
php -l public/index.php
```

`test:auth` compila **o serviço de produção**, cria um banco temporário dedicado, aplica migrações, inicia PHP em porta livre e executa **34/34 verificações** contra as rotas reais. Desliga o servidor para provar falha de rede real; nenhum interceptador fabrica a falha. Limpa seu banco ao terminar. Tokens/senhas não são gravados no resultado.

O script também cria bancos temporários separados, aplica migrações/catálogo e executa:

```sh
php tests/canonical_first_consultation_e2e_journey_test.php
php tests/meal_plan_and_prescription_lifecycle_test.php
```

Essas duas regressões executam serviços de domínio com banco real; não são apresentadas como testes HTTP ou como execução nativa.

Resultados: `evidence/http-client.log`, `http-client-results.json`, `http-server.log`, `typecheck.log`, `web-build.log`, `php-lint.log`, `canonical_first_consultation_e2e_journey.log`, `meal_plan_and_prescription_lifecycle.log`. O manifesto `source-sha256.json` identifica os arquivos desta execução.

## Reprodução visual e evidências

Com o export gerado, em um terminal separado:

```sh
ni294_dir=$(mktemp -d /tmp/ni2-294-browser-XXXXXX)
export DB_CONNECTION=sqlite
export DB_DATABASE="$ni294_dir/ni2-294-browser.sqlite"
php tests/support/ni2_294_fixture.php prepare
php -S 127.0.0.1:8083 -t mobile/dist tests/support/ni2_294_ui_router.php
```

Abrir `http://127.0.0.1:8083`. Credenciais **somente da fixture temporária**: `patient-a@example.test` / `PacienteTeste@2026`. Paciente sem plano: `empty@example.test`, mesma senha da fixture.

1. Enviar campos vazios: mensagem obrigatória; preencher senha errada: rejeição real; senha correta: indicador de autenticação e home.
2. Aba Plano: `Plano vigente NI2-294`, `Almoço`, arroz 100 g. Captura `published-plan.png`.
3. Aba Evolucao: a falha conhecida de schema aparece como erro, não como ausência. Captura `evolution-error.png`.
4. Perfil → Encerrar sessão: retorna ao login. Captura `logout.png`. Na execução observada o total de tokens do banco passou de **1 para 0**.
5. Entrar com `empty@example.test`: aba Plano mostra ausência. Captura `empty-plan.png`.
6. Entrar novamente; no Perfil, desligar o servidor local com Ctrl-C e tentar sair: erro de revogação, sessão não declarada encerrada. Captura `logout-network-error.png`. Reiniciar o mesmo servidor e repetir saída: sucesso.
7. Entrar como paciente A; em outro terminal, usando o mesmo DB_DATABASE, executar `php tests/support/ni2_294_fixture.php expire`; clicar adicionar água: API recusa, aplicativo volta à entrada. Captura `session-expired.png`.

A persistência final da execução observada está em `browser-persistence.json`: 1 token expirado, **0 registros de hidratação após operação negada**, 1 plano publicado e 1 versão. A captura `login-invalid.png` registra a rejeição visual da credencial incorreta. O carregamento foi observado na árvore de acessibilidade como `progress indicator Autenticando` e botão Entrar desabilitado.

## Bloqueios e gates pendentes

- **BLOCKED — provisionamento/recuperação:** não há rota operacional de primeiro acesso/redefinição nem integração de entrega de credenciais no runtime examinado. O serviço de convites existente depende de tabela ausente nas migrações e não define senha. A fixture grava um hash apenas para preparar o teste; isso **não comprova provisionamento de um paciente real**. É necessário definir o mecanismo autorizado, implementá-lo e executar primeiro acesso e recuperação reais. O aplicativo informa a indisponibilidade; não promete envio de mensagem.
- **BLOCKED — evolução:** a API de evolução falha no schema disponível (`patient_self_metrics` ausente), achado PX-02 da auditoria anterior. O erro agora é visível; a funcionalidade não foi simulada nem declarada corrigida nesta issue.
- **NOT TESTED — iOS/Android:** não houve instalação, execução em aparelho/simulador, distribuição ou armazenamento seguro de sessão nativa.
- **NOT TESTED — validação independente:** testes e inspeção foram feitos pelo mesmo executor da implementação. É necessário revisor independente executar o roteiro e avaliar os resultados antes de conclusão da issue.
- **NOT TESTED — infraestrutura de produção/MySQL/HTTPS, matriz integral de regressão, ratificação formal dos tokens e demais gates de release:** execução local não aprova esses ambientes ou gates.
- Identificadores duplicados em clínicas ativas são recusados para evitar seleção arbitrária de prontuário. Atendimento desse caso requer seleção de clínica no contrato, implementação e teste específicos.

**A issue permanece sem alteração de status. Nenhum deploy, merge ou aprovação de release foi realizado.**
