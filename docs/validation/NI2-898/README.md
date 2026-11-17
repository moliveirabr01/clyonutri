# NI2-898 — proxy confiável e X-Forwarded-Proto

17/11/2026. Base v54.76, HEAD 6dba333. Alterações das NI2-895/896/897 preservadas.

## Diagnóstico

A falha histórica não foi reproduzida no código atual sob ambiente HTTP controlado. O primeiro probe usou snapshot somente leitura do banco de teste em cópia temporária, sessões isoladas e o front controller real: X-Forwarded-Proto=https + TRUSTED_PROXIES=127.0.0.1 retornou 200, formulário e Secure; sem cabeçalho retornou 301 https://clyonutri.com/login, sem formulário/sessão (before.log).

O teste original não registrava o status obtido no cenário reportado. A fixture ni2_426_server.php usava portas fixas, processo de fundo via shell sem confirmar prontidão, banco compartilhado storage/testing.sqlite ignorando o isolamento do runner e stop baseado em PID de shell. Essas dependências impedem atribuir com segurança a falha histórica ao Transport. Não afirmar que houve defeito de produto ou que uma causa específica do resultado histórico foi provada.

Classificação: falha histórica NÃO REPRODUZIDA; infraestrutura do teste tornada isolada e diagnóstica. Transport e public/index.php não foram alterados.

## Alterações

A suíte de HTTPS passa a usar tests/support/ni2_898_server.php: porta dinâmica, PDO SQLite novo com migrations oficiais para cada servidor, proc_open direto com PHP_BINARY, verificação de prontidão, encerramento e limpeza por instância. O handler de sessão redireciona somente o arquivo para a pasta temporária; cookie Secure, inicialização de sessão, Transport, controles e rotas continuam executados no front controller real. Nenhuma injeção de sessão/autenticação. Configuração de teste explícita para chaves, e-mail e HSTS; .env existente não é editado.

Adicionadas mensagens com status/Location/form real, casos de XFP=http, proxy CIDR, IP fora da lista e HSTS de seis meses. Mantidos GET/HEAD, canonicalização de Host, POST recusado antes de sessão e opções de rollback. Retirada a entrada de falha conhecida; suíte promovida ao core_modules.txt. Fixture anterior preservada para outras suítes.

## Resultados HTTP reais

| Caso | Resultado |
| --- | --- |
| IP confiável + XFP=https | 200, formulário, sem Location, cookie Secure |
| Mesmo IP sem XFP | 301 para HTTPS canônico |
| IP confiável + XFP=http | 301 |
| CIDR confiável + XFP=https | 200 sem redirect |
| Cliente fora da lista + XFP=https | 301, sem sessão |
| Proxy HTTPS reconhecido | HSTS max-age=15552000 |
| POST por HTTP | 403, sem controller/sessão |
| HEAD login por proxy HTTPS | 200 |
| HEAD rota inexistente | 404 |

Suíte ni2_427: **40 PASS, 0 FAIL**, registrada em http.log. `php tests/run_full_suite.php @docs/validation/NI2-898/regression-suites.txt`: **2 PASS**, zero falhas/conhecidas/não executadas (inclui ni2_470_audit_https_cors_security_headers_test.php); regressions.log. PHP syntax dos dois arquivos e git diff --check PASS.

A máquina permite bind local nesta execução. Não são respostas CGI ou simuladas: requisições cURL atingem processos PHP -S locais com APP_ENV=production e cabeçalhos de proxy controlados.

## Reprodução e limites

APP_ENV=testing MAIL_DRIVER=null php -d zend.assertions=1 -d assert.exception=1 tests/ni2_427_https_transport_test.php

php tests/run_full_suite.php @docs/validation/NI2-898/regression-suites.txt

O runner exige timeout externo, ausente neste macOS: foi usado adaptador temporário real ../NI2-895/timeout-adapter.py no PATH (subprocess.run(timeout=...), códigos preservados, 124 em expiração); runner do projeto inalterado.

Não foi reexecutado o gate core completo nem a suíte inteira. Apache/HostGator, terminador TLS externo e MySQL real NOT TESTED. Não houve deploy ou nova versão de runtime. NI2-427 permanece com seu status anterior; esta investigação não conclui a homologação HTTPS na HostGator nem altera NI2-808/885.
