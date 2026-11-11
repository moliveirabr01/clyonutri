# Validação — aplicativo do paciente / backend v54.32

Execução final aprovada em 4 de novembro de 2026. Detalhes, data UTC e hashes em evidence/run.json.

## Resultados

- TypeScript: aprovado.
- Expo Web: exportação aprovada.
- Android e iOS: bundles Hermes exportados com sucesso.
- Cliente TypeScript real → API PHP v54.32: **18 verificações aprovadas**. Login, plano publicado, orientações confirmadas, porções/substituições, meta/hidratação, consulta, reenvio idempotente de água e diário, persistência, receita e preparo, evolução compartilhada, privacidade, avisos, isolamento de clínicas e logout.
- Interface: **137 verificações aprovadas em 39 estados**, cobrindo as seis áreas, quatro larguras (320/375/390/768), landscape, espaçamento, Chrome/Firefox/WebKit, zoom nativo Chrome 200/400, sessão restaurada, consumo real, hidratação/histórico, check-in, rascunho de chat, fila offline e estado vazio de outra clínica.
- Zero violações axe-core 4.14.0 detectadas nos estados finais. Inconclusivos e observações de overflow constam em ui-report.json.
- Fila de hidratação: suíte herdada com 20 verificações aprovadas, executada separadamente.
- Preservação: serviços de API/fila/push, tela e tokens profissionais e bloco de estado/efeitos/gravações do paciente comparados com a base e inalterados.

Backend confirmado antes e depois: v54.32-973e7dfeaf062a5c; conteúdo SHA-256 973e7dfeaf062a5cdd77142b16d1d5142f384116ea9eb2b792fab34171033111. Instância local e banco SQLite sintético, schema 087. Nenhum arquivo de execução do backend alterado.

## Suítes antigas que precisam de manutenção

A execução exploratória de npm run test:auth falhou na fixture anterior de /Users/moliveira/Nutri_II: ela tenta publicar plano com uma refeição e é recusada pela regra de adequação existente. Essa fixture usa o backend do repositório antigo, não a release v54.32 usada na validação final.

O teste herdado ni2_323_mobile_client_regression_test.cjs extrai texto do componente para executar em VM e alcança chamadas useAnnounce sem definir o hook. A mesma chamada já existe no código base. Esses testes não foram alterados para mascarar falhas; registros em evidence/legacy-*.log. A integração desta entrega usa os testes reais novos com a v54.32 e valida persistência e reenvio.

## Evidências e limites

today.png, plan.png e evolution.png são capturas da aplicação real com dados sintéticos. Não representam registros, avaliações ou resultados de pessoas reais. O gráfico usa avaliações sintéticas efetivamente gravadas e compartilhadas pelo fluxo profissional.

A validação nativa em aparelho/simulador, leitor de tela, teclado/safe areas, permissões, desempenho físico e publicação continuam pendentes. Não há evidência de aprovação Android/iOS em loja, certificação WCAG ou execução no HostGator. As auditorias NI2-323/362 não foram encerradas por esta entrega.
