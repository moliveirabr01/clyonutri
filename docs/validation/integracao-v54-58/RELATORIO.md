# Integração local v54.58

Codebase: `/Users/moliveira/Nutri_II`. Execução: `./bin/serve-local.sh`, http://127.0.0.1:8081/dashboard.

Integração do delta UX v54.58 por comparação de três vias com o pacote v54.57. Conflitos de apresentação do dashboard e menu foram resolvidos conforme o layout v54.58. A função index do dashboard recebeu a composição e os dados semanais da versão nova; os demais métodos conservam a mesclagem local. Foram incluídos DashboardOverview, os estilos requeridos ausentes e o método puro allowsWeb, cuja lógica corresponde às regras locais anteriores de assertWebAccess.

Identidade distinta: v54.58-local. RELEASE_INFO conserva os metadados locais anteriores, sem atribuir a esta integração as validações integrais do ZIP. Manifesto de integridade regenerado para esta árvore. Nenhum deploy ou migração executado. .env, uploads e dados existentes não foram substituídos; login e navegação podem atualizar normalmente sessão e registros operacionais.

Validação: 49 verificações no navegador Chrome, incluindo login, quatro indicadores, agenda semanal, ausência de rolagem horizontal em desktop/celular, foco contido no menu, Escape, botão fechar, toque fora, retorno de foco, mudança para desktop, pacientes, catálogo ativo, busca e nova consulta. Análise axe sem violações WCAG A/AA no painel desktop e menu móvel. Nenhum erro JavaScript observado. Capturas desktop.png, dashboard-mobile.png e menu-mobile.png.

Regressão: 27/27 verificações da suíte dashboard_review_fixes_test.php, incluindo contagem/deduplicação de rascunhos, isolamento por clínica, filtro de planos e logout com CSRF. Atualizada a expectativa legada de redirecionamento para aceitar /login com o parâmetro de retorno que a base local já utiliza; a exigência de autenticação permanece verificada. Sintaxe PHP dos controladores/layout/autorização/resumo e JavaScript dos dois arquivos alterados aprovada.

Preservação: cópias anteriores em storage/backups/integracao-v54-58-20261111/antes, com hashes em arquivos.json. Não houve reset, limpeza do repositório, substituição integral da árvore ou commit das alterações locais. As versões históricas e o ZIP v54.58 permanecem intactos.

Escopo: validação local de dashboard/menu e regressões descritas; não equivale a executar novamente as 990 verificações do pacote nem a homologação humana, clínica ou de produção.
