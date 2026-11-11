# ClyoNutri v53 — NI2-567 — candidato HostGator

Build: ni 2-567-v53-20261101. **Não instalado em produção.**

## Antes de enviar
Este é um candidato com 15 correçõesUX; a liberação depende da homologação descrita emIMPLEMENTACAO-E-VALIDACAO.md. O épico exige validação clínica, E2 E e revisãoUX/PM ainda pendentes. O ZIP externo contém documentos/evidências; **somente clyonutri-v53-ni2-567-hostgator.zip é o código para enviar**.

## SQL
**Não há SQL novo.** Instalação existente nav 52 com migrações até 72: atualização somente de arquivos. Se não estiver nessa base, pare e confira as migrações anteriores antes de instalar. Os arquivos anteriores permanecem emdatabase/manual no pacote, mas não devem ser executados cegamente.

## Atualização
1. Faça backup dos arquivos e banco atuais. Preserve.env, storage/uploads, certificados de assinatura e segredos locais.
2. Homologue oZIP interno em ambiente isolado com dados fictícios e canais controlados; execute o roteiro de aceite.
3. Depois da aprovação, envie o conteúdo doZIP interno para a pasta da aplicação, com o mesmo mapeamento depublic/ e.htaccess da instalação atual. Não substitua.env oustorage por cópias vazias, nem envie a pastaantes/ ou evidências para a área pública.
4. Confira permissões de escrita da pasta storage usada pela aplicação. Não mude SMTP, push, cron ou políticas da clínica por causa deste pacote.
5. Confira login, buildv 53 no shell, agenda, comparador, exames e retorno ao atendimento. Reinicie/limpeOPcache conforme a hospedagem e recarregue ativos se necessário. RELEASE_INFO.json registra build e fingerprint.

## Rollback
Como não há nova migração, restaure os arquivos da versão anterior comprovadamente instalada, sem restaurar o banco sobre novas operações. Preserve consultas, documentos, histórico e configurações alterados após a atualização. Não use os arquivosparciais deantes/ como substituto de um backup completo do servidor.

## Conteúdo da entrega
- Código completo comvendor e migrações existentes.
- Patch revisável e lista de arquivos.
- Relatório de implementação com 15 histórias e limites de validação.
- Logs de 350 verificações, imagens e medições do navegador.
- Cópia do pacote da auditoria de origem e hashes para conferência.
