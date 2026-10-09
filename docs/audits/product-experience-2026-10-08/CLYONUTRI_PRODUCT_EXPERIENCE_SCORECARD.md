# ClyoNutri II — Scorecard de Product Experience

Data de avaliação: **08/10/2026 (America/Sao_Paulo)**. Fontes: árvore de trabalho existente, navegação real em `127.0.0.1:8080` e instância isolada `localhost:8081`, banco SQLite novo com as 21 migrações. Nenhuma alteração de código da aplicação ou de registros no Jira.

## Resultado provisório

**47,4/100 no escopo pontuado — experiência fraca.** Não é nota global definitiva. O cálculo cobre dimensões com peso somado de **90%**; paciente e performance/responsividade permanecem não avaliadas como dimensões completas. Não preencher os 10% restantes com zero, média ou suposição.

Notas são avaliação heurística fundamentada, não medidas estatísticas nem resultados de usuários. Maior peso recai em falhas observadas na rotina. Benchmark de qualidade não é ranking objetivo contra concorrentes.

| Dimensão | Peso | Nota | Justificativa |
|---|---:|---:|---|
| Sofisticação e qualidade visual | 15% | 58 | Shell legível e marca presente; excesso de métricas acima da tarefa e chat com outra identidade. |
| Intuitividade e usabilidade | 15% | 46 | Fluxos centrais concluídos; busca ausente, históricos enganosos, recuperação por suporte e comunicação sem entrada. |
| Consistência do Design System | 15% | 44 | CSS compartilhado existe, mas tokens divergem, variantes faltam e chat ignora shell. |
| Eficiência das jornadas clínicas | 15% | 50 | Anamnese versiona, plano publica e consulta conclui; revisão histórica, edição de itens e semântica clínica prejudicam eficiência. |
| Percepção de valor e desejabilidade | 10% | 45 | Juízo heurístico: operação fragmentada e promessas de governança sem controles. Orgulho e recomendação reais ainda exigem pesquisa. |
| Arquitetura da informação | 10% | 48 | Módulos principais visíveis; contexto paciente não persiste em todos os retornos e chat não é descoberto. |
| Experiência do paciente | 5% | Não avaliada | API examinada e falhas comprovadas; app nativo não executado, não há base para pontuar a experiência completa. |
| Performance e responsividade | 5% | Não avaliada | Reflow de 390px falha; sem CWV de campo ou perfil de carga, não sintetizar duas dimensões em nota inventada. |
| Acessibilidade | 5% | 38 | Campos sem nomes associados e modais sem foco; contraste mobile inadequado. Não é certificação WCAG. |
| Confiabilidade e feedback das interações | 5% | 35 | Persistência central comprovada; indicador inventado, fusos divergentes, histórico errado e API com erro oculto. |

Cálculo: soma ponderada = **42.65 pontos** sobre **0.90 de peso observado**; 42.65 ÷ 0.90 = **47.39**. Arredondamento de apresentação: 47,4.

## O que impede 90/100

Aumentar acabamento sem corrigir confiança não elevaria o produto ao patamar premium. Antes de reavaliar: remover dados presumidos e métricas fictícias; restaurar login/evolução do paciente; corrigir histórico; alinhar meta hídrica e unidades; revogar acesso; padronizar shell/modais/campos; provar jornadas em banco limpo e dispositivo real. A faixa 90 requer ainda pesquisa com profissionais, baixo esforço em tarefas representativas, resposta consistente a falhas e cobertura de todas as telas e papéis. Não há garantia de nota futura por concluir backlog.

## Critério de reavaliação

Reaplicar as mesmas tarefas, congelar versão/fontes, medir com usuários e revisar evidências por dimensão. Publicar separadamente cobertura, nota heurística, métricas de uso e bloqueios. Sem dados de campo, não afirmar ganho em retenção, produtividade ou indicação.
