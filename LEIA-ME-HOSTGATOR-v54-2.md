# Clyo Nutri v54.2: NI2-602 (antropometria gravada no paciente errado)

- **Pacote:** `clyonutri-v54-2-ni2-602-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão hoje em produção.
- **Banco:** **não há mudança de banco.** O único SQL desta versão é de auditoria e só lê dados.

## O que corrige
**O defeito:** no perfil do paciente, o modal "Registrar 1ª avaliação" enviava a avaliação para o id de um *profissional* da clínica em vez do paciente aberto.
- Isso acontecia sempre que o bloco "Liberar chat" estava visível.
- Quando havia, na mesma clínica, um paciente com aquele número, peso e medidas eram gravados nele, e a tela confirmava "Avaliação salva".
- O problema existe desde a migração 48 (chat), em 22/10/2026.

**A causa:** `views/patients/chat-link.php` usava `$p` como variável do laço de profissionais e sobrescrevia o `$p` do paciente no `DashboardController`.

**A correção:**
- `chat-link.php` passa a usar `$opt` no laço.
- O modal lê o id do paciente de `$profile['patient']['id']`.
- O servidor recusa com 409, sem gravar nada, quando o `patient_id` do formulário é diferente do paciente da rota.

## Como foi testado (testes fora do zip)
| Teste | v54.1 | v54.2 |
|---|---|---|
| HTTP, perfil do paciente 2 numa clínica cujo profissional tem id 1 | 1 PASS, 6 FAIL; a avaliação foi gravada no paciente 1 | 7 PASS |
| Navegador, clique em "Registrar 1ª avaliação" e "Salvar" | Volta para `/pacientes/1/antropometria?success=...`, o paciente errado | Volta para `/pacientes/2/...`; 0 violações de CSP |

Regressão: v54.1 com 38/38 e 6/6, navegador 30/30, v47/v48 com 205/206. A única falha já existia na v53 (`v47_ni2_537`).

## Instalação
1. Faça backup dos arquivos.
2. Envie o zip com o mesmo mapeamento da v54.1.
3. Confira o rodapé: deve mostrar "v54.2".
4. Faça um teste com um paciente TESTE: abra o perfil, clique em "Registrar 1ª avaliação" e salve. A tela seguinte deve ser `/pacientes/{id do paciente}/antropometria`.

## Depois de instalar: auditoria dos dados
Rode no phpMyAdmin `database/manual/auditoria-ni2-602-antropometria-paciente-errado.sql`. Ele só lê dados.
- O arquivo lista as avaliações gravadas desde 22/10/2026 em pacientes cujo id é igual ao de um profissional da mesma clínica.
- Ser suspeito não prova o erro. Cada linha precisa ser conferida com o nutricionista responsável.
- Correção de dado clínico só com decisão da clínica, nunca automática.

## Rollback
Restaure os arquivos da v54.1. Não há mudança no banco para desfazer.
