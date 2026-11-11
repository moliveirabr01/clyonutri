# Fontes de composição de alimentos e licenças (NI2-520, v43, 29/10/2026)

Regra seguida: só entra no banco o que tem licença que permite claramente o uso num produto pago. Cada valor importado guarda fonte, versão, código na fonte e data da importação (`foods.source`, `source_version`, `source_code`, `source_imported_at`). Nenhum valor foi digitado à mão nem estimado.

| Fonte | Versão conferida | Licença (trecho) | Decisão |
|---|---|---|---|
| TACO (NEPA/Unicamp) | 4ª ed. rev. e ampl., 2011 | "É permitida a reprodução parcial ou total desta obra, desde que citada a fonte." (página de créditos do PDF) | Já estava no ClyoNutri. A v43 só grava edição, código e data nas 594 linhas e marca o preparo. Valores não mudam. |
| USDA FoodData Central, Foundation Foods | CSV 2026-04-30 | "USDA FoodData Central data are in the public domain and they are not copyrighted. They are published under CC0 1.0 Universal" | Importador pronto e testado. **Não aplicado**: aguarda sua decisão (nomes ficam em inglês). |
| TBCA (FoRC/USP) | 7.3 (2025) | Creative Commons BY-NC-ND 4.0: sem uso comercial e sem obra derivada | **Bloqueada.** Só com autorização escrita (tbca.contato@usp.br). |
| IBGE POF 2008-2009, composição e medidas referidas | 2011 | "© IBGE. 2011", sem concessão de reuso no livro nem no Plano de Dados Abertos 2024-2025 | **Não importada.** Medidas caseiras continuam as que já existiam. |
| Open Food Facts | atual | ODbL 1.0 (compartilhamento pela mesma licença do banco derivado) | Não importada: obrigaria a abrir a base derivada. |
| Guia alimentar / listas de substituição do Ministério da Saúde | vários | uso não comercial | Não importada. |
| Philippi, Tabela de composição e lista de equivalentes | livro | direitos reservados à editora | Não utilizável. |

## Links

- TACO 4ª ed.: https://nepa.unicamp.br/wp-content/uploads/sites/27/2023/10/taco_4_edicao_ampliada_e_revisada.pdf (sha256 `2002aec5615b5b1395aaa8fa675635bbb7f712c33f278af5e332f1cac8f108c8`)
- USDA FDC, licença: https://fdc.nal.usda.gov/api-guide (seção Licensing). Download: https://fdc.nal.usda.gov/download-datasets
  - Arquivo usado no teste: `FoodData_Central_foundation_food_csv_2026-04-30.zip`, sha256 `70457ee9d9342f43bda2010318c85f04210c689fdeb9cd2da4c513b0e8dbc655`.
  - O pacote 2026-10-29 publicado hoje veio sem `food.csv` e sem `food_nutrient.csv` (1,1 MB contra 3,8 MB); por isso o teste usou o de 2026-04-30.
  - Citação pedida pelo USDA: U.S. Department of Agriculture, Agricultural Research Service. FoodData Central. fdc.nal.usda.gov.
- TBCA: https://www.tbca.net.br/
- IBGE POF 2008-2009, Tabela de medidas referidas: https://biblioteca.ibge.gov.br/visualizacao/livros/liv50000.pdf

## USDA: o que o importador faz (teste em MySQL 5.7.44, banco TESTE)

- 469 alimentos no arquivo; 378 entram; 91 ficam de fora porque o arquivo não traz energia para eles (o ClyoNutri não calcula o que a fonte não publicou).
- Energia: Atwater específico quando existe, depois Atwater geral; gordura total pela NLEA quando falta a medida direta; carboidrato por diferença; fibra em branco quando não informada. Cada escolha fica em `nutrient_flags`.
- Segunda execução: 378 puladas (mesma fonte, versão e código). Nada da TACO muda (`taco_com_valor_alterado = 0`).
- Uma versão nova do USDA entra como linhas novas; as antigas ficam com `is_current = 0` e continuam valendo nos planos que já as usam.
- Comando: `php bin/importar_usda_fdc.php --dir=<pasta do CSV> --dry-run` (só conta). Com `--apply` grava. Precisa da migração 63.

## Pontos para revisão de nutricionista

- O rótulo de preparo (cru, cozido, grelhado...) é lido do nome do alimento na fonte.
- As regras de equivalência são da clínica: cada uma exige fonte citada, nasce pendente e só vale depois que um nutricionista com CRN marca como revisada. O sistema não traz regras prontas.
- Os valores do USDA são de alimentos americanos; quem decide usar é a clínica.
