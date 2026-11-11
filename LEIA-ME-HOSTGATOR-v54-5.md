# Clyo Nutri v54.5: NI2-609 (medida "unidade" sem 100 g genéricos)

- **Pacote:** `clyonutri-v54-5-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.4, que ainda não foi instalada (NI2-602, NI2-614, NI2-616 e NI2-617), e acrescenta o NI2-609.
- **Banco:** sem mudança. Nenhuma medida caseira foi cadastrada pelo pacote.

## Novo nesta versão: NI2-609 (UX-R3-08)
Antes, "1 unidade" valia 100 g e "1 fatia" valia 30 g para qualquer alimento, desde que a nutricionista marcasse "Confirmo o peso estimado".

Agora:
- **Seletor de unidade** (modais "Adicionar alimento" e "Adicionar substituição" do plano, e nova receita):
  - a lista geral tem só **g** e **ml**;
  - os utensílios (colheres e xícara) continuam com peso padrão e confirmação;
  - **unidade** e **fatia** só aparecem no grupo "Medidas deste alimento", quando o alimento tem a medida cadastrada.
- **Origem do peso:** abaixo da unidade aparecem as medidas do alimento com a origem de cada uma:
  - "medida da clínica · fonte: …";
  - "medida da base geral do sistema";
  - "porção do cadastro do alimento".
- **Alimento sem medida:** aparece o aviso "use gramas ou ml" e o link **Cadastrar medida caseira ↗**, que abre `/medidas-caseiras?food=ID`. A fonte é obrigatória nesse cadastro.
- **Servidor:** recusa "unidade", "fatia" e "porção" sem medida cadastrada, mesmo com a confirmação. Vale para:
  - item novo;
  - substituição nova;
  - ingrediente de receita nova;
  - item ou substituição em que a unidade foi trocada.

  Itens e receitas já gravados não mudam: um item antigo em "unidade" ainda pode ter a quantidade alterada.

**O que depende da equipe clínica:** cadastrar as medidas (por exemplo, a do pão francês) em Alimentos > Medidas caseiras, uma a uma ou pelo CSV modelo, com a fonte de cada linha. Até lá, prescreva em gramas.

**Diferença em relação ao critério da história:** o critério diz "só gramas e mililitros". Colheres e xícara continuam disponíveis, com peso padrão e confirmação, porque a seção "O que fazer" da história cita só unidade e fatia. Para tirá-las também, basta mudar uma linha em `UnitCheckView::unitOptions()`.

### Arquivos alterados
- `src/Domain/Nutrition/HouseholdMeasureConverter.php`: regra de contagem e origem/fonte da medida.
- `src/Application/Controllers/UnitCheckView.php`: grupos do seletor e espaço para as medidas.
- `public/assets/js/unit-check.js`: lista de medidas com fonte e link de cadastro.
- `src/Application/Controllers/FoodSearchController.php`: `/conversao` recusa contagem sem medida e devolve a fonte e o link.
- `src/Domain/MealPlan/MealPlanService.php`: bloqueio em item e substituição.
- `src/Domain/Recipe/RecipeService.php` e `src/Application/Controllers/RecipeController.php`: bloqueio na receita nova.
- `src/Application/Controllers/ClinicMeasuresController.php`: texto após remover medida.
- `src/Application/Controllers/DashboardController.php`: versão no endereço do JS.
- `public/assets/css/clinical-journeys.css`, `views/layouts/professional-shell.php`: estilo, rodapé v54.5 e versão `v54-5` nos endereços.

## Como foi testado (testes fora do zip)
| Teste | Resultado |
|---|---|
| NI2-609, domínio (`v545_ni2_609`) | 15/15: pão sem medida recusa unidade e fatia; com a medida da clínica, 1 unidade = 50 g sem estimativa e com fonte; o item antigo mantém a unidade |
| NI2-609, navegador no modal do plano | 9/9: sem unidade/fatia na lista; aviso e link de cadastro; com a medida, "unidade (50 g)", a fonte visível, sem confirmação, gravado com 50 g; 0 violações de CSP |
| Regressão | v54.1 38/38 e 6/6; v47/v48 sem novas falhas (`v47_ni2_537` com a falha que já existia na v53) |

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
- o rodapé deve mostrar "v54.5";
- em um plano de paciente **TESTE**, abra "Adicionar alimento", busque "pão francês" e confira que "unidade" não aparece e que há o link "Cadastrar medida caseira".

Depois, rode a auditoria do NI2-602, que só lê dados.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer.
