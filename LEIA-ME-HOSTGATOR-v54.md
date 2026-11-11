# Clyo Nutri v54.1: auditoria de UX, rodada 1 (NI2-583, histórias NI2-584 a NI2-600)

- **Pacote:** `clyonutri-v54-1-ni2-583-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v53 em produção (`clyonutri-v53-ni2-567-hostgator.zip`), com as migrações até a 72.
- **Status:** candidato, ainda **não instalado em produção**.

Esta versão é o pacote v54 recebido com as correções do code review (`claude/NI2-583-code-review-pacote-v54.md`). Ela substitui o zip `clyonutri-v54-ni2-584-600-hostgator.zip`, que **não deve ser instalado**.

## O que mudou em relação ao pacote v54 recebido

| Achado | Correção |
|---|---|
| B1: faltava o SQL das migrações 73, 75 e 76 | SQL manual para as migrações 73, 75 e 77, em três arquivos: preflight, completa e verificação. Sem as colunas novas, o código continua funcionando como na v53. |
| B2: o selo "Sem alergias" contradizia a anamnese | O selo, o resumo clínico e a trava de alérgenos usam a mesma fonte: a anamnese mais recente e, quando ela não informa, o cadastro. "Nega alergias" limpa o texto de alergia. As observações livres não viram mais alergia, só geram o aviso "confira". |
| B3: medidas caseiras com peso errado (migração 76) | A migração 76 foi retirada até haver fonte e validação clínica. |
| B4: link público abria outro profissional e era enumerável | O link usa um apelido único gravado (`nome-xxxxxx`, com índice único). Não há mais busca por nome nem `nutri-{id}`. O link com número redireciona (302) para o apelido. O endereço só aparece com uma opção própria, desligada por padrão. Não há mais valor de R$ 250,00 inventado. |
| B5: o script da agenda era bloqueado pela CSP | A lógica foi para `agenda-form.js` (arquivo externo). O aviso de "mesmo dia" usa a data escolhida no formulário. O encaixe não libera mais o "mesmo dia", e a confirmação aparece mesmo sem JavaScript. |
| B6: o menu "Mais ações" ficava cortado | O menu abre em posição fixa, calculada pelo botão. Os três itens recebem clique na última linha. |
| UX-001: horários publicados sem confirmação | A migração 74 foi retirada e nada é gravado ao abrir uma tela. A conta antiga vê "Confirme seus horários", com a sugestão das 08:00 às 18:00 pré-preenchida. O link público continua sem horários até a nutricionista confirmar. |
| Regressões da v53 | Voltaram a prévia de disponibilidade (NI2-567), a fila de confirmação ao agendar, o nome "Clyo Nutri" nos e-mails, o aviso clínico das dobras e o aviso do comparador. Também voltaram as fontes do dompdf (`DejaVuSans*.ufm.json`). |
| Outros | Publicação: energia e macronutrientes fora de 80–120% e planos com menos de 3 refeições pedem motivo, e a mensagem diz "abaixo" ou "acima" da meta. `issueRequest` foi removido. `return_to` só aceita caminho interno. Links antigos `/configuracoes#...` abrem a aba certa. Um plano vazio não oferece "Imprimir plano". |

O trabalho do pacote recebido que não pertence às histórias (anamnese estruturada NI2-223, notificações) continua no pacote. Os e-mails de boas-vindas, de fim do período de avaliação e de chamados, além do link `/verificar-email`, voltaram ao estado da v53, porque a rota de confirmação não existia.

## SQL (phpMyAdmin, banco `maic6824_Clyonutri2`)
Rode na ordem abaixo os arquivos de `database/manual/`:
1. `atualizacao-v54-preflight.sql`: somente leitura. Guarde o resultado.
   - O item 1 precisa mostrar as migrações 69 a 72.
   - O item 2 precisa vir **vazio**. Se aparecer a migração 74 ou a 76, o pacote rejeitado chegou a rodar: pare e avise.
2. `atualizacao-v54-completa.sql`: cria as colunas das migrações 73, 75 e 77. Pode rodar duas vezes sem efeito.
3. `atualizacao-v54-verificacao.sql`: o resultado esperado é:
   - as 3 migrações registradas;
   - 4 colunas novas;
   - 23 colunas da anamnese;
   - o índice único;
   - `0 / 0 / 0` no item 5;
   - as mesmas contagens do preflight.

Os nomes de banco, tabela e coluna são comparados em binário. Isso evita o erro `#1267 Combinação ilegal de collations` em bancos `utf8_unicode_ci`, como o de produção, e foi corrigido em 02/11, às 02h. Se o nome do banco for outro, ajuste `@db` no arquivo completo e o nome do banco nos outros dois.

**Instalação limpa:** `instalacao_mysql8.sql` (vai até a migração 68), depois `atualizacao-v53-consolidada-completa.sql` e por fim `atualizacao-v54-completa.sql`.

**Testado em MariaDB 10.11:**
- banco v53 (instalação mais SQLs v50 a v52) com a v54.1 rodada duas vezes;
- caminho pela v53 consolidada;
- `php bin/migrate.php` sem pendências;
- esquema igual ao das migrações PHP.

## Atualização dos arquivos
1. Faça backup do banco e dos arquivos. Preserve `.env`, `storage/` e os uploads.
2. Rode o SQL acima **antes** de enviar os arquivos. O código também funciona sem o SQL, mas sem as alergias no cadastro e sem o apelido do link.
3. Envie o conteúdo do zip para a pasta da aplicação, com o mesmo mapeamento de `public/` e `.htaccess` da instalação atual. Não substitua `.env` nem `storage/`.
4. Limpe o OPcache se a hospedagem permitir. Os arquivos JS e CSS têm a versão `v54-1` no endereço, então não ficam presos em cache.
5. Confira:
   - o rodapé "v54.1";
   - Configurações > Agenda: o link mostrado é o apelido, e contas antigas mostram "Confirme seus horários";
   - na agenda: a busca de paciente, o aviso de mesmo dia e "Mais ações" com Cancelar;
   - o perfil de um paciente TESTE com alergia.

**Links antigos com número:** continuam funcionando, com redirecionamento para o apelido, enquanto `BOOKING_LEGACY_NUMERIC_LINKS` não estiver como `false` no `.env`. Data sugerida para desligar: **01/12/2026**, depois de reenviar os links novos aos pacientes. Desligado, `/agendar/{número}` responde 404.

## Rollback
- Restaure os arquivos da v53.
- As colunas novas podem ficar no banco: a v53 não as usa.
- Não restaure o banco por cima de operações novas.

## Pendências (fora deste pacote)
- **Medidas caseiras (UX-009):** precisam de uma tabela com fonte por linha e de revisão por nutricionista antes de voltar.
- **Configurações > Agenda:** a aba continua longa (cerca de 3.500 px). Encurtar exige reorganizar o calendário de feriados.
- **Enumeração pela API:** as chamadas de horários (`/api/v1/booking/...`) ainda usam o número do profissional. Fechar isso exige mudar o app da paciente.
- **Validação clínica** da regra de publicação (macronutrientes e mínimo de 3 refeições) e da regra de alergia.
