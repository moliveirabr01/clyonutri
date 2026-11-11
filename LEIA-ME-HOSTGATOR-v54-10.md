# Clyo Nutri v54.10: NI2-603, NI2-604, NI2-605 e NI2-611

- **Pacote:** `clyonutri-v54-10-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão em produção.
- **Conteúdo:** traz tudo da v54.9, que ainda não foi instalada, e acrescenta estas quatro histórias.
- **Banco:** sem mudança. **Não há SQL para rodar.**

## NI2-605 (UX-R3-04, P1): anamnese concluída × rascunho
**Regra nova:** o resumo clínico, o selo do perfil, a origem do plano e a revisão de publicação usam a **última versão concluída** da anamnese. Um rascunho mais novo aparece como **"Alteração em andamento (v2, salva em DD/MM HH:MM)"**, com o botão "Continuar". Ele não rebaixa a versão concluída para "pendente".

**Defeito de segurança corrigido junto:** a trava de alérgenos lia a versão mais recente, mesmo que fosse rascunho.
- Antes: um rascunho v2 com o campo de alergias vazio fazia o sistema **esquecer o amendoim** da v1 concluída. O estado virava "não avaliado".
- Agora: a trava usa a v1 concluída **mais** o que o rascunho acrescentar. Um rascunho nunca tira uma alergia. Uma alergia digitada no rascunho já protege antes da conclusão e aparece marcada como "alteração em andamento".
- A mesma regra vale para a lista de compras e para os alertas de receitas.

**Sem nenhuma versão concluída:** continua "pendente", como antes.

## NI2-603 (UX-R3-02, P1): agendamento online "ligado" sem horários
Com o agendamento online ligado e os horários não confirmados (ou sem dias de atendimento):
- **Link público (`/agendar/...`):** mostra "Agenda online ainda não disponível. Fale com o consultório", sem calendário e sem "escolha outro dia".
- **Sem horário livre nos próximos 14 dias:** o link mostra um aviso e mantém o calendário, porque datas mais adiante podem ter horário.
- **Onboarding:** não mostra "100%" nem "Consultório pronto para atender". Mostra "Ativar agendamento online: confirme seus horários", com o botão que leva direto à confirmação.
- **Já resolvido na v54.8:** painel, agenda e Configurações.

## NI2-604 (UX-R3-03, P1): sessão encerrada sem aviso
**Causa provável:** havia duas, e as duas foram tratadas.
- O cookie de sessão vencia 2 horas depois do login, mesmo com uso contínuo.
- As sessões ficavam na pasta comum da HostGator, sujeita à limpeza padrão de 24 minutos.

**O que mudou:**
1. **Cookie:** é renovado a cada página.
2. **Pasta de sessões:** fica em `storage/sessions`, protegida pelo `.htaccess` que já existe em `storage/`. A limpeza segue o tempo de inatividade da plataforma mais 10 minutos.
3. **Aviso:** 5 minutos antes do fim por inatividade, aparece "Sua sessão vai encerrar por inatividade", com **Continuar conectado**.
   - Antes de avisar, o sistema confere o tempo no servidor; outra aba pode ter renovado a sessão.
   - Digitar ou clicar na página renova a sessão, no máximo a cada 5 minutos. Assim, um formulário longo não expira enquanto você escreve.
4. **Login:** mostra o motivo ("encerrada depois de 120 minutos sem uso", "sua sessão terminou" ou "acesso encerrado") e, depois de entrar, volta para a página em que você estava.
   - Só aceita caminho interno do sistema. Links externos, `/login` e `/api` são recusados.

**Na HostGator:** a pasta `storage/sessions/` precisa ter permissão de escrita para o PHP. Se não tiver, o sistema continua usando a pasta padrão do servidor e funciona como antes.

**Efeito da troca de pasta:** quem estiver logado na hora da instalação precisa entrar de novo, **uma vez**.

## NI2-611 (UX-R3-10): "assinada" sem assinatura verificável
**Textos trocados:**

| Antes | Depois |
|---|---|
| "Anamnese concluída e assinada" | "concluída e registrada, com data, hora e autor" |
| "a evolução será assinada e registrada de forma autêntica" | "registrada com data, hora e autor, e protegida por código de integridade. Não há assinatura digital (ICP-Brasil)" |
| "Concluir consulta e assinar prontuário" | "Concluir consulta e registrar no prontuário" |
| "Assinar e gravar no prontuário" | "Gravar no prontuário" |
| "Assinatura digital (SHA-256)" e "Assinatura raiz da cadeia" | "Código de integridade (SHA-256)" |

Também foi corrigido o artigo da Central de Ajuda.

**Continuam iguais, de propósito:**
- os manipulados, que têm assinatura CMS verificável de verdade;
- a linha "Assinatura e carimbo" para assinatura à mão no PDF de exames, que já avisa que não há assinatura digital;
- o uso de "assinatura" no sentido de plano contratado.

**Fica para o roadmap:** a assinatura digital (ICP-Brasil), como item separado.

## Arquivos
**Novos:**
- `src/Domain/Anamnesis/AnamnesisVersions.php`
- `src/Core/Http/SafeReturnPath.php`
- `public/assets/js/session-timeout.js`

**Alterados:**
- `AnamnesisService.php`, `AllergenSafetyValidator.php`, `ShoppingListService.php`, `RecipeService.php`
- `DashboardController.php`, `OnboardingController.php`, `BookingController.php`, `AuthController.php`
- `public/index.php` (sessão e rotas `/sessao/estado` e `/sessao/renovar`)
- `views/booking/public_booking.php`, `views/auth/login.php`, `views/layouts/professional-shell.php`
- `HelpArticlesV21.php`, `clinical-journeys.css`

## Como foi testado (testes fora do zip)
| Teste | Resultado |
|---|---|
| Domínio (`v5410_ni2_603_604_605_611`) | 21/21 |
| Navegador (`p5410.js`) | 25/25 |
| Regressão | navegador v54.1 30/30, NI2-609 9/9, NI2-607 14/14, NI2-613 8/8, NI2-610/612/615 31/31; PHP 38/38, 6/6, 15/15, 24/24, 71/71, 42/42, 87/87; `v47_ni2_537` com a falha da v53; 0 violações de CSP e 0 erros de JavaScript |

O teste de domínio cobre:
- v1 concluída com v2 rascunho;
- v1 que nega alergias com rascunho que traz camarão;
- só rascunho;
- duas versões concluídas;
- rascunho que "nega" não apaga alergia;
- caminhos de volta inválidos;
- os textos de assinatura.

O teste de navegador cobre:
- link público, onboarding e painel antes e depois de confirmar os horários; depois, há horário nos próximos 14 dias;
- perfil, plano e revisão de publicação com a v1 concluída e a v2 em andamento;
- textos sem "assinada";
- cookie renovado, aviso de fim de sessão, "Continuar conectado", motivo no login e volta para a página de origem;
- caminho externo ignorado.

## Instalação
Siga o LEIA-ME da v54.3 (`LEIA-ME-HOSTGATOR-v54-3.md`), com estas diferenças:
1. Confirme que `storage/sessions/` existe e tem permissão de escrita (755 ou 775, com o mesmo dono do PHP).
2. Confira o rodapé "v54.10".
3. Em um paciente **TESTE** com anamnese concluída, salve uma alteração como rascunho. O perfil deve mostrar "v1 (Vigente)" e "Alteração em andamento".
4. Abra o link de agendamento em uma janela anônima. Se os horários ainda não foram confirmados, deve aparecer "Agenda online ainda não disponível".

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco. As sessões voltam para a pasta padrão, e quem estiver logado entra de novo uma vez.
