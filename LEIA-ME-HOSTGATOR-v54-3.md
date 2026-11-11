# Clyo Nutri v54.3: NI2-602, NI2-614 e NI2-616

- **Pacote:** `clyonutri-v54-3-hostgator.zip`
- **Data:** 02/11/2026
- **Base:** v54.1, a versão hoje em produção.
- **Conteúdo:** inclui a v54.2 inteira (NI2-602), que ainda não foi instalada, e acrescenta as correções do NI2-614 e do NI2-616.
- **Banco:** sem mudança.

## O que muda

### NI2-602 (P0): antropometria pelo perfil gravada no paciente certo
Detalhes em `LEIA-ME-HOSTGATOR-v54-2.md`.
- O modal "Registrar 1ª avaliação" deixa de gravar no paciente cujo id é igual ao de um profissional.
- O servidor recusa, com erro 409, quando o paciente do formulário for diferente do paciente da rota.
- Depois de instalar, rode `database/manual/auditoria-ni2-602-antropometria-paciente-errado.sql`. Ele só lê dados.

### NI2-614: avaliação encerrada e o texto "de sem limite"
- **Painel:** quando a assinatura está com o teste encerrado, aparece o aviso "Seu período de teste terminou em DD/MM/AAAA". O aviso explica que o sistema continua liberado e que a contratação pelo sistema ainda não está disponível. O titular também vê o link "Ver assinatura".
- **Configurações > Conta e Senha > Seu plano:**
  - o selo "Avaliação encerrada" passa a ser "Teste encerrado";
  - "período até 22/10/2026" passa a ser "teste encerrado em 22/10/2026";
  - há uma nota explicando que o sistema segue liberado;
  - o contador "8 de sem limite" passa a ser "8 (sem limite)".
- **Regra de cobrança:** não muda. Nada é bloqueado depois do teste, que é a regra já descrita em Configurações > Assinatura. Definir uma regra nova é uma decisão de produto à parte.
- **Nome dos planos:** "ClyoNutri Profissional" continua assim. O código mantém de propósito o nome comercial dos planos (`Brand::textoExibido` exclui Start, Profissional e Clínica Elite, decisão da v49). Para mudar, é preciso alterar o cadastro `saas_plans` e essa regra.

### NI2-616: nome do profissional uma vez no topo
- **Cabeçalho:** mostra o nome do consultório uma vez, em uma linha, com reticências se for longo. A saudação usa só o primeiro nome ("Olá, Maicon"). O nome completo aparece ao passar o mouse.
- **Menu lateral:** o bloco com nome, saudação e "Sair" agora só aparece no celular, abaixo de 769 px, onde o cabeçalho fica escondido. Acima disso, o cabeçalho já mostra essas informações.
- **Arquivos:** CSS e JS passam para a versão `v54-3` no endereço, para que o navegador não use a cópia antiga. O rodapé mostra "v54.3".

## Como foi testado (testes fora do zip)
| Teste | Resultado |
|---|---|
| NI2-602 (HTTP) | v54.1: 1/7. v54.3: 7/7 |
| NI2-614 e NI2-616 (navegador, 375, 800, 1024 e 1280 px) | 16/16. Nome completo 1 vez no topo; cabeçalho com 64 px; "Olá, Maicon"; aviso no painel; "8 (sem limite)"; 0 violações de CSP |
| Regressão | v54.1: 38/38, 6/6 e navegador 30/30. v47/v48: 205/206; a falha já existia na v53 (`v47_ni2_537`) |

## Instalação
1. Faça backup dos arquivos.
2. Envie o zip com o mesmo mapeamento da v54.1.
3. Limpe o OPcache, se a hospedagem permitir.
4. Confira:
   - o rodapé mostra "v54.3";
   - o topo mostra o consultório uma vez e "Olá, {primeiro nome}";
   - o painel avisa sobre o teste encerrado, para contas nessa situação;
   - com um paciente TESTE, "Registrar 1ª avaliação" volta para `/pacientes/{id do paciente}/antropometria`.
5. Rode a auditoria do NI2-602.

## Rollback
Restaure os arquivos da v54.1. Não há mudança de banco para desfazer.
