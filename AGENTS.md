# Base de trabalho do ClyoNutri

- A codebase canônica é `/Users/moliveira/Nutri_II`. Faça aqui as alterações, validações e a execução local.
- Não use extrações de ZIP ou pastas `release-v*` como codebase de desenvolvimento. Elas são artefatos ou referências históricas.
- Preserve alterações locais, `.env`, banco, uploads e sessões. Não substitua a árvore por um pacote de release sem reconciliar as diferenças e preservar o trabalho existente.
- Execute localmente com `./bin/serve-local.sh`, que fixa a raiz do projeto e usa `127.0.0.1:8081` por padrão. A porta 8080 pode estar ocupada pelo Talui.
- Pacotes de entrega devem ser gerados a partir desta codebase, identificando a versão e os testes executados. Não presuma que uma versão extraída em outra pasta já foi integrada aqui.
