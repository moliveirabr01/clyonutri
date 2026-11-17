# NI2-899 — handlers inline incompatíveis com CSP

17/11/2026. Base v54.76/HEAD 6dba333. Alterações anteriores preservadas.

## Reprodução

A suíte original, executada com o servidor isolado da NI2-898 para evitar o banco compartilhado/portas fixas, registrou 126 PASS e 2 FAIL (before.log): dois atributos inline (evento_detalhe onsubmit, patient/app onclick) e um falso positivo de URL javascript em comentário PHP de views/anthropometry/skinfolds.php ("Sem JavaScript: ...").

Os dois handlers são defeito de produto: script-src-attr none impede sua execução. O restante da configuração CSP não foi relaxado.

## Implementação

- views/admin/evento_detalhe.php: onsubmit substituído por data-confirm, consumido pelo listener externo existente de csp-actions.js (já injetado globalmente no HTML por SecurityHeaders). Mantidos POST, endpoint, CSRF e texto de confirmação.
- views/patient/app.php: onclick substituído por data-patient-first-access. O script existente com nonce registra addEventListener e usa a função de seleção WAI-ARIA: atualiza painel, aria-selected, tabindex e foco. Sem destino, mantém o fallback href.
- Suíte CSP usa a fixture HTTP isolada NI2-898, inclui /paciente/app e detecta esquema javascript em atributos de URL, sem contar comentários descritivos. Controles positivos de href/formaction e negativo de comentário foram adicionados.
- Removida a entrada de known_failures; suíte CSP e teste de listeners acrescentados ao gate core.

## Resultados

- ni2_429_csp_headers_test.php: **151 PASS, zero falhas** (after.log), inclusive zero atributos inline nas telas varridas; scripts inline com nonce e cabeçalhos CSP efetivos em requisições GET/HEAD reais.
- node tests/ni2_899_csp_handlers_test.cjs: **7 cenários PASS** (listeners.log): recusar/aceitar confirmação; atalho muda painel/ARIA/tabindex/foco; voltar ao login; Home/End; fallback sem destino; ligação declarativa das views. Executa listeners de produção em VM Node com doubles mínimos de DOM, não navegador.
- php tests/run_full_suite.php @docs/validation/NI2-899/regression-suites.txt: **3 PASS, zero falhas/conhecidas/não executadas**, cobrindo CSP, HTTPS/proxy e auditoria de cabeçalhos/CORS (regressions.log).
- PHP syntax dos três PHP alterados, bash -n bin/run-tests e git diff --check: PASS.

Para executar o runner neste macOS sem coreutils timeout foi usado adaptador temporário real de ../NI2-895/timeout-adapter.py no PATH, preservando códigos e aplicando timeout via subprocess.run. Veja NI2-895 para reprodução.

## Limites

A fonte v54.76 foi confirmada e corrigida. ZIP v54.76 não encontrado no diretório release; inspeção desse artefato **NOT TESTED** (package-scan.log). Nenhum ZIP pré-existente foi substituído nem novo pacote foi gerado.

Navegação/interação real em navegador, Apache/HostGator e MySQL real **NOT TESTED** nesta rodada. O Chromium local foi bloqueado pelo sandbox na NI2-897, sem nova tentativa idêntica. Cabeçalhos e páginas públicas foram exercitados via HTTP real PHP -S; listener de incidente administrativo foi testado como unidade, sem criar incidente real. Gate core amplo e suíte inteira não repetidos (falhas fora desta história na NI2-895). Não houve deploy.

Status da NI2-429 e item de .htaccess não alterados por esta história.
