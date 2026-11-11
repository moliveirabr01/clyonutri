# Android de teste no EAS

Perfil: preview, distribuição interna, APK instalável. Projeto @moliveirabr/clyonutri-mobile. API configurada no ambiente preview: https://clyonutri.com. Não houve deploy do backend e a versão publicada não foi confirmada.

## Preparação

- SDK Expo 51 preservado; React Native alinhado a 0.74.5 e expo-splash-screen 0.27.7 incluído, conforme o SDK instalado.
- Splash Android com fundo #102D28; geração nativa local conferiu a existência de splashscreen_background.
- Perfil preview explicitamente configurado para APK e ambiente preview.
- Arquivo de envio limitado ao app (manifesto archive.json); credenciais Android remotas existentes reutilizadas.
- Regressão repetida após os ajustes: 137 verificações de interface em 39 estados, 18 verificações do cliente/API contra backend v54.32 local, TypeScript e exportações Hermes Android/iOS aprovados. Evidências na pasta ../evidence.

## Tentativas

1. 9f9b8ed8-161d-4c55-bb36-bce458e5feeb: falhou antes da instalação de dependências por divergência entre /tmp e /private/tmp no caminho do projeto enviado.
2. 54ec0cb5-dd42-4106-8703-0d80fd853537: reenvio com caminho canônico /private/tmp. Concluído com sucesso. Resultado e artefato em build.json. APK baixado, integridade ZIP e assinatura Android v1/v2 verificadas.

## Limites

A instalação e o uso em aparelho físico ainda precisam ser testados. O aplicativo de teste acessa o sistema configurado em clyonutri.com; usar uma conta de paciente apropriada para testes. Não houve publicação em loja.

A auditoria de dependências identificou problemas já existentes no SDK legado, incluindo tar 6.2.1. A atualização ampla do SDK e suas dependências precisa de rodada própria antes de uma distribuição de produção.

## Instalar no Android

Abrir https://expo.dev/accounts/moliveirabr/projects/clyonutri-mobile/builds/54ec0cb5-dd42-4106-8703-0d80fd853537 no celular, baixar o APK e instalar. Se solicitado, autorizar a instalação por esse navegador. Não requer Expo Go nem manter o Mac ligado.
