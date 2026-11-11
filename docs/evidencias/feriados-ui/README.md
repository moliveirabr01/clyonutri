# Revisão visual dos feriados — 01/11/2026

O estilo “Cadastro à vista” aprovado foi aplicado à tela real de Configurações. O formulário de feriado fica visível, a lista de datas usa cartões compactos e bloqueios/aberturas e buffers/lista de espera ficam em seções expansíveis.

Descrição de novos feriados: Feriado municipal, Feriado estadual ou Feriado nacional. A mesma lista é validada no serviço de calendário. Registros já cadastrados não são renomeados nem apagados. Bloqueios e aberturas especiais continuam aceitando descrição própria.

Não há nova migração nesta revisão. O pacote cumulativo continua exigindo a migração 69; quem já a aplicou não precisa executar SQL adicional para mudar o estilo e as opções.

Validação desta revisão: 41 verificações SQLite de calendário/lista de espera (incluindo as três categorias e rejeição de categoria inválida), 28 de autoagendamento e 27 de confirmação; sintaxe PHP dos arquivos alterados. Conferência do template real com CSS do produto no Chrome, em computador e em viewport móvel de 390px sem transbordamento horizontal. Dados da captura são sintéticos. Não é captura de produção nem homologação completa de login/envio no navegador.

Os resultados MySQL 8 na pasta agenda-v50 são históricos da revisão anterior. MySQL/HostGator e SMTP da revisão atual não foram homologados. Não houve deploy nem escrita no banco da hospedagem.
