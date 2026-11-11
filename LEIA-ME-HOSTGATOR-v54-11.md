# Clyo Nutri v54.11: NI2-606 (equipe) e NI2-608 (busca de paciente)

- **Pacote:** `clyonutri-v54-11-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.10, que ainda não foi instalada, e acrescenta estas duas histórias.
- **Banco:** sem mudança. **Não há SQL para rodar.** Usa colunas e tabelas que já existem (`users`, `clinic_members`, `professionals`, `password_resets`, `audit_logs`).

## NI2-606: Configurações > Equipe
Nova aba **Equipe** em Configurações (`/configuracoes/equipe`), entre "App da Paciente & Avisos" e "Conta e Senha".

**Quem gerencia:** o titular da clínica e quem tem o papel "Nutricionista gestor(a)". Nutricionistas e recepção veem a lista, sem botões.

**Lista da equipe:** nome, e-mail, CRN, papel e situação (Ativo, Convite pendente, Inativo), com o contador **"Pessoas na equipe: X de Y do plano"**.

**Convidar:**
- Campos: nome, e-mail, papel (Nutricionista, Nutricionista gestor(a) ou Recepção), CRN e região. O CRN é obrigatório para nutricionistas.
- A pessoa recebe um link para **criar a própria senha**: vale 72 horas e funciona uma vez. Ninguém digita senha pelos outros.
- Se o servidor estiver enviando e-mail, o link vai por e-mail.
- Se não estiver (como hoje, com `MAIL_DRIVER=log`), o link aparece **uma única vez** na tela, para copiar e mandar por um canal seguro. Ele não fica no histórico nem nos logs.
- Convite pendente já ocupa vaga do plano.
- **"Reenviar convite"** gera um link novo e invalida o anterior.

**Limite do plano:** com todas as vagas ocupadas, o formulário dá lugar ao aviso "Seu plano permite N pessoas na equipe e todas as vagas estão ocupadas", com o botão **Trocar de plano**. O limite vem de `saas_plans.max_professionals` (Start 1, Profissional 3, Elite sem limite). A recepção também conta como vaga; se o produto decidir que não deve contar, é uma linha.

**Papel:** troca entre Nutricionista e Nutricionista gestor(a). A recepção não vira nutricionista por aqui, porque isso exigiria CRN e outro cadastro.

**Desativar:**
- A pessoa sai do sistema na hora: as sessões abertas são encerradas.
- O histórico clínico dela fica preservado, e a vaga do plano é liberada.
- Se tentar entrar, vê "Seu acesso a esta clínica foi desativado. Se isso não era esperado, fale com o gestor da clínica." Antes, ela cairia em um vaivém entre login e painel.
- **Reativar** devolve o acesso, se houver vaga.

**Proteções:** ninguém altera o próprio acesso nem o do titular; cada ação confere se a pessoa é da mesma clínica; todo formulário tem CSRF.

**Histórico da equipe:** convites, entregas, trocas de papel, desativações e reativações, com data e autor (`audit_logs`, ações `TEAM_*`).

**No celular:** a lista vira um bloco por pessoa, sem rolagem lateral.

## NI2-608: busca de paciente no novo agendamento
A lista de resultados da busca em **Agenda > Novo agendamento** ganhou estilo próprio do design system:
- itens com 39 px de altura e respiro lateral;
- destaque ao passar o mouse e foco visível ao usar o teclado (contorno cobre de 3 px);
- **seta para baixo** no campo vai para o primeiro paciente, setas percorrem a lista, **seta para cima** no primeiro volta ao campo, **Enter** escolhe;
- **Esc** fecha só a lista e devolve o foco ao campo. Antes, fechava o formulário inteiro. Um segundo Esc fecha o formulário, como antes.
- "Nenhum paciente encontrado" e "+ novo paciente" com o mesmo estilo.

## Arquivos
**Novos:**
- `src/Domain/Team/TeamService.php`
- `src/Application/Controllers/TeamController.php`
- `views/settings/team.php`
- `views/settings/_tabs.php` (abas de Configurações em um lugar só)

**Alterados:**
- `public/index.php` (rotas `/configuracoes/equipe`)
- `AuthController.php` (mensagem de acesso desativado)
- `DashboardController.php` (versão do `agenda-form.js`)
- `views/settings/profile.php` (usa `_tabs.php`)
- `Icon.php` (ícone `users`)
- `agenda-form.js`, `clinical-journeys.css`
- `views/layouts/professional-shell.php` (rodapé v54.11)

## Como foi testado (testes fora do zip)
| Teste | Resultado |
|---|---|
| Domínio (`v5411_ni2_606_team`) | 21/21 |
| Navegador (`p5411.js`) | 32/32 |
| Regressão | navegador v54.1 30/30, NI2-603/604/605/611 25/25, NI2-609 9/9, NI2-607 14/14, NI2-610/612/615 31/31; PHP 21/21, 38/38, 6/6, 15/15, 24/24, 3/3, 71/71, 42/42, 87/87; `v47_ni2_537` com a falha já conhecida da v53; 0 violações de CSP e 0 erros de JavaScript |

O teste de navegador faz o caminho completo com dados TESTE: convite sem CRN (erro, campos preservados), convite com link exibido uma vez, convidada cria a senha e entra, nutricionista comum sem botões, recepção leva ao limite (3 de 3) com "Trocar de plano", troca de papel, desativação (sessão encerrada e mensagem no login), reativação, histórico sem o link, 375 px sem rolagem lateral, e a busca de paciente pelo teclado.

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`) e os passos da v54.10 (`storage/sessions/` com escrita). Depois:
1. Confira o rodapé "v54.11".
2. Abra **Configurações > Equipe**. O contador deve mostrar o plano da clínica.
3. Para testar o convite, use um e-mail **TESTE** (por exemplo `@teste.invalid`) e desative a pessoa ao final, para liberar a vaga.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco. Convites criados ficam como usuários com situação "invited", sem senha utilizável, e podem ser desativados depois.
