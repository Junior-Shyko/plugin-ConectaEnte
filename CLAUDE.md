# ConectaEnte: regras de trabalho

Plugin do Mapas Culturais v5 para o CultBR (Conecta Ente). Repositório próprio, dentro de `src/protected/application/plugins/ConectaEnte`.

## Remotos e branches

- `origin` = `culturagovbr/plugin-ConectaEnte` (equipe, sem CodeRabbit).
- `fork` = `Junior-Shyko/plugin-ConectaEnte` (conta pessoal, com CodeRabbit). Os PRs de revisão saem daqui.
- Branch de issue nova: `git switch -c issue-N-slug --no-track origin/feature/v5`. Atualize `origin/feature/v5` antes.

## Fluxo para cada issue

1. Leia a issue inteira: `gh issue view N --repo culturagovbr/plugin-ConectaEnte --json title,body`. Leia também as issues de "Depende de" e as que ela bloqueia.
2. Antes de editar, liste as contradições entre a issue, o código atual e as issues vizinhas. Inclua o que a issue cita e não existe, e o que existe e a issue não considera. Se houver decisão de produto, pergunte. Não decida sozinho o que a issue deixa em aberto.
3. Proponha o plano com os arquivos que vão mudar, e espere o aval quando houver decisão pendente.
4. Implemente. Rode os testes e o lint (abaixo) antes de dar por pronto.
5. Não faça `commit`, `push`, PR, comentário ou edição de issue sem pedido explícito. Vale para cada etapa: aprovação de uma não vale para a próxima.
6. Ao relatar, diga o que foi verificado de fato e o que não foi (teste no navegador, homologação).

## Comandos

- Testes: `tests/run.sh` (PHPUnit 8 em `php:7.2-cli`, via Docker). Aceita argumentos do phpunit, como `--filter` ou um arquivo.
- Lint PHP 7.2 de um arquivo: `docker run --rm -v "$PWD":/p -w /p php:7.2-cli php -l <arquivo>`.
- Sintaxe de JS: `docker run --rm -v "<pasta>":/j node:20-alpine node --check /j/<arquivo>.js`. O host não tem `node` nem `php`.

## Convenções de código

- PHP 7.2: sem propriedades tipadas, sem `??=`, sem `match`, sem `mixed`.
- Identificadores em inglês. Comentários seguem o padrão atual do plugin, em PT-BR.
- Comentário só quando explica o porquê: uma decisão, um cuidado, uma restrição que o código não mostra. Não repita o que o código já diz.
- Textos para o usuário passam por `i::__()` (PT-BR). Mensagens de publicação não podem ter aspas duplas ASCII: o `editable.js` põe a mensagem num atributo `title` sem escapar. Use « ».
- Migrações vão no `db-updates.php` deste plugin, com a chave prefixada por `conectaente:`. O `db-updates.php` do core não é usado para o plugin.
- Nada de token, segredo ou cabeçalho de requisição em banco, log, resposta ou commit.

## Dados e ambiente

- Dados de teste no banco de dev: só com aprovação, e sempre limpar no fim.
- Homologação do CultBR: ente 3, oportunidade 8455. Use só com aprovação.
- A tela de edição roda em `http://localhost:8088`.

## Commits

Mensagem em português, começando com `#N`, por exemplo `#54 Registrando tentativas de envio`. Termine com:

```
Co-Authored-By: Claude Haiku 5.5 <noreply@anthropic.com>
```

## Contradições já conhecidas

- #54 manda as tabelas para o `db-updates.php` do core, mas o plugin tem o próprio, usado pela #39. Use o do plugin.
- #54 fala em "`data` do job". O job só tem `metadata` JSON.
- #53 citava AngularJS e endpoint `/par/{id}`. Foram implementados como AngularJS (`conecta-ente-par`) e `/parInformation/<id>`.
- Identificadores em inglês, comentários em PT-BR: o padrão real do código, mesmo que a memória antiga diga "código em inglês".
