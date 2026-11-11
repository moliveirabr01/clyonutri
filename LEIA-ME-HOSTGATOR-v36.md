# ClyoNutri v36 — biblioteca de receitas e onboarding

Atualização sobre a v35. Preserva a base v34 enviada pelo proprietário, inclusive a central de notificações e os ajustes visuais.

## Instalação

1. Faça backup dos arquivos e do banco.
2. Extraia e publique os arquivos na raiz da instalação, preservando o .env real e todo o conteúdo existente em storage (anexos, uploads, sessões etc.). Não substitua nem apague os dados do servidor usando os diretórios vazios do pacote.
3. Não há nova migração de estrutura nem SQL de assinaturas nesta atualização.
4. Abra a Biblioteca de receitas com uma conta profissional autenticada. Os seis exemplos institucionais ausentes são preparados automaticamente, incluindo os alimentos essenciais de referência caso estejam ausentes. Não é necessário terminal, rodar seed manualmente ou refazer o onboarding.
5. Busque “Mingau” e selecione “Café da Manhã”: deve aparecer o exemplo de mingau de aveia com banana. Teste também “Limpar filtros”, busca por ingrediente e limite de calorias.
6. No onboarding, confirme que a seleção de plano continua disponível e que meta de pacientes/mês e tempo de atuação foram removidos. O primeiro paciente continua opcional.

## Detalhes da correção

A v35 inseria receitas apenas ao executar manualmente o instalador de catálogo. A v36 verifica os exemplos ao abrir a biblioteca autenticada e instala somente os curados, sem publicar receitas particulares. Execuções repetidas não recriam exemplos existentes. Em MySQL um bloqueio nomeado serializa as instalações simultâneas. Se houver falha, a página mostra um alerta específico e permite consultar receitas existentes; uma visita posterior tenta novamente.

Busca textual, calorias e refeição agora chegam corretamente à consulta. Busca também encontra ingredientes. O resultado vazio permite limpar os filtros.

Os campos de meta de pacientes e tempo de atuação foram removidos da tela e não recebem mais valores inventados na persistência do passo 1. As especialidades e a escolha do plano foram preservadas.

## Validação e limites

Veja VALIDACAO-V36.txt. O cenário da imagem foi reproduzido com o controlador real, sessão profissional e banco SQLite isolado inicialmente sem catálogo. Não houve teste no servidor HostGator ou MySQL real. Após publicar, confira os pontos acima; validação local não garante ausência absoluta de regressão.

## Reversão

Restaure os arquivos anteriores; as receitas públicas criadas são compatíveis com v34/v35. Para retornar integralmente ao estado anterior, use o backup do banco. Não exclua receitas pelo título, pois podem estar vinculadas a prescrições ou ter cópias particulares.
