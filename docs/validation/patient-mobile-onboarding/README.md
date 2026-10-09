# Primeiro acesso do paciente pelo app

O nutricionista pode gerar um convite no perfil do paciente no portal (Gerar convite para o app) ou na aba Pacientes do app. A entrega do código é manual, por canal privado. Nenhuma mensagem é enviada automaticamente.

O paciente escolhe Primeiro acesso, informa o código, confere nome, e-mail e celular, cria e confirma a senha e acessa o acompanhamento. O cadastro e o vínculo com o consultório já existem; não há cadastro espontâneo em clínica arbitrária. Dados clínicos e manifestações de consentimento existentes são preservados.

Convites têm validade de 48 horas, são armazenados como hash, aceitos uma única vez e invalidados quando outro convite é gerado. A ativação e a emissão do acesso são uma transação. Contas já ativadas não podem ter a senha substituída por esse fluxo.

## Validação realizada

- PHP sem erros de sintaxe; TypeScript sem erros; exportação Expo web.
- HTTP real em banco SQLite isolado: profissional autorizado, bloqueio de outro consultório e recepcionista, convite inválido, substituído e expirado; confirmação de senha; falha simulada na gravação do acesso e rollback; ativação, uso repetido, login e resumo autenticado.
- Navegador Chrome em 390 × 844: convite gerado pelo profissional, primeiro acesso, cadastro, senha, boas-vindas e acompanhamento. Sem transbordamento horizontal.
- Portal web: ação no perfil, bloqueio sem CSRF e página privada do convite.
- A migração 000025 foi aplicada ao banco local. Nenhum dado ou senha de paciente real foi usado nos testes.

Os testes de transporte ficam em `tests/patient_mobile_onboarding_http_test.cjs` e os de tela em `tests/patient_mobile_onboarding_ui_test.cjs`. Usam a fixture `tests/support/ni2_281_fixture.php` em um novo banco `/tmp/ni2-281-*`. O teste HTTP recebe `DB_DATABASE` e `ONBOARDING_TEST_URL`. O teste de tela recebe `ONBOARDING_UI_URL` e, opcionalmente, `PLAYWRIGHT_MODULE`; executá-lo após o HTTP na mesma fixture e servir a exportação Expo e a API no mesmo domínio. Não executar contra dados reais.

No Expo Go em desenvolvimento, o app resolve o servidor pela máquina do Expo na porta 8080. Builds instalados devem configurar `EXPO_PUBLIC_API_URL` para o endereço da API. A validação de interface foi feita no navegador mobile; não foi feita em dispositivo nativo.
