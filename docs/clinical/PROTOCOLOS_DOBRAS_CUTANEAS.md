# Protocolos de dobras cutâneas — NI2-439

**Status:** implementado; **aguardando revisão clínica** (Rafael / nutricionista responsável). Não marcar a NI2-439 como Feita antes da revisão (regra do épico NI2-435).
**Catálogo:** `SkinfoldProtocolCatalog::CATALOG_VERSION = 2026.10-r1`. Qualquer alteração de coeficiente exige nova versão; cada medição grava a versão usada.

## O que foi entregue

- Tela `/pacientes/{id}/antropometria/dobras`, com link na avaliação antropométrica (NI2-7).
- O formulário é guiado pelo protocolo: mostra só as dobras exigidas, com a localização anatômica de cada uma, aceita até 3 leituras por ponto e permite vincular a avaliação antropométrica para usar o peso.
- O cálculo é determinístico (densidade + Siri) e a proveniência fica gravada em cada medição: equação, coeficientes, faixa etária, referência e versão do catálogo.
- Elegibilidade: o sistema recusa sexo ausente, sexo diferente do protocolo e idade fora da faixa validada. Nada é extrapolado.
- Limites: valores fora de 1–80 mm são recusados. Valores fora de 3–50 mm, leituras com diferença acima de 5%, gordura abaixo da essencial ou acima de 50%, salto de dobra (mais de 25% e mais de 3 mm em até 120 dias) e salto de gordura (5 pontos percentuais ou mais em até 30 dias) geram alerta.
- Registros versionados: uma correção cria nova versão, exige motivo e preserva a versão anterior como `superseded`.
- Trilha de auditoria: `SKINFOLD_RECORDED` e `SKINFOLD_CORRECTED`, com usuário, IP, motivo e valor anterior.
- A série temporal usa apenas um protocolo e apenas as versões vigentes. O gráfico em SVG vem com tabela equivalente, texto acessível e fonte dos valores.
- A exportação LGPD inclui as medições.
- Fora de escopo, como pede a história: estimar composição corporal por foto e enviar imagens a terceiros.

## Protocolos e conferência dos coeficientes

| Código | Protocolo | Elegibilidade | Coeficientes conferidos em |
|---|---|---|---|
| `jp7_male` | Jackson & Pollock 1978, 7 dobras | homens 18–61 | Artigo original (Br J Nutr 40:497-504, Tab. 4, eq. 1) |
| `jp3_male` | Jackson & Pollock 1978, peitoral + abdominal + coxa | homens 18–61 | Artigo original (eq. 5) + tabela dos autores: **28 células, diferença ≤ 0,05 p.p.** |
| `jp7_female` | Jackson, Pollock & Ward 1980, 7 dobras | mulheres 18–55 | Fontes secundárias coincidentes, incluindo Jagim et al. 2023 (revisado por pares) |
| `jp3_female` | Jackson, Pollock & Ward 1980, tricipital + suprailíaca + coxa | mulheres 18–55 | Idem. **Atenção:** a tabela clínica de Pollock, Schmidt & Jackson (1980) para mulheres diverge até 1,35 p.p. (somas de 27 e 99 mm); uma fonte traz 0,0001395 para a idade |
| `dw4` | Durnin & Womersley 1974, bicipital + tricipital + subescapular + suprailíaca | homens 17–72, mulheres 16–68 | Fontes secundárias + tabela dos autores: **42 células (30–72 anos), diferença ≤ 0,2 p.p.** |

Conversão: Siri (1961), %G = (4,95/D − 4,50) × 100, a mesma usada pelos autores originais.

Os casos de referência estão em `tests/ni2_439_skinfold_protocols_test.php`:
1. tabelas publicadas pelos autores;
2. 8 casos calculados de forma independente com aritmética decimal de 40 dígitos.

## Checklist da revisão clínica

- [ ] Conferir no artigo original de Jackson, Pollock & Ward (1980) os coeficientes de `jp7_female` e `jp3_female` e explicar a divergência com a tabela clínica de 1980.
- [ ] Conferir a Tabela 3 de Durnin & Womersley (1974), principalmente as faixas 16/17–19 e 20–29, que não têm tabela de % para confronto.
- [ ] Validar as descrições dos pontos anatômicos, que divergem entre JP e DW na suprailíaca, e o lado de medição.
- [ ] Validar os limites e alertas: 1–80 mm, faixa usual 3–50 mm, 5% entre leituras, gordura essencial de 5% (homens) e 12% (mulheres), saltos.
- [ ] Decidir sobre protocolos brasileiros (Petroski 1995, Guedes 1985) e infantis (Slaughter 1988, que exige estágio de Tanner). Ficaram fora até haver fonte conferida.
- [ ] Testar com medições reais (sem dados de pacientes antes de contrato e autorização, conforme NI2-435).

## Referências

- Jackson AS, Pollock ML. Br J Nutr. 1978;40(3):497-504. doi:10.1079/BJN19780152
- Jackson AS, Pollock ML, Ward A. Med Sci Sports Exerc. 1980;12(3):175-181. PMID 7402053
- Durnin JVGA, Womersley J. Br J Nutr. 1974;32(1):77-97. doi:10.1079/BJN19740060
- Siri WE. In: Brozek J, Henschel A, eds. Techniques for Measuring Body Composition. NAS; 1961:223-244.
- Pollock ML, Schmidt DH, Jackson AS. Comprehensive Therapy. 1980;6(9):12-27 (tabelas de % de gordura).
- Jagim AR et al. Front Sports Act Living. 2023;5:1240252.

## Implantação

1. HostGator, pelo phpMyAdmin: primeiro `database/manual/preflight-2026-10-19-dobras-ni2-439.sql`, depois `migracao-2026-10-19-dobras-ni2-439.sql`, sempre depois da migração 43.
2. Ambiente local: `php bin/migrate.php`.
