# Clyo Nutri v54.16: pendências da NI2-635 (anamnese adaptativa, ainda desligada)

- **Pacote:** `clyonutri-v54-16-hostgator.zip`
- **Data:** 03/11/2026
- **Base:** v54.15, a versão em produção desde 03/11/2026.
- **Banco:** sem mudança. **Não há SQL para rodar.**

## Nada muda para os usuários ao instalar
Tudo continua atrás da chave global `feature_flag_clinical_protocols`, que fica **0**. O pacote não traz protocolo clínico.

## NI2-640: sugestões entendem negação e outra pessoa
A busca no texto da anamnese usa regras transparentes, sem serviço externo. O texto é dividido em trechos (frases, vírgulas, "mas"/"porém"):

| Texto | Resultado |
|---|---|
| "Não tenho doença X", "Nega X", "X descartado" | ignorado (negação) |
| "Minha mãe tem X", "Histórico familiar de X", "Minha mãe, que tem X, …" | ignorado (outra pessoa) |
| "Acho que tenho X", "Não sei se tenho X" | sugestão marcada com dúvida |
| "Já tive X" | sugestão marcada como relato antigo |
| "Paciente não fuma e tem X" | sugestão (a negação não alcança o novo verbo) |
| "Tenho X", "X não controlada" | sugestão |

- **Comparação:** sem acentos, sem diferença de maiúsculas e só com palavra inteira ("X" não casa dentro de "préX").
- **Motivo da sugestão:** fica gravado e aparece na tela, por exemplo: *Sugerido porque o texto diz: "Acho que tenho X". O texto indica dúvida.*
- **Aviso depois da busca:** diz quantas sugestões foram criadas e o que foi ignorado, com o trecho e o motivo.
- **Sugestão não ativa nada:** continua precisando de confirmação do profissional.

## NI2-642: dispensar um módulo com motivo
- **Dispensar:** cada módulo ativo tem "Dispensar este módulo", com motivo obrigatório. A condição continua registrada; só o módulo sai do formulário.
- **Reativar:** os dispensados aparecem em "Módulos dispensados", com data e motivo, e o botão "Reativar".
- **Histórico:** dispensa e reativação ficam no histórico da condição. Não há tabela nova.

## NI2-652: conflito de gravação e comparação
**Conflito:** o formulário leva a marca da última gravação. Se outra pessoa (ou outra aba) salvou depois que a página abriu:
- nada é gravado;
- aparece "Outra pessoa salvou respostas destes módulos…";
- o formulário mostra as respostas atuais;
- uma caixa lista o que a pessoa digitou e não foi gravado.

**Comparação:** "Comparar com a conclusão anterior" mostra, por módulo, as datas e uma tabela Pergunta / Antes / Depois com os rótulos das opções. Só versões concluídas entram; rascunho não.

## NI2-655: dados de saúde fora de logs e métricas
- **Auditoria de leitura** (`PROTOCOL_DATA_VIEWED`): guarda só quantidades. O código do protocolo saiu, porque revelaria a condição.
- **Métricas de uso:** a lista é fechada (plano visto, check-in, hidratação) e o fluxo de protocolos não grava métrica. O bloco não carrega script externo.

## Verificação (fora do zip)
- **Portão:** `tests/run_release_gate.sh`.
- **Teste novo** `v5416_ni2_635_pendencias_test.php`: 39 verificações, 15 delas de frases de detecção.
- **Jornada nova `p5419`** (19 verificações):
  - sugestão com familiar e dúvida, explicada;
  - dispensar e reativar;
  - conflito entre duas abas;
  - comparação;
  - 375/656/1280/1440px sem rolagem lateral;
  - dispensa pelo teclado.
- **MariaDB 10.11 local:** dispensa, conflito e comparação.

## Arquivos
**Novos:**
- `src/Domain/ClinicalProtocol/TermDetector.php`
- `src/Domain/ClinicalProtocol/ProtocolSaveConflict.php`

**Alterados:**
- `src/Domain/ClinicalProtocol/PatientConditionService.php`
- `src/Domain/ClinicalProtocol/ProtocolWorkspace.php`
- `src/Application/Controllers/ClinicalProtocolController.php`
- `public/index.php` (2 rotas)
- `views/patients/_adaptive_anamnesis.php`
- `public/assets/css/clinical-journeys.css`
- `views/layouts/professional-shell.php` (v54.16)

## Instalação
1. Extraia o zip sobre a pasta atual.
2. Não há SQL.
3. Confira o rodapé: **v54.16**.
