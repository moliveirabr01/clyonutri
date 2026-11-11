# ClyoNutri II — v54.57 candidata (correções da auditoria UX/UI 360°)

Base: pacote v54.56 (build v54.56-40c47b8cc25ddbdd; linha v54.55 → v54.56 → v54.57), preservado.

O que muda (achados da auditoria UX 360° da v54.56):
- UX-103: rascunho de anamnese com campo cifrado ilegível não trava mais a tela. Aviso nomeia o campo; "Recuperar rascunho" mantém o legível, esvazia só o corrompido e guarda o conteúdo original na auditoria. Versões concluídas nunca são alteradas.
- UX-102: portal do paciente (/paciente/app) com sessão real depois do login: plano vigente (/paciente/plano), consultas, registro de água e sair. Saíram os cartões que não faziam nada.
- UX-104 / UX-111 / R55-01: 403, 404, 500 e 503 em HTML com layout, mensagem e caminho de volta; API e XHR continuam recebendo JSON. A recepção não vê links para telas que não pode abrir.
- UX-101 / UX-105 / UX-119: o que é concluído dentro do atendimento em andamento conta como etapa concluída. A faixa do atendimento mostra tipo, data e hora e as etapas (Anamnese, Avaliação, Plano, Revisão) com link direto.
- UX-106: datas no fuso do consultório e no formato dd/mm/aaaa hh:mm (rascunho, convite do app "válido até … (horário de Brasília)", acompanhamento de prescrição).
- UX-108: com anamnese concluída, a alergia aparece só para leitura no cadastro, com a origem e o link "Editar na anamnese". Não existe mais uma segunda alergia paralela.
- UX-114 (parcial): na nova avaliação, a altura vem preenchida da última avaliação, com a data.
- UX-110 / UX-109: o painel tem "Buscar paciente" e "+ Nova consulta" no topo. Com a prontidão completa, as preferências viram uma linha opcional. No celular, a ordem do painel da v54.56 foi mantida e a saudação ficou compacta para a busca e o botão caberem na primeira dobra.
- UX-124: "Último atendimento" volta a aparecer depois de concluir a consulta. A consulta usava uma coluna inexistente.
- UX-115: "Comparar com a versão anterior" na aba Plano quando há 2 ou mais versões publicadas.
- UX-113: "Sessão expirada" oferece "Entrar novamente", que volta à mesma tela.
- UX-112: falha de rede em pt-BR.
- UX-126: Permissions-Policy sem 'bluetooth'.
- UX-120: "Sem dados" da adesão em texto menor e neutro; "Planos a revisar" só fica em destaque quando há algo a revisar; nomes da agenda da semana em até 2 linhas.
- UX-121 / UX-122: links isolados e o aceite dos termos com alvo de 24 px; contorno de foco visível nos campos de data e hora (inclusive no botão do calendário no Chrome).

Rotas novas:
- POST /pacientes/{id}/anamnese/recuperar-rascunho
- GET /paciente/plano
- POST /paciente/hidratacao
- POST /paciente/app/sair

Nenhuma migration nem dependência nova. Consultas sempre filtradas pelo consultório (clinic_id). Login, CSRF e permissões continuam como estavam. Não substitua .env, banco, uploads nem sessões.

CANDIDATA LOCAL (CANDIDATE_LOCAL_VALIDATION). Validada em SQLite com dados sintéticos, no Chromium e no Firefox. Ainda faltam MySQL 5.7/HostGator, aparelhos físicos e leitor de tela real. Nenhum deploy foi feito.
Reversão: restaure os arquivos da v54.56. Não há alteração de esquema.
