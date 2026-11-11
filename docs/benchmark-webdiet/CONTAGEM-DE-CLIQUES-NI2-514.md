# NI2-514, contagem de cliques: "plano com um alimento"

Roteiro automatizado: `tests/ni2_514_click_count.cjs` (Chrome real, dados "TESTE", banco isolado). Sem cronômetro.

Convenção (igual nas duas versões):
- Começa na lista de planos, com a sessão aberta.
- Conta cada clique em botão, link, opção de lista ou caixa de seleção. Escolher uma opção de lista suspensa conta 1.
- Digitar não conta (aparece em "campos digitados"). Foco automático ou por Tab não conta.
- Termina com 1 alimento salvo no plano, conferido no banco.
- A v41 **não** escolhe sozinha o primeiro resultado da busca: o alimento é sempre uma escolha explícita, por segurança.

| Cenário | Versão | Cliques | Campos digitados |
|---|---|---|---|
| Com paciente | v40 | 9 | 3 (título, busca, quantidade) |
| Com paciente | v41 | 6 | 2 (busca, quantidade) |
| Sem paciente | v41 | 5 | 2 |
| Com paciente | v42 | 6 | 2 |
| Sem paciente | v42 | 5 | 2 |

A meta de até 6 cliques é uma proposta da épica, ainda a validar com nutricionistas (gate 2). Resultado bruto em `docs/evidencias/ni2-514/cliques-*.json`.

Passos da v40 (9): Novo plano; escolher o paciente; Iniciar prescrição; Adicionar primeira refeição; sugestão rápida; Salvar refeição; + Alimento; escolher o alimento; Adicionar ao plano.
Passos da v41 sem paciente (5): Novo plano; Iniciar prescrição (as 6 refeições já vêm marcadas e o título já vem preenchido); + Alimento; escolher o alimento; Adicionar ao plano.

## v42 (NI2-521: editor sem recarregar)

Mesma convenção e mesmo cenário. Na v42 o "Adicionar ao plano" salva sem recarregar a página: a janela fecha, a refeição e a barra de totais se atualizam e o aviso aparece na região de status. Por isso o roteiro da v42 espera a janela fechar e o aviso, em vez de esperar a navegação; os cliques são os mesmos da v41 (6 com paciente, 5 sem paciente) e o alimento continua sendo escolha explícita.

A v42 não reduziu cliques neste cenário. O ganho da v42 está em não recarregar a página a cada alimento (a rolagem e o foco ficam onde estavam) e na barra de totais sempre visível; ele não aparece na contagem de cliques e precisa ser medido em tempo nas sessões do roteiro `ROTEIRO-AVALIACAO-CLINICA-V42.md`.
