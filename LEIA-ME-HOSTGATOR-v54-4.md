# Clyo Nutri v54.4: NI2-602, NI2-614, NI2-616 e NI2-617

- **Pacote:** `clyonutri-v54-4-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.3, que ainda não foi instalada (NI2-602, NI2-614 e NI2-616), e acrescenta o NI2-617.
- **Banco:** sem mudança.

## Novo nesta versão: NI2-617
Pela minimização de dados da LGPD, a tela de onboarding deixa de mostrar o e-mail e o telefone do primeiro paciente:
- **Passo 4, resumo "Paciente cadastrado com sucesso":** só aparece o nome. O e-mail e o telefone continuam nos campos do formulário logo abaixo, para edição.
- **Passo 5, "Primeiro paciente vinculado":** só aparece o nome, com o botão "Abrir prontuário".

Arquivo alterado: `src/Application/Controllers/OnboardingController.php`.

## Como foi testado (testes fora do zip)
| Teste | Antes (v54.3) | Depois (v54.4) |
|---|---|---|
| NI2-617, HTTP nos passos 4 e 5 | 3 PASS, 2 FAIL: e-mail e telefone aparecem como texto | 5/5: só o nome; o campo de e-mail continua editável no passo 4 |

**Regressão:** v54.1 com 38/38 e 6/6; v47/v48 sem novas falhas.
- `v47_ni2_537` continua com a falha que já existia na v53.
- `v47_ni2_529_telas` alterna entre passar e falhar no item "sobra é listada", porque um arquivo da execução anterior fica na pasta temporária. Isso não tem relação com esta versão: passou ao rodar de novo.

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
- o rodapé deve mostrar "v54.4";
- abra `/onboarding?passo=5` e confira que o primeiro paciente aparece só com o nome.

Depois, rode a auditoria do NI2-602, que só lê dados.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer.
