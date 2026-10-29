# laravel-qualidade

Caixa de ferramentas em Docker para checar a qualidade de projetos Laravel antes de abrir um pull request:
**PHPStan + Larastan**, **Rector**, **cobertura de testes** e **Infection** (testes de mutação).

Tudo roda num container e olha só para o que a sua branch mudou. Nada é instalado no projeto: o `composer.json`
continua igual, e o time não precisa adotar as ferramentas para você usar.

## O que cada ferramenta responde

| Ferramenta | Pergunta | Exemplo do que encontra |
|---|---|---|
| PHPStan + Larastan | O código está correto em tipos? | método que não existe, valor que pode ser `null`, retorno errado |
| Rector | Dá para escrever de forma mais simples ou moderna? | código morto, condição redundante, sintaxe antiga |
| Cobertura (PCOV) | Os testes passam por cada linha? | linhas que nenhum teste executa |
| Infection | Se houvesse um bug nesta linha, algum teste perceberia? | testes que passam mesmo com o código errado |

## Requisitos

- Docker (Docker Desktop no macOS)
- git
- Os serviços que os testes do projeto usam (banco, Redis) rodando, em container ou na máquina

## Instalação

```bash
git clone https://github.com/nexus-4/laravel-qualidade.git ~/ferramentas/laravel-qualidade
cd ~/ferramentas/laravel-qualidade
docker build -t laravel-qualidade:php8.4 .

mkdir -p ~/.local/bin
ln -sf ~/ferramentas/laravel-qualidade/qualidade ~/.local/bin/qualidade
```

Se o `~/.local/bin` ainda não estiver no PATH, acrescente ao `~/.zshrc`:

```bash
export PATH="$HOME/.local/bin:$PATH"
```

Confira com `qualidade ajuda`.

## Uso

Sempre de dentro do projeto, com a branch da feature aberta:

```bash
qualidade phpstan       # PHPStan + Larastan nos arquivos de app/ que a branch mudou
qualidade rector        # Rector em simulação (não altera nada) nos mesmos arquivos
qualidade cobertura     # testes com PCOV, relatório HTML e dados para o Infection
qualidade infection     # cobertura e depois Infection só nas linhas que a branch mudou
qualidade tudo          # phpstan, rector e infection, com um resumo no final
qualidade ajuda         # lista os comandos e as opções
```

### Opções

| Opção | Padrão | Para que serve |
|---|---|---|
| `--base=<ref>` | `origin/homologation` | branch ou commit de comparação. Ex.: `--base=origin/master`, `--base=HEAD~1` |
| `--nivel=<0-10>` | `6` | nível do PHPStan. O 8 procura `null` sem tratamento |
| `--testes=<path>` | todos | roda só esses testes na cobertura e no Infection. Bem mais rápido |
| `--threads=<n>` | `1` | threads do Infection. Só aumente se cada thread tiver o próprio banco de testes |

### Exemplos

```bash
qualidade tudo
qualidade tudo --testes=tests/Feature/EducationalText
qualidade phpstan --nivel=8
qualidade infection --base=HEAD~1 --testes=tests/Feature/EducationalText/Generation
```

Saída do `qualidade tudo`:

```
================ Resumo (banco-de-questoes, base HEAD~1) ================
PHPStan      No errors
Rector       Rector is done!
Cobertura    Lines:    1.68% (376/22344)
Infection    Mutation Score Indicator (MSI): 96%
Relatórios em ~/ferramentas/laravel-qualidade/relatorios/banco-de-questoes
```

A linha "Cobertura" é do projeto inteiro e fica baixa quando você usa `--testes`. O número que importa para a
branch é o do Infection, que só olha as linhas alteradas.

### Relatórios

Ficam em `relatorios/<nome-do-projeto>/`, fora do projeto:

| Arquivo | Conteúdo |
|---|---|
| `cobertura-html/index.html` | cobertura linha a linha (abra no navegador) |
| `infection.html` | cada mutante, morto ou que escapou, com o diff (abra no navegador) |
| `phpstan.txt`, `rector.txt`, `cobertura.txt`, `infection.txt` | a saída de cada ferramenta |

### Como ler o Infection

- **Morto**: algum teste falhou com o bug, ou seja, o código está protegido.
- **Escapou**: nenhum teste percebeu. Pergunte se aquele bug causaria problema para o usuário. Se sim, escreva o
  teste. Se não (texto de mensagem, `public` virando `protected` em controller, cast que o PHP faz sozinho),
  ignore: são mutações sem efeito real.
- Não persiga 100%. Acima de 80% nas linhas alteradas é bom; acima de 90%, muito bom.

## Como funciona

- **Só o que mudou.** O script compara a branch com a base (`git merge-base`) e passa para o PHPStan e o Rector
  os arquivos de `app/` alterados, inclusive os ainda não commitados. O Infection usa `--git-diff-lines` e muta
  só as linhas alteradas.
- **Larastan na versão certa.** O Larastan precisa da mesma versão do Laravel do projeto. O script lê
  `laravel/framework` e `nesbot/carbon` do `composer.lock` e instala o Larastan correspondente em
  `cache/larastan/laravel-<versão>`. A primeira vez em cada versão leva cerca de um minuto; depois é instantâneo.
- **O projeto não é copiado.** A pasta é montada em `/app` e o container roda com o seu usuário, então os
  relatórios não ficam com dono `root`.
- **Banco e Redis.** Os testes acessam os serviços da sua máquina por `host.docker.internal`
  (`DB_HOST` e `REDIS_HOST` são sobrescritos). O banco de testes é o do `phpunit.xml` de cada projeto.
- **Infection sem rodar a suíte duas vezes.** A cobertura gera o `coverage-xml` e o `junit.xml`, e o Infection
  roda com `--skip-initial-tests`, executando para cada mutante só os testes que passam por aquela linha.

## Problemas já resolvidos no script

| Problema | Solução |
|---|---|
| O Larastan ignora os casts declarados no método `casts()` e gera dezenas de falsos positivos (`status` vira `string`) | `parseModelCastsMethod: true` em `modelos/phpstan.neon` |
| O Rector 2.8.1 quebra ao ler docblocks com um PHPStan mais novo | PHPStan fixado em 2.3.3 na imagem |
| Um `phpunit.xml` no formato antigo não define o filtro, e a cobertura não é medida | `--coverage-filter app` na linha de comando |
| Testes em paralelo disputam o mesmo banco de testes e geram timeouts falsos no Infection | `--threads=1` por padrão |
| No Mac, `--add-host=host.docker.internal:host-gateway` quebra o acesso aos serviços | o `--add-host` só é usado no Linux |
| O git recusa o repositório montado ("dubious ownership") | `safe.directory '*'` na imagem |
| Regras do Rector que contrariam o padrão dos projetos (`strict_types`, `dispatch()`, `resolve()`, `instanceof` no lugar de `=== null`, `compact()`) | puladas em `modelos/rector.php` |

## Problemas comuns

| Sintoma | Causa e correção |
|---|---|
| `Network is unreachable` ou `Connection refused` nos testes | o banco não está rodando. Ex.: `docker start maria-banco` |
| `Não achei a base 'origin/homologation'` | rode `git fetch` ou passe `--base=<branch>` |
| `Could not find any "index.xml"` no Infection | a etapa de cobertura falhou antes. Veja `relatorios/<projeto>/cobertura.txt` |
| Avisos do PHPStan em accessors de models antigos (`$user->algumAccessor`) | o accessor não declara o tipo genérico de `Attribute`. É falso positivo, não bug |
| `$request->user()` pode ser `null` no nível 8 | as rotas autenticadas garantem o usuário; o PHPStan não lê middleware |

## Personalização

- **Outra versão do PHP:** `docker build --build-arg PHP_VERSION=8.3 -t laravel-qualidade:php8.3 .` e rode com
  `QUALIDADE_IMAGEM=laravel-qualidade:php8.3 qualidade tudo`.
- **Regras do Rector ou do PHPStan:** edite os arquivos em `modelos/`. Eles valem para todos os projetos.
- **Atualizar as ferramentas:** mude as versões no `Dockerfile`, reconstrua a imagem e teste com `qualidade tudo`
  num projeto conhecido antes de commitar.

## Fluxo de desenvolvimento sugerido

1. Entender o problema e alinhar o desenho com quem vai revisar.
2. TDD: teste vermelho, verde, refatoração.
3. Pint (estilo do projeto).
4. `qualidade tudo --testes=<pasta dos testes da feature>`.
5. Corrigir o que for erro real e escrever os testes que o Infection mostrou faltando.
6. Rodar a suíte inteira, testar no navegador, revisar o próprio diff e escrever a descrição do PR.
