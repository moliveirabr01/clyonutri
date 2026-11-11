# ClyoNutri v40 · Sem avisos de "revisão de advogado" nas telas (28/10/2026)

**SQL novo na v40: NÃO.** A v40 não muda banco, migração nem SQL. Os três arquivos `database/manual/atualizacao-v39-*.sql` (migração 60) são **os mesmos da v39, byte a byte**, e **continuam aguardando o seu OK**; não rodei nada em produção. A v40 funciona com ou sem esse SQL, como a v39.

A v40 é a **v39** (sha256 `f4c5e4607b64b4e99e5d2de4e65e46c5dc1821b9c5a059d9ddfeb8a63f589027`) com **quatro arquivos de código alterados**, só de texto de tela. Tudo o mais é byte a byte o da v39 (veja `COMPARACAO-V39-V40.txt`). O pacote não traz `.env` nem chaves.

## O que saiu das telas

| Onde aparece | Texto removido | O que ficou |
|---|---|---|
| **Configurações > Assinatura > Excluir a conta e os dados** (titular do consultório) | O rótulo laranja **"RASCUNHO PARA REVISÃO DE UM ADVOGADO"** e a nota **"Texto provisório, ainda sem validação jurídica (versão rascunho-2026-10-28)"** | Os cinco parágrafos do aviso sobre retenção de prontuário, prazo e o que a equipe faz, sem mudança |
| **Termo da teleconsulta** (tela de espera do paciente) | **"— texto sujeito a revisão jurídica e regulatória (NI2-253)"** | O texto do termo e a linha **"Versão 2026.10-r1"** |
| **Admin > Comercial > pedidos de exclusão** (equipe) | **", ponto a validar com advogado"** | "…respeitando a retenção legal de prontuário) e registre o desfecho aqui." |

Também saiu a constante `AccountDeletionRequestService::LEGAL_DRAFT_LABEL`, que só servia ao rótulo.

**Não mudei o conteúdo legal:** Termos de Uso, Política de Privacidade, os parágrafos do aviso de exclusão e o texto do termo de teleconsulta estão iguais à v39.

## O que ficou, e por quê

- **Comentários de código** que citam "rascunho para revisão de um advogado" (`views/legal/terms.php`, `views/landing/plans.php`, `src/Domain/Legal/LegalDocuments.php`, `LegalAcceptanceGate.php`, `AccountDeletionRequestService.php`, a migração 60). Não aparecem para ninguém no sistema.
- **Documentos internos** (LEIA-ME antigos, relatórios, `docs/`): podem citar a revisão. O `LEIA-ME-HOSTGATOR-v25.md` ainda diz que os Termos e `/planos` são rascunho. Isso é aviso para você, não para clientes.
- **`LEGAL_NOTICE_VERSION = 'rascunho-2026-10-28'`**: é um identificador interno, gravado em `account_deletion_requests.legal_notice_version` quando alguém pede a exclusão. **Não aparece em nenhuma tela** (a linha que o mostrava foi removida). Mantive o valor para não mudar o que o sistema grava. Se quiser outro nome, é uma mudança de uma linha, mas aí os pedidos já gravados ficariam com o nome antigo.
- **Reaceite dos Termos desligado** (`LEGAL_REACCEPTANCE_ENABLED`): continua desligado por padrão; é uma chave do `.env`, não um texto.
- **Outros "rascunho"** (anamnese, plano alimentar, pré-consulta, teleconsulta, lista de compras) são rascunhos clínicos de uso normal e **não foram tocados**.

## Banco de dados: nada a limpar

Procurei em todos os textos gravados por migração ou SQL manual (`consent_terms` da teleconsulta, da pré-consulta e do atendimento clínico, artigos da Central de ajuda, versões dos Termos e da Política). **Nenhum tem o aviso de revisão de advogado**; ele só existia no código das telas. Portanto **não há SQL de limpeza**. As versões dos documentos e a tabela `user_legal_acceptances` **não foram tocadas**, e nenhum aceite foi invalidado.

## Atenção

Tirar o aviso **não significa que o texto foi revisado**. O aviso de exclusão, o termo de teleconsulta e as regras comerciais dos Termos (teste de 14 dias, renovação, reembolso, valores) continuam sem revisão jurídica. A decisão de publicar assim é sua.

## Antes de subir os arquivos

1. **Backup** do banco e dos arquivos, como sempre (o banco não muda).
2. **`.env`** e **`storage/`**: não mexa. O zip só traz `storage/.htaccess` e pastas vazias.
3. **Arquivos:** pelo Gerenciador de Arquivos, envie `clyonutri-v40.zip` para `/home3/maic6824/clyonutri.com`, use **Extract** (sobrescrever) e apague o zip do servidor. Limpe o cache e abra com Ctrl+F5.

## Conferência depois de subir

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- Titular de uma clínica **TESTE**: Configurações > Assinatura mostra "Excluir a conta e os dados" com os parágrafos do aviso e **sem** o rótulo laranja nem a nota de texto provisório.
- Super admin: `/admin/comercial` (pedidos de exclusão) sem "ponto a validar com advogado".
- Paciente em uma teleconsulta de teste: o termo termina em "Versão 2026.10-r1", sem o aviso.

## Validação e limites

- Teste novo `tests/v40_sem_aviso_de_advogado_test.php` (não vai no zip): varre `views/`, `src/` e `public/` sem os comentários e falha se achar "advogado", "revisão jurídica", "validação jurídica", "texto provisório" etc. Falha na v39 (4 achados) e passa na v40.
- `tests/v37_plano_perfil_test.php` ajustado: antes exigia o rótulo na tela; agora exige que ele não exista.
- Suíte completa (121 arquivos): 47 falhas, **todas já existentes na v39** (45 herdadas + `ni2_292_mobile_app_patient_and_professional_e2e_test` e `px_13_clinical_language_and_enum_sanitization_test`, dois testes que dependem da hora do dia; o `px_13` também falha na v39 neste horário). O `dashboard_review_fixes_test`, instável, falhou na v39 e passou agora. Nenhuma falha nova.
- Teste HTTP de fumaça (servidor PHP local + SQLite): ver `PACOTE-V40-2026-10-28.md`.
- **Não rodei nada no banco real de produção.**
- O app mobile foi conferido: não tem nenhum texto de revisão de advogado (só termos de retenção legal do prontuário, que ficam).
- Todos os limites da v37, v38 e v39 continuam valendo.

## O que vem no zip

- `clyonutri-v40.zip`: o sistema inteiro com `vendor/` pronto. Arquivos 0644, pastas 0755. `clyonutri-v40.zip.sha256` ao lado.
- Na raiz: LEIA-ME v34 a v40, `COMPARACAO-V34-V35.txt` a `V39-V40`, `VALIDACAO-V35.txt`, `VALIDACAO-V36.txt`, `RELEASE_INFO.json`.
- Não vão no zip: testes, o app mobile, `.git`, `.env` e dados de `storage/`.

## Voltar atrás

Extraia por cima o `clyonutri-v39.zip` e limpe o cache. Não há nada no banco para desfazer.
