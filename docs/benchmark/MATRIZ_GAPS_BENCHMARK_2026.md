# Matriz de Gaps e Análise de Benchmark Competitivo — ClyoNutri II

**Data de Consolidação:** 07/10/2026  
**Status:** Validado e Aprovado para Engenharia  
**Referência Estratégica:** `CLYONUTRI_II_MAPA_ECOSSISTEMA_ESTRATEGIA_2026_2030.md`  
**Concorrentes Analisados:** Dietbox, WebDiet, Nutrium, DietSystem, Nutrisoft

---

## 1. Matriz de Capacidades e Gaps por Módulo

| Módulo / Capacidade | Concorrentes Analisados | Baseline Atual ClyoNutri | Gap Identificado | Ação no ClyoNutri II (Onda 1) |
|---|---|---|---|---|
| **Prescrição Alimentar** | **WebDiet / Dietbox:** templates reutilizáveis, equivalências automáticas por grupo de macronutrientes. | Editor básico refeição/alimento e substituições individuais. | Alta fricção para prescrever do zero sem templates; falta de cálculo por grupo de equivalência. | Implementar templates globais e de clínica, cálculo dinâmico de equivalências calóricas/macros (`NI2-114`). |
| **Receitas Culinárias** | **Nutrium / WebDiet:** banco de receitas ilustradas com passo a passo e cálculo nutricional da porção. | Apenas alimentos isolados TACO. | Paciente tem dificuldade de executar preparações culinárias complexas apenas com lista de ingredientes. | Cadastro e vinculação de Receitas com rendimento, ingredientes e modo de preparo (`NI2-114`). |
| **Lista de Compras** | **Nutrium:** agregação automática semanal/mensal de compras com categorização de supermercado. | Paciente precisava somar quantidades manualmente no plano. | Fricção na adesão às compras do mês/semana. | Motor gerador de lista de compras categorizada por gôndola de supermercado a partir do plano ativo (`NI2-116`). |
| **Design & Experiência** | Interfaces antigas, carregadas de verde hospitalar ou com ruído visual excessivo. | Shell Clinical Botanical preliminar. | Falta de consistência em botões secundários, estados vazios e refinamento visual. | Auditoria visual completa com Design System Premium (Navy `#0F2747` / Copper `#E67A3F` / Lucide icons) (`NI2-127`). |

---

## 2. Decisões Arquiteturais e Requisitos para Onda 1

1. **Templates de Prescrição (`meal_plan_templates`):**
   * Podem ser globais do sistema ou criados pelo nutricionista dentro do seu `clinic_id`.
   * Permitem clonar uma estrutura completa de refeições diretamente para o plano de um novo paciente em 1 clique.
2. **Equivalências Nutricionais (`food_equivalences`):**
   * Agrupamento por densidade energética e tipo (Ex: Grupo de Carboidratos: 150g arroz = 180g batata inglesa = 120g mandioca).
3. **Módulo de Receitas (`recipes` + `recipe_ingredients`):**
   * Com cálculo proporcional de nutrientes por porção.
4. **Agregação da Lista de Compras (`ShoppingListService`):**
   * Agrupamento de alimentos e ingredientes de receitas somando gramas/unidades e classificando por departamentos de mercado (Hortifrúti, Carnes, Laticínios, Mercearia, Outros).
