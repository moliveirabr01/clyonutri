# NI2-888 — Agenda, configuração e botão principal

Implementação local na codebase canônica. Alterações anteriores preservadas; sem migração, alteração de .env, pacote ou deploy.

## Resultado

- Dia, semana e mês usam um único partial de configuração: “Configurar agenda”, com destinos Horários e regras (`secao=horarios`), Feriados e bloqueios (`secao=feriados`) e Link de agendamento (`secao=link`). Os atalhos antigos duplicados foram removidos, inclusive o link escondido da visão semanal.
- Estado desativado: link “Agendamento online: desativado · Ativar” abre diretamente a seção Link online. Nessa seção há um submit explícito “Ativar agendamento online”, que envia `booking_enabled=1` junto dos demais valores do formulário. Assim, com configurações válidas, são dois cliques: abrir a seção e ativar. Não altera a disponibilidade semanal nem fabrica horários; a ativação não substitui as verificações existentes de jornada/perfil público.
- Estado ativado: “Agendamento online: ativo · Copiar link · Configurar”. Cópia usa Clipboard API; sem permissão/suporte, mostra campo selecionável e feedback acessível. URLs relativas são convertidas em absolutas usando a origem atual.
- Permissão usa as mesmas verificações de usuário, clínica, vínculo e papel da tela de destino (ProfessionalProfile + owner/admin). Recepção e nutricionista sem gestão não veem atalhos de ativação, configuração ou cópia. A permissão do backend foi preservada e a ação booking também valida CSRF explicitamente.
- `NewConsultationButton` fornece o mesmo texto “+ Nova consulta” e as mesmas classes `btn btn-primary new-consultation-cta` no painel, nas três visões da agenda e no agendamento genérico da ficha. Contexto do paciente e abertura do diálogo preservados.
- “Agendar retorno” continua sendo uma ação própria com significado diferente; links em mensagens explicativas não foram transformados em botões. A lista de pacientes foi pesquisada e não possui outro botão de nova consulta a alinhar.
- Cor primária verde do Design System, sem o accent laranja nem a variante exclusiva da semana. Responsividade do painel atualizada para a classe compartilhada, com cache bust do CSS e novo script externo sem handlers inline.

## Validações executadas

- `php tests/ni2_888_agenda_usability_test.php`: **25 PASS, 0 FAIL**. SQLite isolado; actions reais e renderização das três visões, painel, ficha e Configurações. Cobre unicidade do menu, destinos, ativação persistida, preservação dos parâmetros, estados ativo/inativo, ausência dos controles para recepção/nutricionista, permissão de administrador, bloqueio por CSRF e alteração negada à recepção.
- `node tests/ni2_888_copy_link_test.cjs`: **3 PASS**. Executa o script real em contexto JavaScript controlado para cópia, fallback e URL absoluta. Não é um teste de navegador.
- Contraste calculado sobre os tokens reais `--botanical-green-600: #39734A` e branco: **5,64:1**, acima do mínimo AA de 4,5:1 para texto normal. Não substitui inspeção visual de layout.
- `./bin/run-tests core`: **21 suítes OK, 0 falhas**, com asserções PHP ligadas. A suíte nova está no gate. O log do gate foi capturado antes de ampliar os dois cenários de permissão na suíte específica; a execução específica final com 25 verificações foi aprovada separadamente.
- `php tests/ni2_588_590_execution_test.php`: **FAIL**, na expectativa “Badge destaca alergia registrada do paciente”. As verificações de abas e menu da agenda passaram antes dessa falha. A fixture também reporta ausência de `production_events`. Não declarar essa suíte inteira aprovada; não foi alterada a lógica clínica do selo nesta história.
- Dois testes HTTP antigos tiveram somente a expectativa do rótulo Agendar atualizada para + Nova consulta, mantendo a mesma URL/contexto: `v5442_iniciar_agora_revisao_pendente_test.php` e `v5443_retorno_avaliacoes_alergias_test.php`. Não foram executados nesta rodada por dependerem de servidor local.
- Sintaxe dos 10 arquivos PHP envolvidos, `node --check` do script e `git diff --check`: sem erros.
- Navegação HTTP/browser, layout responsivo e contagem de cliques por automação de interface: **NOT TESTED**. O ambiente recusou abertura de porta nas tentativas anteriores desta sessão. Os testes diretos de controller não foram classificados como HTTP/E2E.

Logs nesta pasta. Sem alteração de versão de release ou conclusão declarada no Jira.
