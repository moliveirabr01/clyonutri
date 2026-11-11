<?php

declare(strict_types=1);

namespace ClyoNutri\Domain\Helpdesk;

/**
 * v21: textos revisados da Central de ajuda (fonte única para seeds, migração 042 e SQL de produção).
 * Descrevem só o que o sistema faz hoje. Chave = slug (não muda, para não quebrar links).
 */
final class HelpArticlesV21
{
    /** @return array<string, array{title:string, summary:string, content_markdown:string}> */
    public static function all(): array
    {
        return [
            "como-redefinir-minha-senha" => [
                'title' => "Esqueci minha senha",
                'summary' => "Como criar uma nova senha pelo link enviado por e-mail.",
                'content_markdown' => <<<'MD'
1. Na tela de entrada, clique em **Esqueci minha senha**.
2. Digite o e-mail da sua conta.
3. Se o e-mail tiver cadastro, você recebe um link para criar uma nova senha. O link vale por 30 minutos e só funciona uma vez.
4. Crie a nova senha, com pelo menos 8 caracteres.

Não chegou? Confira o spam e peça um novo link. Se continuar sem chegar, abra um chamado aqui na Central de ajuda.
MD,
            ],
            "como-elaborar-um-plano-alimentar-com-taco" => [
                'title' => "Como montar um plano alimentar com a TACO",
                'summary' => "Refeições, alimentos da TACO, quantidades e publicação para o paciente.",
                'content_markdown' => <<<'MD'
O catálogo de alimentos usa a Tabela Brasileira de Composição de Alimentos (TACO).

1. No perfil do paciente, clique em **Criar plano alimentar** e dê um nome ao plano.
2. Adicione as refeições do dia (café da manhã, almoço, lanche, jantar…).
3. Em cada refeição, busque o alimento pelo nome e informe a quantidade em gramas ou em medida caseira.
4. Os totais de energia, carboidratos, proteínas, lipídios e fibras são recalculados a cada alimento incluído.
5. Se quiser, cadastre substituições para um alimento.
6. Revise e clique em **Revisar e publicar versão**. A versão publicada aparece para o paciente no app; o rascunho, não.

Se um alimento conflitar com alergias registradas na anamnese, o editor avisa antes de você publicar.
MD,
            ],
            "como-registrar-avaliacao-antropometrica-pollock-7-dobras" => [
                'title' => "Como registrar uma avaliação antropométrica",
                'summary' => "Peso, altura, circunferências e percentual de gordura informado por você.",
                'content_markdown' => <<<'MD'
1. No perfil do paciente, clique em **Nova avaliação antropométrica**.
2. Informe peso e altura (obrigatórios). A altura pode ser digitada em centímetros (170) ou em metros (1,70); em metros, o sistema mostra a conversão e pede sua confirmação.
3. Se quiser, preencha cintura, quadril, pescoço, tórax, braço, coxa e panturrilha, o percentual de gordura e o de massa muscular.
4. O sistema calcula o IMC e a relação cintura/estatura quando a cintura é informada.
5. A avaliação fica privada. Para o paciente ver no app, marque a opção de liberar ao salvar ou use **Liberar** depois (o sistema pede confirmação). Dá para revogar a qualquer momento.

**Dobras cutâneas e protocolos de composição corporal (Pollock, Faulkner, Petroski) ainda não estão disponíveis.** Se você usa adipômetro, calcule o percentual de gordura por fora e registre o resultado no campo de percentual de gordura.
MD,
            ],
            "como-convidar-paciente-para-o-aplicativo" => [
                'title' => "Como convidar o paciente para o app",
                'summary' => "Link, código ou QR code para o primeiro acesso do paciente.",
                'content_markdown' => <<<'MD'
1. No perfil do paciente, clique em **Gerar convite para o app**.
2. Envie o link de ativação pelo WhatsApp (o botão já abre a conversa com a mensagem pronta) ou copie o link e mande por onde preferir. Também dá para mostrar o QR code no consultório ou passar o código de convite.
3. O paciente abre o link, confirma os dados e cria a própria senha. O convite vale por 48 horas.

Se o prazo vencer, gere um novo convite. O app funciona no navegador do celular ou do computador; não precisa instalar nada.
MD,
            ],
            "como-preencher-anamnese-nutricional-estruturada" => [
                'title' => "Como preencher a anamnese",
                'summary' => "O que a anamnese registra e como ela ajuda no plano alimentar.",
                'content_markdown' => <<<'MD'
A anamnese tem três blocos:

- **Alergias alimentares e restrições clínicas**
- **Rotina diária e hábitos de vida**
- **Histórico clínico e meta hídrica prescrita**

Você pode salvar como rascunho e continuar depois. Ao concluir, a anamnese é registrada com data, hora e autor e vira uma versão no histórico (não há assinatura digital); as versões anteriores continuam disponíveis para consulta e comparação.

As alergias registradas aparecem em destaque no perfil e o editor do plano alimentar avisa quando um alimento conflita com elas.
MD,
            ],
            "como-o-paciente-registra-agua-e-refeicoes-no-app" => [
                'title' => "O que o paciente faz no app",
                'summary' => "Plano alimentar publicado, água e diário alimentar.",
                'content_markdown' => <<<'MD'
No app, o paciente:

1. **Vê o plano alimentar publicado**, com as refeições e as substituições que você cadastrou.
2. **Registra a água** do dia com um toque e acompanha a meta.
3. **Registra refeições no diário**, com foto opcional e como se sentiu de saciedade. Você vê esses registros no consultório e pode responder.

Avaliações só aparecem no app quando você as libera.
MD,
            ],
            "como-o-clyonutri-garante-a-privacidade-lgpd" => [
                'title' => "Como os dados dos pacientes são protegidos",
                'summary' => "Isolamento por consultório, senhas, consentimento e acesso do suporte.",
                'content_markdown' => <<<'MD'
- **Cada consultório só vê os próprios dados.** Toda consulta ao banco é filtrada pelo consultório de quem está logado.
- **Senhas** são guardadas cifradas (bcrypt), e o site só funciona por HTTPS.
- **Consentimento:** o termo de tratamento de dados de saúde é registrado com a versão aceita, a data e o canal. O paciente pode retirar o consentimento, e isso fica no histórico.
- **Suporte:** a equipe de suporte vê os chamados que você abre, não os prontuários.

Dúvidas sobre os direitos do titular (acesso, correção, exportação)? Abra um chamado.
MD,
            ],
            "como-funciona-a-sala-de-teleconsulta-e-o-teste-de-dispositivos" => [
                'title' => "Como funciona a teleconsulta",
                'summary' => "Convite, sala de espera, teste de câmera e entrada só com áudio.",
                'content_markdown' => <<<'MD'
1. Na agenda, abra a consulta marcada como teleconsulta e entre na sala.
2. Envie o convite ao paciente por e-mail ou copie o link. O link é pessoal e serve só para essa consulta.
3. O paciente abre o link no navegador (não precisa instalar nada), testa câmera e microfone e aceita o termo da teleconsulta.
4. Ele entra na sala de espera e você admite a entrada.

A chamada é feita direto entre os dois navegadores e não é gravada.

**Sem câmera?** O paciente pode escolher **Entrar apenas com áudio** ou **Continuar sem câmera**. Você vê na sua tela qual modo ele usou.

Se a consulta for remarcada, o convite anterior deixa de valer: envie um novo.
MD,
            ],
            "o-que-fazer-se-a-c-mera-ou-microfone-n-o-forem-autorizados-no-navegador" => [
                'title' => "Câmera ou microfone bloqueados no navegador",
                'summary' => "Como liberar a permissão no computador e no celular.",
                'content_markdown' => <<<'MD'
Quando o navegador bloqueia a câmera ou o microfone, aparece um ícone de cadeado ou de câmera riscada na barra de endereço.

**No computador**
1. Clique no cadeado ao lado do endereço clyonutri.com.
2. Em **Câmera** e **Microfone**, escolha **Permitir**.
3. Recarregue a página da sala de espera.

**No celular**
Abra os Ajustes do aparelho, vá até o navegador que está usando (Chrome, Safari…), libere câmera e microfone e volte para a página.

Se mesmo assim não funcionar, o paciente pode entrar só com áudio.
MD,
            ],
            "como-reagendar-uma-consulta" => [
                'title' => "Como reagendar uma consulta",
                'summary' => "Mude o dia ou o horário sem perder o histórico da consulta.",
                'content_markdown' => <<<'MD'
1. Na **Agenda**, na linha da consulta (agendada ou confirmada), clique em **Reagendar**. No perfil do paciente, o botão fica no quadro *Próximo passo*.
2. O formulário já vem com o horário e a duração atuais. Escolha o novo dia e horário e, se quiser, anote o motivo.
3. Clique em **Confirmar reagendamento**.

**O que acontece**
- A consulta continua a mesma: paciente, tipo, observações, anamnese, avaliações e planos vinculados não mudam.
- O histórico da consulta guarda o horário anterior, o novo, o motivo e quem reagendou.
- Se o novo horário bater com outra consulta, o formulário continua aberto e preenchido, com o horário destacado. Escolha outro ou marque a opção de sobreposição de horário (encaixe).
- Não dá para reagendar para um horário que já passou, nem consultas concluídas, canceladas ou com falta.
- Teleconsulta: o convite anterior deixa de valer. Envie um novo ao paciente.
MD,
            ],
            "como-orientar-o-paciente-no-acesso-ao-link-web-e-aplicativo" => [
                'title' => "Como orientar o paciente no primeiro acesso",
                'summary' => "O que dizer ao paciente para ele entrar no app sem travar.",
                'content_markdown' => <<<'MD'
O paciente entra pelo link ou pelo QR code e cria a própria senha. Não precisa instalar nada.

Vale combinar com ele:
- O link vale por 48 horas. Se vencer, você gera outro.
- Depois do primeiro acesso, ele entra com e-mail e senha em clyonutri.com/paciente/app.
- No app ele vê o plano publicado, registra a água e as refeições do diário.
- Se esquecer a senha, ele fala com você.
MD,
            ],
        ];
    }
}
