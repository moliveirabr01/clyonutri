# ClyoNutri v35 — atualização sobre v34

Base: pacote clyonutri-v34.zip fornecido pelo proprietário. Todas as mudanças anteriores foram preservadas, incluindo notificações do backoffice e ajustes de interface. Consulte COMPARACAO-V34-V35.txt para a lista exata de diferenças.

## Instalação sobre a v34

1. Faça backup dos arquivos e banco antes da atualização.
2. Extraia o pacote na raiz da instalação, preservando o .env real, uploads, anexos, sessões e demais dados existentes em storage/. O pacote contém apenas os diretórios vazios originais de storage; não os use para substituir/apagar dados do servidor. Não substitua configurações próprias do servidor sem conferir.
3. Não há nova migração ou SQL de estrutura para quem já está na v34. Não execute SQL histórico de assinaturas como parte desta atualização. As instruções antigas continuam no LEIA-ME-HOSTGATOR-v34.md para referência.
4. Na raiz da aplicação, com o PHP e a configuração do ambiente de produção, execute `php bin/seed.php` sem --demo. Esse comando já atualizava o catálogo nutricional; agora também instala as seis receitas de exemplo públicas. Pode repetir sem duplicar exemplos. Não cria usuários de demonstração, não publica receitas particulares e não reinicia avaliações. Se houver falha, guarde a mensagem e não considere a instalação concluída.
5. Verifique com uma nova conta: escolha um plano no passo 1; no passo 4 clique em “Cadastrar paciente depois”; conclua o onboarding; abra Receitas e confira os seis exemplos. Verifique também sino/notificações no backoffice e a tela de assinatura de uma conta existente.

## Comportamento

- Planos ativos vêm do catálogo, com preços e limites. Somente titular/admin em teste pode escolher; assinatura contratada não é trocada pelo onboarding. Não inicia cobrança ou renova prazo de teste.
- Adiar o paciente avança ao passo 5 sem criar registros, mesmo com formulário parcialmente preenchido. Cadastro permanece disponível em Pacientes.
- Receitas de exemplo são institucionais e públicas. As cópias privadas existentes continuam privadas. Não é preciso refazer o onboarding para vê-las.

## Verificação e limites

Testes isolados passaram sobre o código deste pacote: seleção de planos, permissões, isolamento, período de teste e ausência de cobrança; adiamento do paciente e conclusão; catálogo global, detalhes das receitas e instalação repetida. Verificação de release e sintaxe PHP registradas em VALIDACAO-V35.txt. Não houve teste no servidor HostGator, SMTP real, gateway ou navegador autenticado em produção. Ausência absoluta de regressão não pode ser garantida por estas verificações.

## Reversão

Restaure os arquivos da v34 e o backup do banco, caso necessário. O código v34 também consegue ler as receitas públicas inseridas, mas restaurar o banco é a forma de retornar integralmente ao estado anterior. Não apague receitas pelo título: podem existir cópias privadas e prescrições vinculadas.
