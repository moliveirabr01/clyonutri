# Aplicativo do paciente — design da referência

Implementado diretamente em /Users/moliveira/Nutri_II/mobile, usando a imagem fornecida pelo usuário como direção visual. Base do mobile: código existente, commit de referência 7d0c597; sem reset de alterações ou aplicação dos patches Android preexistentes.

## Visual e experiência

Paleta do paciente em src/theme/patient.ts: verde floresta, menta, superfícies claras e amarelo pontual. Tipografia, campos, cartões arredondados, botões amplos e ícones do mesmo conjunto. A tela Hoje prioriza o plano e as refeições antes dos atalhos e acompanhamento. Cabeçalho escuro com resumo real e ação para abrir o plano. Evolução usa superfície verde suave e dados reais do histórico compartilhado.

Hoje, Plano, Diário, Chat, Evolução e Perfil permanecem disponíveis. As seis abas têm ícones decorativos, rótulos e seleção acessíveis; a navegação admite rolagem horizontal quando necessário. Não foram escondidos recursos para reproduzir as quatro abas da imagem.

O círculo representa água confirmada e a meta retornada pelo backend. Sem meta, mostra somente ml. Não representa adesão, calorias restantes ou progresso estimado. Nenhuma foto de alimento, estatística, sequência de dias ou meta foi inventada. Os textos, doses, orientações e substituições continuam vindo da prescrição publicada.

Login e primeiro acesso usam a mesma linguagem visual. A entrada profissional compartilhada recebe o novo acabamento; a tela profissional e seus tokens permanecem inalterados.

## Preservação

API, autenticação, fila de hidratação, notificações push e lógica de estado/efeitos/gravações do PatientHomeScreen permanecem iguais ao código base. Conferência em evidence/preservation.json. Os componentes foram ajustados visualmente, preservando callbacks e contratos.

A leitura de orientações gerais da v54.32 foi testada com um plano sintético publicado após confirmação profissional sintética. O app não cria, aprova ou aplica sugestões dietéticas: apenas apresenta o texto já publicado.

Foram corrigidos nomes acessíveis que divergiam dos textos visíveis (atalho do plano, registro de outro consumo e avisos), além do alvo para marcar avisos como lidos. Avisos não lidos também têm indicação textual. Sem mudanças nas regras clínicas ou no backend.

## Dependências e compilação

@expo/vector-icons 14.1.0 já estava instalado pelo Expo e passou a ser dependência explícita. O SDK Expo 51 foi preservado. Na preparação posterior do APK EAS, React Native foi alinhado a 0.74.5 e expo-splash-screen 0.27.7 foi incluído, conforme versões esperadas pelo SDK. Assets de marca preservados.

TypeScript, exportação Expo Web e bundles Hermes Android/iOS aprovados. As exportações são validação técnica, não APK/IPA nem publicação nas lojas. Os arquivos de propostas de patches EAS/Android já existentes foram preservados. Os ajustes necessários foram conferidos e implementados diretamente para o build EAS posterior; consultar eas-android/ para os registros.

## Repetir a validação

Requer PHP/PDO SQLite, dependências já instaladas do projeto, Playwright e axe-core. Informar o diretório da release v54.32 e os caminhos dos módulos disponíveis no ambiente:

    cd /Users/moliveira/Nutri_II/mobile
    PATIENT_BACKEND_ROOT=/caminho/para/release-v54-32 \
    PLAYWRIGHT_MODULE=/caminho/para/playwright \
    AXE_MODULE=/caminho/para/axe-core \
    CHROME_PATH="/caminho/para/Chrome" \
    node tests/patient-reference-verify.cjs

O executor confere o manifesto da v54.32 antes/depois, cria banco SQLite e servidor locais temporários, prepara dados sintéticos, usa o cliente TypeScript real e o front controller PHP original e remove banco/servidor temporários ao terminar. Não há mocks de resposta nem envio de chat/convites. As fontes externas e serviços de produção não são utilizados.

O host de verificação é de mesma origem. Para uso real, configurar EXPO_PUBLIC_API_URL com a URL base do backend, sem acrescentar /api/v1. Conferir HTTPS, configuração de origem/cookies e ambiente de implantação. A integração local não certifica CORS, infraestrutura ou implantação no HostGator.

## Limites

Android/iOS passaram pelas exportações JavaScript/Hermes. A geração posterior de APK é registrada em eas-android/. Não foram testados em aparelhos físicos ou simuladores nativos; teclado virtual, safe areas, retorno do sistema, permissões, TalkBack e VoiceOver precisam dessa rodada. Axe não certifica WCAG integral. Sem atualização de Jira, deploy ou publicação em loja nesta entrega.
