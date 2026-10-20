# HostGator — v22 candidato (20/10/2026)

**Pacote preparado; NÃO homologado para produção.** O empacotador oficial bloqueou a release. Consulte `RELEASE_INFO.json` para os checks pendentes. Não trate este ZIP como uma release aprovada.

Commit: 35e6e0f9fe4a4fc0045208569fbe3a1810ff6ca1

O pacote contém o backend PHP, dependências Composer, front-end web, rotas e migrações. Inclui as correções da agenda NI2-10 e os commits posteriores presentes na base. Não contém .env privado, senhas de hospedagem, banco de dados, uploads ou logs. Não contém o build do aplicativo mobile.

## Antes de qualquer atualização de produção

1. Resolver os bloqueios de homologação e repetir os testes HTTP, jornadas web/app e ensaio de deploy/rollback.
2. Fazer backup dos arquivos, de storage/ e do banco MySQL pelo cPanel/phpMyAdmin.
3. Conferir as migrações necessárias para a versão instalada. Para banco na v19, o documento LEIA-ME-HOSTGATOR.md descreve os preflights e scripts 42 a 45 em database/manual/. O documento conserva a identificação v21, pois descreve a base de banco dessas alterações. Não execute migrações que já estejam aplicadas.
4. Preservar o .env e storage/ existentes. A extração deste ZIP deve ocorrer na raiz da instalação, onde ficam index.php e .htaccess.
5. Após homologação e atualização, testar login, agenda, conclusão de consulta, publicação de planos e acesso do paciente.

Este arquivo é um candidato para conferência. Nenhum arquivo foi enviado para a HostGator.
