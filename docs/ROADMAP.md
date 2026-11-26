# Roadmap: correções, melhorias e testes do envchecker

> Documento de execução. Primeira versão em **2026-11-23**; revisado em **2026-11-25** para posicionar a ferramenta **para produção primeiro**.
> Cada tarefa tem: problema, local, como reproduzir, correção sugerida (com código), critério de aceite e testes.
> Marque os checkboxes conforme for concluindo.

## Sumário

- [Visão do produto](#visão-do-produto)
- [Como usar este documento](#como-usar-este-documento)
- [Decisões de design](#decisões-de-design)
- [Comportamento final esperado](#comportamento-final-esperado)
- [Visão geral das fases](#visão-geral-das-fases)
- [Fase 0: Higiene e testabilidade](#fase-0-higiene-e-testabilidade)
- [Fase 1: Segurança e bugs críticos](#fase-1-segurança-e-bugs-críticos)
- [Fase 2: Núcleo de produção](#fase-2-núcleo-de-produção)
- [Fase 3: Robustez da CLI](#fase-3-robustez-da-cli)
- [Fase 4: Qualidade de código](#fase-4-qualidade-de-código)
- [Fase 5: Flags da CLI](#fase-5-flags-da-cli)
- [Fase 6: Infraestrutura e documentação](#fase-6-infraestrutura-e-documentação)
- [Suíte de testes completa (código final)](#suíte-de-testes-completa-código-final)
- [Matriz de rastreabilidade](#matriz-de-rastreabilidade)
- [Checklist final de release](#checklist-final-de-release)

---

## Visão do produto

**O envchecker valida o ambiente de produção antes que a aplicação suba.** Ele compara o que a aplicação espera (o `.env.example`, versionado) com o que o ambiente realmente tem (um arquivo `.env` no servidor **ou** as variáveis do processo em containers e PaaS) e faz o deploy falhar quando falta algo.

O problema que ele resolve: alguém adiciona uma chave no `.env.example`, o código passa a depender dela, e ninguém a configura em produção. A aplicação sobe e quebra (ou, pior, funciona errado) só quando aquele trecho de código roda.

Cenários de uso, em ordem de importância:

| Cenário | Comando típico | Onde roda |
|---|---|---|
| Servidor com arquivo `.env` | `vendor/bin/envchecker` | script de deploy, antes de trocar a versão |
| Container / Kubernetes / PaaS | `vendor/bin/envchecker --base=@env` | entrypoint do container, antes do `exec` da aplicação |
| CI: manter o `.env.example` em dia | `vendor/bin/envchecker --base=.env.testing --strict` | pipeline de testes |

Consequências para o projeto:

- **Instalação padrão sem `--dev`** (`composer require fabriciope/envchecker`), porque deploys rodam `composer install --no-dev`. O pacote não tem dependências em produção, então o custo é nulo.
- **O comportamento padrão é o de produção:** chave faltando, vazia ou duplicada falha; chave sobrando é só aviso.
- **Segurança é requisito central:** a ferramenta lê segredos reais e a saída cai em logs de deploy. Ela **nunca** imprime valores.
- Roda **uma vez por deploy ou por início de container**, nunca a cada requisição.

---

## Como usar este documento

1. **Execute as fases em ordem.** As dependências aparecem em cada tarefa (`Depende de:`).
2. **TDD:** em cada tarefa, escreva primeiro os testes listados (eles devem falhar), depois implemente até passarem.
3. **Uma tarefa = um commit** (ou um PR pequeno). Mensagem sugerida: `tipo(escopo): descrição [Tx.y]`.
4. **Ao fim de cada tarefa:** `composer test`, `vendor/bin/php-cs-fixer fix`, e atualize o `CHANGELOG.md` (seção `[Unreleased]`) e o `README.md` quando a tarefa disser.
5. A seção [Suíte de testes completa](#suíte-de-testes-completa-código-final) mostra **o estado final** de cada arquivo de teste. Cada teste está marcado com a tarefa que o faz passar (`// [T2.3]`); testes sem marcação já passam hoje. As tarefas mostram só os trechos relevantes.

| Símbolo | Significado |
|---|---|
| 🔴 | Segurança ou bug que gera resultado errado |
| 🟠 | Bug de comportamento ou robustez |
| 🟡 | Qualidade de código, sem efeito para o usuário |
| 🟢 | Funcionalidade nova |
| 🔵 | Infraestrutura ou documentação |

---

## Decisões de design

Estas decisões valem para o documento inteiro. Se mudar alguma, revise as tarefas e os testes que a citam.

| # | Decisão | Motivo |
|---|---|---|
| D1 | **Produção primeiro.** O comportamento padrão é o adequado para validar um ambiente de produção. Instalação recomendada sem `--dev`. | É onde a ferramenta evita incidentes. Ainda não houve release, então mudar o padrão não quebra ninguém. |
| D2 | **Erros (exit `1`):** chave obrigatória **faltando**, chave obrigatória **vazia** e chave **duplicada** em qualquer um dos arquivos. **Avisos (exit `0`):** chave **sobrando** e **permissão** insegura do arquivo. | Em produção, vazia quase sempre é configuração esquecida, e duplicada deixa o valor efetivo ambíguo. Uma chave sobrando não quebra a aplicação. |
| D3 | **`--strict` transforma chaves sobrando em erro.** O aviso de permissão **nunca** muda o exit code, nem com `--strict`. | `--strict` serve ao CI, para manter o `.env.example` em dia. Checkouts de CI criam arquivos com `0644`; se a permissão falhasse, o `--strict` ficaria inutilizável no CI. |
| D4 | **Chaves opcionais** são marcadas no `.env.example` com um comentário no fim da linha: `SENTRY_DSN= # @optional`. Uma chave opcional pode faltar ou estar vazia sem erro. A marcação só vale no arquivo de referência (`against`). Se a chave aparece mais de uma vez, vale a última ocorrência. | Sem isso, chaves legitimamente vazias forçariam as pessoas a desligar a verificação. A marcação fica versionada junto com a chave. |
| D5 | **`--base=@env`** compara o `.env.example` com as **variáveis do processo**. Nesse modo: chaves sobrando são ignoradas por completo (nem reportadas, nem com `--strict`), nomes de variáveis inválidos são ignorados em silêncio, valores são usados como estão (sem remover aspas ou comentários) e não há verificação de permissão. `--against=@env` é erro de uso (`64`). Para um arquivo chamado literalmente `@env`, use `./@env`. | Em containers e PaaS não existe arquivo `.env`. O ambiente do processo sempre tem dezenas de variáveis alheias (`PATH`, `HOME`...), que só gerariam ruído. |
| D6 | **Chave com nome inválido é erro** de dado: exit **`65`** (`EX_DATAERR`). Padrão aceito: `/^[A-Za-z_][A-Za-z0-9_.]*\z/`. A mensagem lista arquivo, linha e nome, nunca o valor. | Bloqueia a injeção de sequências de terminal na origem, resolve as chaves numéricas e pega erros de digitação. |
| D7 | **Mensagens dinâmicas são sanitizadas** na saída: caracteres de controle viram `\xNN` visível. | Defesa em profundidade: caminhos e mensagens de exceção também chegam ao terminal e aos logs. |
| D8 | **Nunca imprimir valores** de variáveis, em nenhum formato, modo ou mensagem de erro. Isso tem testes dedicados (`SecurityTest`). | A saída vai para logs de deploy, que são guardados e às vezes compartilhados. |
| D9 | **Duplicadas:** reportadas separadamente para `base` e `against`. Para o valor, a última ocorrência vence. | Duplicada no `.env.example` também é erro de manutenção. "Última vence" é o comportamento da maioria dos parsers. |
| D10 | **JSON:** `has_differences` segue **sempre** o exit code. Campos novos: `duplicate_keys` (`{base, against}`) e `warnings` (lista de strings). | O README já promete "o mesmo critério do exit code `1`". |
| D11 | **Mensagem de sucesso** `All required keys are set` só aparece quando o exit code é `0`. Avisos aparecem antes dela, com o prefixo `warning: `. | Nunca exibir "sucesso" junto com uma falha. |
| D12 | **Valores:** aspas só são removidas quando formam um par. Sem aspas, `#` no início do valor ou depois de um espaço inicia um comentário. Aspas não fechadas mantêm o valor literal. Valor só com espaços conta como vazio. | Corrige a detecção de vazias com comentário no fim da linha e é o que permite a marcação `# @optional`. |
| D13 | **Limite de 1 MiB** por arquivo; acima disso, exit `65`. | Nenhum `.env` real chega perto; protege contra apontar para um arquivo errado. |
| D14 | **Linhas sem `=` continuam ignoradas**, e comentários de linha inteira também (inclusive `# @optional` sozinho numa linha). | Compatibilidade e simplicidade. |
| D15 | **API pública:** `Env\` (`Parser`, `ParsedEnv`, `Comparator`, `InvalidLine`) é contrato estável a partir da 1.0. `Console\` continua `@internal`. | Quem usa em produção pode chamar a biblioteca no próprio código. |
| D16 | **Flags booleanas** `--help`, `--version` e `--strict` não aceitam valor. **Precedência:** validação dos argumentos (`64`) → `--help` → `--version` → verificação. `--help` e `--version` não leem arquivos. | Comportamento previsível, igual ao de outras CLIs. |
| D17 | **Dica quando o `.env` não existe:** além do erro `66`, a ferramenta sugere `--base=@env`. | É o erro mais provável de quem roda pela primeira vez num container. |
| D18 | **Raiz do projeto:** a raiz do Composer só é usada se o diretório atual estiver dentro dela; senão, usa o diretório atual. | Resolve a instalação global (`composer global require`). |
| D19 | **Saída em inglês**, documentação em português. | Mantém o padrão atual do código. |

---

## Comportamento final esperado

Resumo do produto depois de todas as tarefas, para servir de referência.

### O que é verificado

| Situação | Padrão | `--strict` | `--base=@env` |
|---|---|---|---|
| Chave obrigatória faltando | **erro** | **erro** | **erro** |
| Chave obrigatória vazia | **erro** | **erro** | **erro** |
| Chave opcional (`# @optional`) faltando ou vazia | ignorada | ignorada | ignorada |
| Chave duplicada (em qualquer arquivo) | **erro** | **erro** | **erro** (só no `.env.example`) |
| Chave sobrando | aviso | **erro** | ignorada |
| Arquivo `base` acessível por outros usuários | aviso | aviso | não se aplica |
| Nome de chave inválido | exit `65` | exit `65` | ignorado no ambiente; `65` no `.env.example` |

### Exit codes

| Código | Significado |
|---|---|
| `0` | Nenhum erro. Avisos podem ter sido reportados. |
| `1` | Há erros (veja a tabela acima). |
| `64` | Uso incorreto (argumento inválido, flag desconhecida ou repetida, valor ausente, `--format` desconhecido, `--against=@env`). |
| `65` | Arquivo inválido (nome de chave inválido, arquivo maior que 1 MiB). |
| `66` | Arquivo não existe ou não pode ser lido. |
| `70` | Erro interno inesperado. |

### Saída em texto

```text
2 missing keys: A, F
1 empty keys: B
1 duplicate keys in base: D
1 duplicate keys in against: F
1 extra keys: E
warning: /srv/app/.env is accessible by other users (mode 0644); consider chmod 600
```

Quando o exit code é `0`, a última linha é `All required keys are set`.

### Saída em JSON

```json
{"has_differences":true,"missing_keys":["A","F"],"empty_keys":["B"],"extra_keys":["E"],"duplicate_keys":{"base":["D"],"against":["F"]},"warnings":[]}
```

---

## Visão geral das fases

| Fase | Tarefas | Foco |
|---|---|---|
| 0 | T0.1 – T0.7 | Limpeza e testabilidade, sem mudança de comportamento |
| 1 | T1.1 – T1.6 | Segurança e bugs que geram resultado errado |
| 2 | T2.1 – T2.7 | **Núcleo de produção:** critérios de erro, `@optional`, `@env`, permissões |
| 3 | T3.1 – T3.3 | Robustez da CLI |
| 4 | T4.1 – T4.2 | Qualidade de código |
| 5 | T5.1 – T5.4 | `--help`, `--version`, `--strict` |
| 6 | T6.1 – T6.6 | CI, PHPStan, cobertura, posicionamento e documentação |

> As Fases 0 a 2 entregam a ferramenta pronta para produção. Se precisar lançar cedo, uma versão `0.1.0` depois da Fase 2 (com T6.5 adiantada) já é útil.

---

## Fase 0: Higiene e testabilidade

### [ ] T0.1 🟡 Remover anotações pessoais do executável

- **Local:** `bin/envchecker:11-12`
- **Problema:** dois comentários de anotação pessoal depois do `exit`. O arquivo vai no pacote distribuído.
- **Correção:** apagar as duas linhas. O arquivo deve terminar em `exit(...);` + quebra de linha.
- **Aceite:** `tail -n 2 bin/envchecker` mostra só o `exit` e a linha vazia.

### [ ] T0.2 🟡 Excluir `docs/` do pacote distribuído

- **Local:** `.gitattributes`
- **Correção:** adicionar `/docs                        export-ignore`.
- **Aceite:** `git archive HEAD | tar -t | grep docs` não retorna nada.

### [ ] T0.3 🟡 Limpar a base de testes

- `tests/Pest.php`: remover o código de exemplo do scaffold (`toBeOne`, `something()`) e centralizar os helpers `fixture()`, `tempEnv()`, `runEnvChecker()` e `checkContents()` (código final em [tests/Pest.php](#testspestphp)).
- `tests/Feature/KernelTest.php`: remover a função local `runEnvChekcer` (typo) e as variáveis `$sdterr`; usar o helper central.
- `tests/Unit/EnvParserTest.php:10-11`: a mesma asserção sobre `NAME` aparece duas vezes. Verificar o array completo.
- `tests/Unit/InputParserTest.php:61-68`: o teste *"should throw … when retrieving a flag that has been defined but has no value"* passa **pelo motivo errado**: quem lança a exceção é o `parse()`, não o `getLongOption()`. Transformar em um caso do dataset de argumentos inválidos.
- A fixture `tests/Fixtures/parser/.env_with_null_value` não é usada. Usar no teste "o literal `null` é um valor não vazio".
- **Aceite:** `composer test` verde, sem helpers duplicados e sem código morto em `tests/`.

### [ ] T0.4 🟡 PHPUnit mais rigoroso

- **Local:** `phpunit.xml`

```xml
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache"
         failOnWarning="true"
         failOnRisky="true"
         failOnNotice="true"
         failOnDeprecation="true"
         beStrictAboutOutputDuringTests="true"
>
```

- Adicionar `/.phpunit.cache` ao `.gitignore`. Não precisa de `export-ignore`, porque arquivos ignorados pelo git não entram no pacote.
- **Aceite:** um `trigger_error('x', E_USER_WARNING)` temporário em um teste faz a suíte falhar.

### [ ] T0.5 🟡 Comparação frouxa e tipos largos em `Input`

- **Local:** `src/Console/Input.php:41, 90, 110`
- **Problema:** `'' != $value` só funciona porque `null == ''` em comparação frouxa. `getLongOption()` declara `bool|int|string`, mas os valores são sempre `string`. O docblock em `Input.php:41` usa o namespace errado (`Envchecker`).
- **Correção:**

```php
/** @var array<string, null|string> */
private array $longOptions = [];

public function getLongOption(string $longOptionName, string $default = ''): string
{
    // ...igual
}

private function validateArgument(string $argumentName, ?string $value): bool
{
    return null !== $value
        && '' !== $value
        && in_array($argumentName, self::ACCEPTED_OPTIONS, true)
        && ('format' !== $argumentName || in_array($value, Kernel::ACCEPTED_FORMATS, true));
}
```

- **Aceite:** `--base=0` continua sendo aceito, com valor `'0'`.

### [ ] T0.6 🟡 Checagem redundante no `Parser`

- **Local:** `src/Env/Parser.php:43`
- **Problema:** `is_file()` já implica `file_exists()`.
- **Correção:** `if (!is_file($path) || !is_readable($path))`. (Esse trecho é reescrito em T1.6; se for fazer as duas em sequência, pode pular esta.)

### [ ] T0.7 🟡 `Kernel` com dependências injetáveis

- **Bloqueia:** T2.5, T3.3 e vários testes do `KernelTest`.
- **Local:** `src/Console/Kernel.php:28-38`
- **Problema:** o `Kernel` cria o `PathResolver` internamente e, depois de T2.5, vai ler `getenv()`. Sem injeção, os testes não conseguem cobrir os arquivos padrão, o modo `@env` com variáveis controladas nem o exit `70`.
- **Correção:**

```php
/**
 * @param null|array<string, string> $environment variáveis do processo; null = getenv()
 */
public function __construct(
    private readonly Input $input,
    private readonly Output $output,
    private readonly ?PathResolver $paths = null,
    private readonly ?array $environment = null,
) {}

// em run():
$pathResolver = $this->paths ?? PathResolver::fromEnvironment();
```

- **Testes:** exit `70` via um `PathResolver` que lança exceção; arquivos padrão resolvidos pela raiz injetada.
- **Aceite:** nenhuma mudança de comportamento em `bin/envchecker`; os novos testes passam.

---

## Fase 1: Segurança e bugs críticos

### [ ] T1.1 🟡 Base: DTO `ParsedEnv`

- **Bloqueia:** T1.3, T2.3, T2.4, T2.5.
- **Problema:** `Parser` devolve um array sem estrutura (TODO em `Comparator.php:7-8`). As tarefas seguintes precisam devolver também chaves duplicadas, linhas inválidas e chaves opcionais.
- **Correção:** criar `src/Env/ParsedEnv.php` e `src/Env/InvalidLine.php`.

```php
<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Env;

final readonly class ParsedEnv
{
    /** Valor de --base que indica as variáveis do processo (T2.5). */
    public const string FROM_ENVIRONMENT = '@env';

    /**
     * @param array<string, string> $values        chave => valor (última ocorrência vence)
     * @param list<string>          $duplicateKeys chaves que apareceram mais de uma vez
     * @param list<InvalidLine>     $invalidLines  linhas com nome de chave inválido
     * @param list<string>          $optionalKeys  chaves marcadas com "# @optional"
     * @param string                $source        caminho do arquivo, '@env' ou ''
     */
    public function __construct(
        public array $values = [],
        public array $duplicateKeys = [],
        public array $invalidLines = [],
        public array $optionalKeys = [],
        public string $source = '',
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->values));
    }

    public function isValid(): bool
    {
        return [] === $this->invalidLines;
    }

    public function isOptional(string $key): bool
    {
        return in_array($key, $this->optionalKeys, true);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Env;

final readonly class InvalidLine
{
    public function __construct(
        public int $number,
        public string $key,
    ) {}
}
```

- `Parser::parse(string $content, string $source = ''): ParsedEnv` e `Parser::parseFile(string $path): ParsedEnv`.
- `Comparator` passa a receber `ParsedEnv` e ganha o construtor auxiliar `fromArrays()` (código final em T2.3).
- Atualizar os testes existentes: `$envParsed['NAME']` → `$envParsed->values['NAME']`; `new Comparator(base: [...])` → `Comparator::fromArrays(base: [...])`.
- **Aceite:** suíte verde com a nova API; TODO de `Comparator.php` removido.

### [ ] T1.2 🔴 Quebra de linha errada em valores UTF-8

- **Local:** `src/Env/Parser.php:19`
- **Problema:** `preg_split('/\R/', …)` **sem** o modificador `u` considera o byte `0x85` (NEL) uma quebra de linha. Esse byte aparece **dentro** de caracteres UTF-8 comuns (`Å` = `C3 85`). A linha é cortada no meio, e o pedaço que sobra pode virar uma chave falsa.
- **Reproduzir:**

```bash
php -r 'require "vendor/autoload.php"; var_dump(Fabriciope\EnvChecker\Env\Parser::parse("URL=https://x?q=\u{C5}&b=1"));'
# ["URL"]=> "https://x?q=\xC3"   ["&b"]=> "1"    ← chave "&b" inventada
```

  Depois de T1.3, essa chave falsa vira **exit 65** num `.env` válido, ou seja, um deploy bloqueado sem motivo. Por isso esta tarefa vem antes.
- **Correção:**

```php
foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $line) {
```

- **Aceite:** `URL=https://x?q=Å&b=1` resulta numa única chave `URL` com o valor completo.

### [ ] T1.3 🔴 Validar nomes de chave (injeção no terminal e chaves numéricas)

- **Depende de:** T1.1, T1.2. **Decisão:** D6.
- **Locais:** `src/Env/Parser.php`, `src/Console/Kernel.php`, novo `src/Exceptions/InvalidEnvFileException.php`.
- **Problemas resolvidos:**
  1. **Injeção de sequências de terminal:** o modo texto imprime as chaves cruas. Um `.env.example` malicioso com `X\033[2J\033]0;pwned\007=1` limpa a tela e muda o título do terminal. Em produção, a mesma sequência vai parar no log de deploy.
  2. **Chaves numéricas:** `123=x` vira chave `int` no PHP, e o JSON sai `"extra_keys":[123]`.
  3. Erros de digitação como `MY KEY=1`, `=valor` e `KEY-NAME=1` passavam sem aviso.
- **Reproduzir:**

```bash
printf 'X\033[2J\033]0;pwned\007=1\n' > /tmp/evil.env
printf 'A=1\n' > /tmp/a.env
vendor/bin/envchecker --base=/tmp/a.env --against=/tmp/evil.env   # a tela é limpa
```

- **Correção no `Parser`** (versão final, já incluindo T2.1, T2.2 e T2.4):

```php
public const string KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_.]*\z/';

public static function parse(string $content, string $source = ''): ParsedEnv
{
    if (str_starts_with($content, "\xEF\xBB\xBF")) {                     // T1.5
        $content = substr($content, 3);
    }

    $values = [];
    $duplicates = [];
    $invalid = [];
    $optional = [];

    foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $line) {   // T1.2
        $line = trim($line);
        if ('' === $line || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        $line = preg_replace('/^export\s+/', '', $line) ?? $line;        // T2.2
        [$key, $rawValue] = explode('=', $line, 2);
        $key = trim($key);

        if (1 !== preg_match(self::KEY_PATTERN, $key)) {
            $invalid[] = new InvalidLine($index + 1, $key);

            continue;
        }

        if (array_key_exists($key, $values)) {
            $duplicates[$key] = $key;
        }

        [$value, $comment] = self::parseValue($rawValue);                 // T2.1
        $values[$key] = $value;
        $optional[$key] = self::isOptionalComment($comment);              // T2.4
    }

    return new ParsedEnv(
        values: $values,
        duplicateKeys: array_values($duplicates),
        invalidLines: $invalid,
        optionalKeys: array_keys(array_filter($optional)),
        source: $source,
    );
}
```

  Como uma chave válida sempre começa com letra ou `_`, o PHP nunca a converte para `int`, o que resolve o problema 2.

- **`parseFile()` lança a exceção** (o `parse()` continua tolerante, para ser testável):

```php
$parsed = self::parse($content, $path);
if (!$parsed->isValid()) {
    throw InvalidEnvFileException::invalidKeys($path, $parsed->invalidLines);
}

return $parsed;
```

- **Nova exceção:**

```php
<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Exceptions;

use Fabriciope\EnvChecker\Env\InvalidLine;

final class InvalidEnvFileException extends \Exception implements EnvCheckerException
{
    /** @param list<InvalidLine> $lines */
    public static function invalidKeys(string $path, array $lines): self
    {
        $details = implode(', ', array_map(
            static fn (InvalidLine $l): string => sprintf('line %d (%s)', $l->number, $l->key),
            $lines,
        ));

        return new self("Invalid variable names in {$path}: {$details}");
    }

    public static function tooLarge(string $path, int $maxBytes): self          // T1.6
    {
        return new self("Env file is larger than {$maxBytes} bytes: {$path}");
    }
}
```

  > A interface `EnvCheckerException` é criada em T4.1. Se fizer T1.3 antes, crie a interface já aqui (é uma linha).

- **No `Kernel`:**

```php
public const int DATA_ERROR = 65;
// ...
} catch (InvalidEnvFileException $e) {
    $this->output->error($e->getMessage());

    return self::DATA_ERROR;
}
```

- **Aceite:** a reprodução acima resulta em exit `65`, e o `stderr` não tem bytes de controle crus.

### [ ] T1.4 🔴 Sanitizar a saída (defesa em profundidade)

- **Decisão:** D7. **Local:** `src/Console/Output.php`, `src/Console/Kernel.php`
- **Problema:** mesmo com T1.3, chegam ao terminal e aos logs **caminhos de arquivo** (`--base=$'\e[2J'`), **nomes de chave inválidos** dentro da mensagem de T1.3 e **mensagens de exceções inesperadas** (`Unexpected internal error: …`).
- **Correção:**

```php
/**
 * Troca caracteres de controle (C0, DEL e C1 em UTF-8) por \xNN visível.
 */
public static function sanitize(string $text): string
{
    return (string) preg_replace_callback(
        '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/',
        static fn (array $m): string => implode('', array_map(
            static fn (string $byte): string => sprintf('\x%02X', ord($byte)),
            str_split($m[0]),
        )),
        $text,
    );
}

public function error(string $message): void
{
    fwrite($this->err, $this->style('envchecker: ', 31, $this->errorColors).self::sanitize($message).PHP_EOL);
}
```

  No relatório de texto do `Kernel`, aplique `Output::sanitize()` às listas de chaves e aos avisos **antes** de `style()`.
- **Aceite:** nenhum byte `\x1B` vindo de dados do usuário aparece em `stdout`/`stderr`. O único `ESC` permitido é o das cores geradas pela própria ferramenta.

### [ ] T1.5 🔴 BOM UTF-8 gera falsas diferenças

- **Local:** `src/Env/Parser.php` (início de `parse()`, já incluído no código de T1.3)
- **Problema:** um arquivo salvo com BOM faz a primeira chave virar `\uFEFFNAME`. O resultado é um falso "missing" + "extra" e um **deploy bloqueado**. Depois de T1.3, seria exit `65`.
- **Reproduzir:**

```bash
printf '\xEF\xBB\xBFNAME=a\n' > /tmp/b.env; printf 'NAME=\n' > /tmp/a.env
vendor/bin/envchecker --base=/tmp/b.env --against=/tmp/a.env --format=json
# {"has_differences":true,"missing_keys":["NAME"],...,"extra_keys":["\ufeffNAME"]}
```

- **Aceite:** o comando acima retorna `has_differences: false`.

### [ ] T1.6 🟠 Limite de tamanho de arquivo

- **Depende de:** T1.3. **Decisão:** D13.
- **Local:** `src/Env/Parser.php::parseFile`

```php
public const int MAX_FILE_SIZE = 1_048_576; // 1 MiB

public static function parseFile(string $path): ParsedEnv
{
    if (!is_file($path) || !is_readable($path)) {
        throw new EnvFileNotFoundException($path);
    }

    $size = filesize($path);
    if (false !== $size && $size > self::MAX_FILE_SIZE) {
        throw InvalidEnvFileException::tooLarge($path, self::MAX_FILE_SIZE);
    }

    $content = file_get_contents($path, length: self::MAX_FILE_SIZE + 1);
    if (false === $content) {
        throw new EnvFileNotFoundException($path);
    }

    if (strlen($content) > self::MAX_FILE_SIZE) {      // arquivo cresceu entre filesize() e a leitura
        throw InvalidEnvFileException::tooLarge($path, self::MAX_FILE_SIZE);
    }

    $parsed = self::parse($content, $path);
    if (!$parsed->isValid()) {
        throw InvalidEnvFileException::invalidKeys($path, $parsed->invalidLines);
    }

    return $parsed;
}
```

- **Aceite:** um arquivo de `MAX_FILE_SIZE + 1` bytes resulta em exit `65`; um de exatamente `MAX_FILE_SIZE` bytes é aceito.

---

## Fase 2: Núcleo de produção

### [ ] T2.1 🟠 Aspas e comentários no fim da linha

- **Decisão:** D12. **Bloqueia:** T2.4. **Local:** `src/Env/Parser.php:30`
- **Problemas:**
  1. `DB_HOST= # preencher` **não** é considerado vazio (o valor vira `# preencher`). Com vazia valendo como erro (T2.3), uma chave sem valor em produção passaria sem ser detectada. O README (`README.md:125`) afirma que isso "não afeta a verificação", o que está errado.
  2. `trim($value, '"\'')` remove aspas desbalanceadas: `A="foo'` vira `foo`, e `A='"'` vira vazio.
- **Correção:** `parseValue()` devolve o valor **e o comentário** (o comentário é usado em T2.4):

```php
/** @return array{0: string, 1: string} [valor, comentário sem o '#'] */
private static function parseValue(string $raw): array
{
    $raw = trim($raw);
    if ('' === $raw) {
        return ['', ''];
    }

    $quote = $raw[0];
    if ('"' === $quote || "'" === $quote) {
        $pattern = '"' === $quote
            ? '/^"((?:[^"\\\\]|\\\\.)*)"(.*)$/s'   // aceita \" dentro de aspas duplas
            : "/^'([^']*)'(.*)$/s";

        if (1 !== preg_match($pattern, $raw, $m)) {
            return [$raw, ''];                       // aspas não fechadas: valor literal
        }

        $rest = trim($m[2]);

        return [trim($m[1]), str_starts_with($rest, '#') ? trim(substr($rest, 1)) : ''];
    }

    // Sem aspas: '#' no início ou precedido de espaço inicia um comentário.
    if (1 === preg_match('/(?:^|\s)#/', $raw, $m, PREG_OFFSET_CAPTURE)) {
        $hash = $m[0][1] + strlen($m[0][0]) - 1;

        return [trim(substr($raw, 0, $hash)), trim(substr($raw, $hash + 1))];
    }

    return [$raw, ''];
}
```

  > Sequências de escape (`\"`) **não** são decodificadas. Não é necessário, porque a ferramenta só verifica se o valor está vazio.

- **README:** reescrever "Limitações conhecidas": comentários no fim da linha são suportados; `#` colado no valor (`abc#def`, URLs com `#fragmento`) faz parte do valor.
- **Aceite:** todos os casos do dataset `values` do [EnvParserTest](#testsunitenvparsertestphp) passam.

### [ ] T2.2 🟠 `export` seguido de tab ou de vários espaços

- **Local:** `src/Env/Parser.php:25-27`
- **Problema:** só `export ` (com um espaço) é reconhecido. `export\tKEY=1` vira a chave `export\tKEY`, que depois de T1.3 é inválida (exit `65`).
- **Correção:** `preg_replace('/^export\s+/', '', $line)` (já incluída no código de T1.3).
- **Aceite:** `export\tKEY` e `export   KEY` funcionam; `export=1` continua sendo a chave `export`.

### [ ] T2.3 🔴 Critérios de produção: vazias e duplicadas são erro, sobrando é aviso

- **Depende de:** T1.1, T1.3. **Decisões:** D2, D3, D9, D10, D11.
- **Locais:** `src/Env/Comparator.php`, `src/Console/Kernel.php`
- **Problema:** hoje, chave sobrando faz o comando falhar e chave vazia passa, o contrário do que importa em produção. Duplicadas não são detectadas. Além disso, o `Comparator` tem API assimétrica e lança `TypeError` com valores `null` (confirmado: `new Comparator(['A' => null], ['A' => 'x'])`).
- **`Comparator` final:**

```php
<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Env;

final readonly class Comparator
{
    /** @var list<string> */
    public array $missingKeys;

    /** @var list<string> */
    public array $emptyKeys;

    /** @var list<string> */
    public array $extraKeys;

    public function __construct(
        public ParsedEnv $base,
        public ParsedEnv $against,
        public bool $ignoreExtraKeys = false,                          // T2.5
    ) {
        $required = array_diff_key($against->values, array_flip($against->optionalKeys));   // T2.4

        $this->missingKeys = self::keys(array_diff_key($required, $base->values));
        $this->emptyKeys = self::keys(array_filter(
            array_intersect_key($base->values, $required),
            static fn (mixed $value): bool => '' === trim((string) $value),
        ));
        $this->extraKeys = $ignoreExtraKeys
            ? []
            : self::keys(array_diff_key($base->values, $against->values));
    }

    /**
     * @param array<array-key, mixed> $base
     * @param array<array-key, mixed> $against
     * @param list<string>            $optionalKeys
     */
    public static function fromArrays(array $base, array $against, array $optionalKeys = []): self
    {
        return new self(new ParsedEnv($base), new ParsedEnv($against, optionalKeys: $optionalKeys));
    }

    public function hasMissingKeys(): bool
    {
        return [] !== $this->missingKeys;
    }

    public function hasEmptyKeys(): bool
    {
        return [] !== $this->emptyKeys;
    }

    public function hasExtraKeys(): bool
    {
        return [] !== $this->extraKeys;
    }

    public function hasDuplicateKeys(): bool
    {
        return [] !== $this->base->duplicateKeys || [] !== $this->against->duplicateKeys;
    }

    /**
     * Erros: faltando, vazia e duplicada. Com $strict, chaves sobrando também (D2, D3).
     */
    public function hasErrors(bool $strict = false): bool
    {
        return $this->hasMissingKeys()
            || $this->hasEmptyKeys()
            || $this->hasDuplicateKeys()
            || ($strict && $this->hasExtraKeys());
    }

    /** @param array<array-key, mixed> $array @return list<string> */
    private static function keys(array $array): array
    {
        return array_map(strval(...), array_keys($array));
    }
}
```

  > `hasDifferences()` é **removido** e substituído por `hasErrors()`, porque o significado mudou. Como não houve release, isso não quebra ninguém.

- **`Kernel`:**

```php
$strict = $this->input->hasFlag('strict');                             // T5.1/T5.4 (até lá: false)
$comparator = new EnvComparator($base, $against, ignoreExtraKeys: $baseIsEnv);
$warnings = $this->warnings($base);                                    // T2.6 (até lá: [])
$failed = $comparator->hasErrors($strict);

$this->printReport($comparator, $warnings, $failed);

return $failed ? self::DIFFERENCES_ERROR : self::SUCCESS;
```

```php
/** @param list<string> $warnings */
private function printReportInText(EnvComparator $c, array $warnings, bool $failed): void
{
    $this->section('missing keys', $c->missingKeys, 31);
    $this->section('empty keys', $c->emptyKeys, 33);
    $this->section('duplicate keys in base', $c->base->duplicateKeys, 35);
    $this->section('duplicate keys in against', $c->against->duplicateKeys, 35);
    $this->section('extra keys', $c->extraKeys, 34);

    foreach ($warnings as $warning) {
        $this->output->line($this->output->style('warning: ', 33).Output::sanitize($warning));
    }

    if (!$failed) {
        $this->output->line($this->output->style('All required keys are set', 32));
    }
}

/** @param list<string> $warnings */
private function printReportInJson(EnvComparator $c, array $warnings, bool $failed): void
{
    $this->output->line(json_encode([
        'has_differences' => $failed,
        'missing_keys' => $c->missingKeys,
        'empty_keys' => $c->emptyKeys,
        'extra_keys' => $c->extraKeys,
        'duplicate_keys' => ['base' => $c->base->duplicateKeys, 'against' => $c->against->duplicateKeys],
        'warnings' => $warnings,
    ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
}
```

  A mensagem antiga `.env is in sync with the example file` é substituída por `All required keys are set`.
- **README:** reescrever "O que é verificado" e "Exit codes" de acordo com [Comportamento final esperado](#comportamento-final-esperado); adicionar `duplicate_keys` e `warnings` à tabela do JSON.
- **Aceite:** a fixture `with_differences` (com `AGE=` vazio) retorna exit `1`; base com só uma chave sobrando retorna `0` e imprime o aviso.

### [ ] T2.4 🟢 Chaves opcionais: `# @optional`

- **Depende de:** T2.1, T2.3. **Decisão:** D4.
- **Local:** `src/Env/Parser.php`
- **Problema:** com vazia valendo como erro, chaves legitimamente vazias (`SENTRY_DSN`, `MAIL_BCC`...) bloqueariam o deploy.
- **Uso:**

```dotenv
APP_KEY=
DB_PASSWORD=
SENTRY_DSN=          # @optional
MAIL_BCC=""          # @optional  cópia oculta, só em alguns clientes
```

- **Correção:**

```php
private static function isOptionalComment(string $comment): bool
{
    return 1 === preg_match('/(?:^|\s)@optional(?:\s|$)/', $comment);
}
```

  O `Comparator` (T2.3) já desconta as chaves opcionais do `against` ao calcular faltando e vazias. Uma chave opcional presente no `.env` **não** é "sobrando".
- **README:** nova seção "Chaves opcionais".
- **Aceite:** `SENTRY_DSN= # @optional` no exemplo e ausente no `.env` → exit `0`, sem menção à chave na saída.

### [ ] T2.5 🟢 Validar as variáveis do processo: `--base=@env`

- **Depende de:** T0.7, T2.3. **Decisão:** D5.
- **Locais:** `src/Env/Parser.php`, `src/Console/Input.php`, `src/Console/Kernel.php`
- **Problema:** em Docker, Kubernetes e PaaS, produção não tem arquivo `.env`. Sem este modo, a ferramenta não serve para boa parte dos ambientes de produção.
- **Correção no `Parser`** (o domínio recebe um array e não chama `getenv()`; o teste de arquitetura garante isso):

```php
/**
 * Monta um ParsedEnv a partir das variáveis do processo.
 * Nomes inválidos são ignorados em silêncio; valores são usados como estão.
 *
 * @param array<array-key, mixed> $variables
 */
public static function fromEnvironment(array $variables): ParsedEnv
{
    $values = [];
    foreach ($variables as $name => $value) {
        $name = (string) $name;
        if (is_string($value) && 1 === preg_match(self::KEY_PATTERN, $name)) {
            $values[$name] = $value;
        }
    }

    return new ParsedEnv($values, source: ParsedEnv::FROM_ENVIRONMENT);
}
```

- Adicionar a constante em `ParsedEnv`: `public const string FROM_ENVIRONMENT = '@env';`. O valor fica no domínio, e `Input` e `Kernel` usam a mesma constante. `Env\` não pode referenciar `Kernel`, porque o teste de arquitetura proíbe.

- **Correção no `Input`:** `--against=@env` é rejeitado (exit `64`):

```php
&& ('against' !== $argumentName || ParsedEnv::FROM_ENVIRONMENT !== $value)
```

- **Correção no `Kernel`:**

```php
$baseIsEnv = ParsedEnv::FROM_ENVIRONMENT === $this->input->getLongOption('base');
$base = $baseIsEnv
    ? EnvParser::fromEnvironment($this->environment ?? getenv())
    : EnvParser::parseFile($this->basePath = $this->resolvePath($pathResolver, 'base', '.env'));
$against = EnvParser::parseFile($this->resolvePath($pathResolver, 'against', '.env.example'));

$comparator = new EnvComparator($base, $against, ignoreExtraKeys: $baseIsEnv);
```

- **README:** nova seção "Variáveis de ambiente do processo (containers)", com exemplo de entrypoint (ver T6.5).
- **Aceite:** com `A=1` no ambiente e `A=` no exemplo → exit `0`, e nenhuma variável alheia (`PATH`, `HOME`) aparece na saída, nem com `--strict`.

### [ ] T2.6 🟢 Aviso de permissão insegura do `.env`

- **Decisões:** D2, D3. **Local:** novo `src/Support/FilePermissions.php`, `src/Console/Kernel.php`
- **Problema:** um `.env` de produção com permissão `0644` pode ser lido por qualquer usuário do servidor. É um erro comum, e quase nenhuma ferramenta verifica.
- **Correção:**

```php
<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Support;

final class FilePermissions
{
    /**
     * Devolve o modo (ex.: 0644) se "outros" têm qualquer permissão; senão, null.
     * Sempre null no Windows, onde o modelo de permissões é diferente.
     */
    public static function exposedMode(string $path): ?int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }

        clearstatcache(true, $path);
        $perms = @fileperms($path);
        if (false === $perms) {
            return null;
        }

        return 0 !== ($perms & 0o007) ? $perms & 0o777 : null;
    }
}
```

```php
// Kernel
/** @return list<string> */
private function warnings(ParsedEnv $base): array
{
    if ('' === $base->source || ParsedEnv::FROM_ENVIRONMENT === $base->source) {
        return [];
    }

    $mode = FilePermissions::exposedMode($base->source);

    return null === $mode ? [] : [sprintf(
        '%s is accessible by other users (mode %04o); consider chmod 600',
        $base->source,
        $mode,
    )];
}
```

- Só o arquivo `base` é verificado; o `.env.example` não contém segredos.
- O aviso **nunca** muda o exit code, nem com `--strict` (D3).
- **Aceite:** `chmod 644 .env && vendor/bin/envchecker` imprime o aviso e retorna `0`; com `chmod 600`, não há aviso.

### [ ] T2.7 🟢 Sugerir `--base=@env` quando o `.env` não existe

- **Depende de:** T2.5. **Decisão:** D17.
- **Locais:** `src/Exceptions/EnvFileNotFoundException.php`, `src/Console/Kernel.php`
- **Correção:** tornar o caminho público na exceção (`public readonly string $path`) e, no `Kernel`:

```php
private ?string $basePath = null;   // preenchido em run(), antes de ler o base

} catch (EnvFileNotFoundException $e) {
    $this->output->error($e->getMessage());
    if ($e->path === $this->basePath) {
        $this->output->error('hint: if the variables come from the process environment, use --base=@env');
    }

    return self::NOINPUT_ERROR;
}
```

- **Aceite:** sem `.env`, o comando retorna `66` e mostra a dica; sem `.env.example`, não mostra.

---

## Fase 3: Robustez da CLI

### [ ] T3.1 🟠 Opção repetida é aceita em silêncio

- **Local:** `src/Console/Input.php:81`
- **Problema:** `--base=a --base=b` usa `b` sem avisar. Em scripts de deploy, isso esconde erros de configuração.
- **Correção:** dentro do loop de `parse()`, antes de gravar:

```php
if (array_key_exists($argumentName, $this->longOptions)) {
    throw new InvalidCommandArgumentException("--{$argumentName} (repeated)");
}
```

- **README:** "Cada opção pode aparecer uma vez."

### [ ] T3.2 🟠 Cor do STDERR decidida pelo STDOUT

- **Local:** `src/Console/Output.php:22`
- **Problema:** em `envchecker > relatorio.txt`, o `stderr` (terminal) sai sem cor; em `envchecker 2> erros.log`, o log recebe códigos ANSI. Logs de deploy são exatamente o segundo caso.
- **Correção:**

```php
private readonly bool $errorColors;

/**
 * @param resource $out
 * @param resource $err
 */
public function __construct(
    private $out,
    private $err,
    private readonly bool $colors = false,
    ?bool $errorColors = null,             // null = mesmo valor de $colors
) {
    $this->errorColors = $errorColors ?? $colors;
}

public static function fromStd(): self
{
    return new self(STDOUT, STDERR, colors: self::supportsColor(STDOUT), errorColors: self::supportsColor(STDERR));
}

public function style(string $text, int $sgr, ?bool $colors = null): string
{
    return ($colors ?? $this->colors) ? "\033[{$sgr}m{$text}\033[0m" : $text;
}

/** @param resource $stream */
public static function supportsColor($stream): bool       // agora public, para teste
{
    return '' === (string) getenv('NO_COLOR') && stream_isatty($stream);
}
```

### [ ] T3.3 🟠 Raiz do projeto errada na instalação global

- **Depende de:** T0.7. **Decisão:** D18. **Local:** `src/Support/PathResolver.php:16-24`
- **Problema:** com `composer global require`, `InstalledVersions::getRootPackage()` aponta para o diretório global do Composer, e os arquivos padrão são procurados lá (exit `66` em qualquer projeto).
- **Correção:**

```php
public static function fromEnvironment(): self
{
    $cwd = getcwd() ?: '.';
    $root = class_exists(InstalledVersions::class)
        ? realpath(InstalledVersions::getRootPackage()['install_path'])
        : false;

    return new self($cwd, self::chooseProjectRoot($cwd, $root));
}

/**
 * Usa a raiz do Composer só quando o diretório atual está dentro dela.
 * Caso contrário (instalação global), usa o diretório atual.
 */
public static function chooseProjectRoot(string $cwd, false|string $composerRoot): string
{
    if (false === $composerRoot) {
        return $cwd;
    }

    $cwdReal = realpath($cwd) ?: $cwd;
    $root = rtrim($composerRoot, '/\\');

    return $cwdReal === $root || str_starts_with($cwdReal, $root.DIRECTORY_SEPARATOR)
        ? $composerRoot
        : $cwd;
}
```

  > No Windows, a comparação deveria ignorar maiúsculas e minúsculas (`strtolower` quando `PHP_OS_FAMILY === 'Windows'`).

---

## Fase 4: Qualidade de código

### [ ] T4.1 🟡 Hierarquia de exceções

- **Local:** `src/Exceptions/*`
- **Problema:** as exceções não são `final` e não têm tipo comum. Quem usa a biblioteca no próprio código (D15) não consegue fazer `catch` de "qualquer erro do envchecker".
- **Correção:**

```php
<?php

declare(strict_types=1);

namespace Fabriciope\EnvChecker\Exceptions;

interface EnvCheckerException extends \Throwable {}
```

  E nas três exceções: `final class … extends \Exception implements EnvCheckerException`, com as propriedades `public readonly` (necessário para T2.7).

### [ ] T4.2 🟡 Ampliar os testes de arquitetura

- **Local:** `tests/ArchTest.php`. Código final em [tests/ArchTest.php](#testsarchtestphp).
- Inclui os presets `php` e `security` do Pest, `strict_types` em tudo, `Env` final e sem acesso a terminal ou ambiente (`getenv` proibido, o que garante o desenho de T2.5), exceções `final` implementando `EnvCheckerException` e `Console` final.

---

## Fase 5: Flags da CLI

### [ ] T5.1 🟢 Suporte a flags booleanas no `Input`

- **Bloqueia:** T5.2, T5.3, T5.4. **Decisão:** D16.

```php
/** Opções que exigem valor: --nome=valor */
public const array VALUE_OPTIONS = ['base', 'against', 'format'];

/** Flags booleanas: --nome (sem valor) */
public const array FLAG_OPTIONS = ['help', 'version', 'strict'];

private function validateArgument(string $name, ?string $value): bool
{
    if (in_array($name, self::FLAG_OPTIONS, true)) {
        return null === $value;
    }

    return in_array($name, self::VALUE_OPTIONS, true)
        && null !== $value
        && '' !== $value
        && ('format' !== $name || in_array($value, Kernel::ACCEPTED_FORMATS, true))
        && ('against' !== $name || ParsedEnv::FROM_ENVIRONMENT !== $value);     // T2.5
}

public function hasFlag(string $name): bool
{
    return in_array($name, self::FLAG_OPTIONS, true) && $this->hasLongOption($name);
}
```

  > Remova `ACCEPTED_OPTIONS` ou mantenha-a como `[...VALUE_OPTIONS, ...FLAG_OPTIONS]`.

### [ ] T5.2 🟢 `--help`

- **Depende de:** T5.1. No `Kernel::run()`, logo depois de `$this->input->parse()`:

```php
if ($this->input->hasFlag('help')) {
    $this->output->write(self::HELP);

    return self::SUCCESS;
}
```

```php
private const string HELP = <<<'TXT'
    envchecker - validate environment variables against .env.example

    Usage:
      envchecker [--base=<file>|@env] [--against=<file>] [--format=text|json] [--strict]

    Options:
      --base=<file>      File being checked (default: .env at the project root)
      --base=@env        Check the process environment variables instead of a file
      --against=<file>   Reference file (default: .env.example at the project root)
      --format=<format>  Output format: text (default) or json
      --strict           Extra keys also fail (useful in CI)
      --help             Show this help
      --version          Show the version

    Missing, empty and duplicate keys fail. Mark keys that may be empty in the
    reference file with a trailing comment: SENTRY_DSN= # @optional

    Exit codes:
      0 ok · 1 errors found · 64 usage error · 65 invalid file · 66 file not found · 70 internal error

    TXT;
```

### [ ] T5.3 🟢 `--version`

- **Depende de:** T5.1.

```php
use Composer\InstalledVersions;

if ($this->input->hasFlag('version')) {
    $this->output->line('envchecker '.self::version());

    return self::SUCCESS;
}

private static function version(): string
{
    return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('fabriciope/envchecker')
        ? (InstalledVersions::getPrettyVersion('fabriciope/envchecker') ?? 'unknown')
        : 'unknown';
}
```

### [ ] T5.4 🟢 `--strict`

- **Depende de:** T2.3, T5.1. **Decisão:** D3.
- O `Comparator::hasErrors($strict)` já existe (T2.3). Esta tarefa só liga a flag: `$strict = $this->input->hasFlag('strict');`.
- **README:** documentar `--strict` como a opção para CI, com o exemplo `vendor/bin/envchecker --base=.env.testing --strict`.
- **Aceite:** chave sobrando → `0` sem a flag e `1` com ela; aviso de permissão → `0` nos dois casos; em `@env`, sobrando continua ignorada.

---

## Fase 6: Infraestrutura e documentação

### [ ] T6.1 🔵 Scripts do Composer

```json
"scripts": {
    "lint": "php-cs-fixer fix",
    "lint:check": "php-cs-fixer fix --dry-run --diff",
    "analyse": "phpstan analyse --memory-limit=512M",
    "test": "pest --display-warnings",
    "test:coverage": "pest --coverage --min=95",
    "test:mutate": "pest --mutate --min=85",
    "check": ["@lint:check", "@analyse", "@test"]
}
```

### [ ] T6.2 🔵 PHPStan no nível máximo

```bash
composer require --dev phpstan/phpstan
```

`phpstan.neon.dist`:

```neon
parameters:
    level: max
    paths:
        - src
```

- Adicionar `/phpstan.neon.dist export-ignore` ao `.gitattributes`.

### [ ] T6.3 🔵 GitHub Actions

`.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  quality:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: pcov
      - uses: ramsey/composer-install@v3
      - run: composer lint:check
      - run: composer analyse
      - run: composer test:coverage

  tests:
    needs: quality
    strategy:
      fail-fast: false
      matrix:
        os: [ubuntu-latest, windows-latest, macos-latest]
        php: ['8.3', '8.4', '8.5']
        deps: [lowest, highest]
    runs-on: ${{ matrix.os }}
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          coverage: none
      - uses: ramsey/composer-install@v3
        with:
          dependency-versions: ${{ matrix.deps }}
      - run: composer test

  production-smoke:
    # Simula o uso real: pacote instalado sem dev, validando variáveis do processo.
    needs: quality
    runs-on: ubuntu-latest
    env:
      APP_KEY: base64:test
      DB_PASSWORD: secret
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
      - run: composer install --no-dev --no-interaction
      - run: printf 'APP_KEY=\nDB_PASSWORD=\nSENTRY_DSN= # @optional\n' > /tmp/.env.example
      - run: bin/envchecker --base=@env --against=/tmp/.env.example
      - run: "! bin/envchecker --base=@env --against=tests/Fixtures/ok/.env.example"   # deve falhar (exit 1)
```

- O job `production-smoke` garante que o comando funciona **sem as dependências de desenvolvimento**, exatamente como roda num deploy.

### [ ] T6.4 🔵 Cobertura mínima e testes de mutação

- Cobertura: `--min=95` no CI (T6.3).
- Mutação: o `pest --mutate` exige que cada arquivo de teste declare o que cobre. Os arquivos da [suíte final](#suíte-de-testes-completa-código-final) já usam `covers(...)`.
- Rodar `composer test:mutate` localmente antes de cada release.

### [ ] T6.5 🔵 Posicionamento: produção primeiro

- **Decisões:** D1, D15.
- **`composer.json`:**

```json
"description": "Valida as variáveis de ambiente de produção contra o .env.example antes do deploy: chaves faltando, vazias, duplicadas e sobrando",
"keywords": ["env", "dotenv", "env-example", "environment", "validation", "production", "deploy", "docker", "cli", "ci"],
```

- **README, introdução e instalação:**
  - Primeiro parágrafo: o problema (variável esquecida em produção) e a solução (validar no deploy).
  - Instalação: `composer require fabriciope/envchecker` (sem `--dev`), explicando que deploys rodam `composer install --no-dev`. Mencionar `--dev` como alternativa para quem só usa no CI.
- **README, nova seção "Uso em produção"**, com três exemplos:

  Script de deploy (servidor com `.env`):

  ```bash
  composer install --no-dev --optimize-autoloader
  vendor/bin/envchecker || { echo "Ambiente incompleto, deploy abortado"; exit 1; }
  php artisan migrate --force
  ```

  Entrypoint de container (variáveis do processo):

  ```sh
  #!/bin/sh
  set -e
  vendor/bin/envchecker --base=@env
  exec "$@"
  ```

  CI, mantendo o `.env.example` em dia:

  ```yaml
  - name: Verifica variáveis de ambiente
    run: vendor/bin/envchecker --base=.env.testing --strict
  ```

- **README, avisos:**
  - Rode uma vez por deploy ou início de container, **não a cada requisição**.
  - A ferramenta **nunca** imprime valores, então a saída pode ir para logs de deploy.
  - Prefira `chmod 600` no `.env` de produção (a ferramenta avisa quando não estiver assim).
- **README, seção "Uso como biblioteca"** (D15): exemplo com `Parser::parseFile()` + `Comparator`, e um aviso de que `Console\` é interno.

### [ ] T6.6 🔵 Documentação final

- [ ] README: tabelas "O que é verificado", "Exit codes" e JSON iguais às de [Comportamento final esperado](#comportamento-final-esperado).
- [ ] README: seções "Chaves opcionais", "Variáveis de ambiente do processo", `--help`, `--version`, `--strict`, padrão de nome de chave, limite de 1 MiB, comentários no fim da linha, instalação global.
- [ ] README: corrigir a afirmação errada de `README.md:125` (feito em T2.1).
- [ ] README, seção "Estrutura": incluir `docs/`, `ParsedEnv`, `InvalidLine` e `FilePermissions`.
- [ ] CHANGELOG: uma entrada por tarefa em `[Unreleased]` (`Added` / `Changed` / `Fixed` / `Security`).
- [ ] `SECURITY.md`: a ferramenta nunca imprime valores; como reportar vulnerabilidades.

---

## Suíte de testes completa (código final)

Estado final esperado de cada arquivo. O comentário `// [Tx.y]` indica a tarefa que faz o teste passar; testes sem marcação já passam hoje (são regressão).

```text
tests/
├── ArchTest.php
├── Pest.php
├── TestCase.php
├── Feature/
│   ├── CompareParsedEnvTest.php
│   ├── KernelTest.php
│   └── SecurityTest.php            (novo)
├── Unit/
│   ├── ComparatorTest.php          (renomeado de EnvComparatorTest.php)
│   ├── EnvParserTest.php
│   ├── ExceptionsTest.php          (novo)
│   ├── FilePermissionsTest.php     (novo)
│   ├── InputTest.php               (renomeado de InputParserTest.php)
│   ├── OutputTest.php              (novo)
│   └── PathResolverTest.php        (novo)
└── Fixtures/
    ├── ok/                         .env + .env.example
    ├── with_differences/           .env + .env.example
    ├── optional/                   .env + .env.example com "# @optional"   (novo, T2.4)
    ├── parser/                     .env, .env_with_empty_value, .env_with_null_value
    ├── bom/                        .env (com BOM) + .env.example            (novo, T1.5)
    └── crlf/                       .env (CRLF) + .env.example (LF)          (novo)
```

> **Fixtures novas.** `bom/.env` precisa começar com os bytes `EF BB BF` e `crlf/.env` usa `\r\n`. Marque-as no `.gitattributes` para o git não normalizar:
>
> ```text
> /tests/Fixtures/crlf/.env  -text
> /tests/Fixtures/bom/.env   -text
> ```
>
> ```bash
> mkdir -p tests/Fixtures/bom tests/Fixtures/crlf tests/Fixtures/optional
> printf '\xEF\xBB\xBFNAME=a\nAGE=1\n'  > tests/Fixtures/bom/.env
> printf 'NAME=\nAGE=\n'                > tests/Fixtures/bom/.env.example
> printf 'NAME=a\r\nAGE=1\r\n'          > tests/Fixtures/crlf/.env
> printf 'NAME=\nAGE=\n'                > tests/Fixtures/crlf/.env.example
> printf 'APP_KEY=abc\nMAIL_BCC=\n'     > tests/Fixtures/optional/.env
> printf 'APP_KEY=\nSENTRY_DSN= # @optional\nMAIL_BCC="" # @optional\n' > tests/Fixtures/optional/.env.example
> ```
>
> **Permissões nos testes.** Fixtures versionadas costumam ter `0644`, o que gera o aviso de T2.6. Por isso, os testes que comparam a saída **exata** usam `tempEnv()`/`checkContents()`: o `tempnam()` cria arquivos com `0600`. Testes com fixtures usam `toContain`.

### tests/Pest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Input;
use Fabriciope\EnvChecker\Console\Kernel;
use Fabriciope\EnvChecker\Console\Output;
use Fabriciope\EnvChecker\Support\PathResolver;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

function fixture(string $path): string
{
    return __DIR__.'/Fixtures/'.$path;
}

/** Cria um arquivo temporário (modo 0600) com o conteúdo dado; é apagado ao fim do processo. */
function tempEnv(string $content): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'envchecker_');
    file_put_contents($path, $content);
    register_shutdown_function(static fn () => @unlink($path));

    return $path;
}

/**
 * Executa o comando completo com streams em memória (sem cores).
 *
 * @param list<string>               $args
 * @param null|array<string, string> $env  variáveis do processo para --base=@env
 *
 * @return array{0: int, 1: string, 2: string} [exit code, stdout, stderr]
 */
function runEnvChecker(array $args = [], ?PathResolver $paths = null, ?array $env = null): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');

    $code = (new Kernel(Input::make(['envchecker', ...$args]), new Output($out, $err), $paths, $env))->run();

    rewind($out);
    rewind($err);

    return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
}

/**
 * Compara dois conteúdos de .env passados como string (arquivos temporários 0600).
 *
 * @return array{0: int, 1: string, 2: string}
 */
function checkContents(string $base, string $against, string ...$extraArgs): array
{
    return runEnvChecker(['--base='.tempEnv($base), '--against='.tempEnv($against), ...$extraArgs]);
}

/**
 * Compara as variáveis de processo dadas com um .env.example passado como string.
 *
 * @param array<string, string> $env
 *
 * @return array{0: int, 1: string, 2: string}
 */
function checkEnvironment(array $env, string $against, string ...$extraArgs): array
{
    return runEnvChecker(['--base=@env', '--against='.tempEnv($against), ...$extraArgs], env: $env);
}

function skipOnWindows(): bool
{
    return PHP_OS_FAMILY === 'Windows';
}
```

### tests/ArchTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Exceptions\EnvCheckerException;

arch()->preset()->php();                                                       // [T4.2]
arch()->preset()->security();                                                  // [T4.2]

arch('todo o código usa strict_types')                                         // [T4.2]
    ->expect('Fabriciope\EnvChecker')
    ->toUseStrictTypes();

arch('o domínio não conhece o terminal nem o suporte')
    ->expect('Fabriciope\EnvChecker\Env')
    ->not->toUse(['Fabriciope\EnvChecker\Console', 'Fabriciope\EnvChecker\Support']);

arch('o domínio não lê o ambiente do processo')                                // [T4.2]
    ->expect('Fabriciope\EnvChecker\Env')
    ->not->toUse(['getenv', 'putenv', 'getcwd', 'fwrite', 'printf']);

arch('classes do domínio são final')                                           // [T1.1]
    ->expect('Fabriciope\EnvChecker\Env')
    ->classes()
    ->toBeFinal();

arch('classes do Console são final')
    ->expect('Fabriciope\EnvChecker\Console')
    ->classes()
    ->toBeFinal();

arch('exceções são final e têm um tipo comum')                                 // [T4.1]
    ->expect('Fabriciope\EnvChecker\Exceptions')
    ->classes()
    ->toBeFinal()
    ->toImplement(EnvCheckerException::class)
    ->toExtend(Exception::class);

arch('nada de funções de debug no código distribuído')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray'])
    ->not->toBeUsed();
```

> Se o preset `php` reclamar de algo em `tests/` (por exemplo, `@unlink` em `Pest.php`), use `->ignoring('Tests')`.

### tests/Unit/EnvParserTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Env\InvalidLine;
use Fabriciope\EnvChecker\Env\ParsedEnv;
use Fabriciope\EnvChecker\Env\Parser;
use Fabriciope\EnvChecker\Exceptions\EnvFileNotFoundException;
use Fabriciope\EnvChecker\Exceptions\InvalidEnvFileException;

covers(Parser::class, ParsedEnv::class, InvalidLine::class);

describe('parseFile', function () {
    it('parses a file into key/value pairs', function () {
        $parsed = Parser::parseFile(fixture('parser/.env'));

        expect($parsed)->toBeInstanceOf(ParsedEnv::class)                         // [T1.1]
            ->and($parsed->values)->toBe(['NAME' => 'fabricio', 'AGE' => '21', 'OCCUPATION' => 'developer'])
            ->and($parsed->source)->toBe(fixture('parser/.env'));
    });

    it('keeps keys with an empty value', function () {
        expect(Parser::parseFile(fixture('parser/.env_with_empty_value'))->values)
            ->toBe(['API_KEY' => '123456', 'API_KEY2' => '654321', 'TEST' => '']);
    });

    it('treats the literal "null" as a non-empty value', function () {                // [T0.3]
        expect(Parser::parseFile(fixture('parser/.env_with_null_value'))->values)->toBe(['TEST' => 'null']);
    });

    it('throws when the file does not exist', function () {
        Parser::parseFile(fixture('.wrong-env-file'));
    })->throws(EnvFileNotFoundException::class);

    it('throws when the path is a directory', function () {
        Parser::parseFile(fixture('ok'));
    })->throws(EnvFileNotFoundException::class);

    it('throws when the file is not readable', function () {
        $path = tempEnv("A=1\n");
        chmod($path, 0o000);

        try {
            Parser::parseFile($path);
        } finally {
            chmod($path, 0o600);
        }
    })->throws(EnvFileNotFoundException::class)
        ->skip(
            fn () => skipOnWindows() || (function_exists('posix_geteuid') && 0 === posix_geteuid()),
            'Permissões não se aplicam no Windows nem para root',
        );

    it('throws when the file has invalid variable names', function () {             // [T1.3]
        Parser::parseFile(tempEnv("OK=1\nMY KEY=1\n"));
    })->throws(InvalidEnvFileException::class, 'line 2 (MY KEY)');

    it('rejects files larger than the limit', function () {                         // [T1.6]
        Parser::parseFile(tempEnv(str_repeat('#', Parser::MAX_FILE_SIZE + 1)));
    })->throws(InvalidEnvFileException::class, 'larger than');

    it('accepts a file exactly at the size limit', function () {                    // [T1.6]
        $content = 'A=1'.PHP_EOL;
        $content .= '#'.str_repeat('x', Parser::MAX_FILE_SIZE - strlen($content) - 1);

        expect(strlen($content))->toBe(Parser::MAX_FILE_SIZE)
            ->and(Parser::parseFile(tempEnv($content))->values)->toBe(['A' => '1']);
    });
});

describe('values', function () {
    it('parses the value', function (string $line, string $expected) {
        expect(Parser::parse($line)->values)->toBe(['KEY' => $expected]);
    })->with([
        'simple' => ['KEY=value', 'value'],
        'spaces around key and value' => ['  KEY  =  value  ', 'value'],
        'equals inside the value' => ['KEY=a=b=c', 'a=b=c'],
        'empty' => ['KEY=', ''],
        'only spaces' => ['KEY=   ', ''],
        'double quotes' => ['KEY="value"', 'value'],
        'single quotes' => ["KEY='value'", 'value'],
        'empty double quotes' => ['KEY=""', ''],
        'empty single quotes' => ["KEY=''", ''],
        'spaces inside quotes' => ['KEY="   "', ''],                                     // [T2.1]
        'hash inside double quotes' => ['KEY="a # b"', 'a # b'],                         // [T2.1]
        'hash inside single quotes' => ["KEY='a # b'", 'a # b'],                         // [T2.1]
        'quoted value then comment' => ['KEY="value" # comment', 'value'],               // [T2.1]
        'empty quotes then comment' => ['KEY="" # comment', ''],                         // [T2.1]
        'escaped quote inside' => ['KEY="say \"hi\""', 'say \"hi\"'],                    // [T2.1]
        'mismatched quotes kept literal' => ['KEY="foo\'', '"foo\''],                    // [T2.1]
        'unclosed quote kept literal' => ['KEY="foo', '"foo'],                           // [T2.1]
        'quote char alone kept literal' => ["KEY='\"'", '"'],                            // [T2.1]
        'inline comment' => ['KEY=value # comment', 'value'],                            // [T2.1]
        'only a comment' => ['KEY= # fill me', ''],                                      // [T2.1]
        'comment glued to equals' => ['KEY=#fill me', ''],                               // [T2.1]
        'tab before comment' => ["KEY=value\t# comment", 'value'],                       // [T2.1]
        'hash glued to the value' => ['KEY=abc#def', 'abc#def'],
        'url with fragment' => ['KEY=https://x.com/a#frag', 'https://x.com/a#frag'],
        'literal null' => ['KEY=null', 'null'],
        'utf-8 value' => ['KEY=ação', 'ação'],
        'utf-8 byte 0x85 inside value' => ["KEY=\u{C5}SA", "\u{C5}SA"],                  // [T1.2]
        'export prefix' => ['export KEY=value', 'value'],
        'export with tab' => ["export\tKEY=value", 'value'],                             // [T2.2]
        'export with many spaces' => ['export    KEY=value', 'value'],                   // [T2.2]
    ]);

    it('does not treat "export" without whitespace as a prefix', function () {        // [T2.2]
        expect(Parser::parse("export=1\nexporter=2")->values)->toBe(['export' => '1', 'exporter' => '2']);
    });
});

describe('lines', function () {
    it('accepts every line ending', function (string $content) {
        expect(Parser::parse($content)->keys())->toBe(['A', 'B']);
    })->with([
        'LF' => ["A=1\nB=2"],
        'CRLF' => ["A=1\r\nB=2"],
        'CR' => ["A=1\rB=2"],
        'trailing newline' => ["A=1\nB=2\n"],
        'mixed' => ["A=1\r\nB=2\n"],
    ]);

    it('does not split lines on bytes inside UTF-8 characters', function () {          // [T1.2]
        $parsed = Parser::parse("URL=https://x?q=\u{C5}&b=1\nNAME=x");

        expect($parsed->keys())->toBe(['URL', 'NAME'])
            ->and($parsed->values['URL'])->toBe("https://x?q=\u{C5}&b=1");
    });

    it('does not split lines on vertical tab or form feed', function () {              // [T1.2]
        expect(Parser::parse("A=x\vy\fz")->values)->toBe(['A' => "x\vy\fz"]);
    });

    it('ignores blank lines, comments and lines without "="', function () {
        $content = <<<'ENV'

            # a comment
              # an indented comment
            A=1
            JUST_TEXT

            B=2
            ENV;

        expect(Parser::parse($content)->keys())->toBe(['A', 'B']);
    });

    it('returns an empty result for empty content', function (string $content) {
        expect(Parser::parse($content)->values)->toBe([]);
    })->with(['', "\n\n", "# only comments\n# here", "\xEF\xBB\xBF"]);

    it('strips the UTF-8 BOM', function () {                                           // [T1.5]
        expect(Parser::parse("\xEF\xBB\xBFNAME=a\nAGE=1")->keys())->toBe(['NAME', 'AGE']);
    });

    it('only strips the BOM at the very beginning', function () {                      // [T1.5]
        expect(Parser::parse("A=\xEF\xBB\xBFx")->values)->toBe(['A' => "\xEF\xBB\xBFx"]);
    });
});

describe('key validation', function () {                                               // [T1.3]
    it('accepts valid key names', function (string $key) {
        $parsed = Parser::parse("{$key}=1");

        expect($parsed->isValid())->toBeTrue()
            ->and($parsed->keys())->toBe([$key]);
    })->with(['A', 'a', '_A', 'A1', 'APP_NAME', 'app.name', 'A__B', 'export']);

    it('rejects invalid key names', function (string $line, string $key) {
        $parsed = Parser::parse($line);

        expect($parsed->isValid())->toBeFalse()
            ->and($parsed->values)->toBe([])
            ->and($parsed->invalidLines)->toHaveCount(1)
            ->and($parsed->invalidLines[0]->key)->toBe($key);
    })->with([
        'starts with a digit' => ['1A=x', '1A'],
        'numeric' => ['123=x', '123'],
        'space inside' => ['MY KEY=x', 'MY KEY'],
        'empty key' => ['=x', ''],
        'dash' => ['KEY-NAME=x', 'KEY-NAME'],
        'non-ascii' => ['AÇÃO=x', 'AÇÃO'],
        'escape sequence' => ["X\x1B[2J=1", "X\x1B[2J"],
        'osc + bel' => ["X\x1B]0;pwned\x07=1", "X\x1B]0;pwned\x07"],
        'null byte' => ["A\x00B=1", "A\x00B"],
    ]);

    it('reports the right line numbers, counting blank and comment lines', function () {
        $parsed = Parser::parse("# c\n\nOK=1\nMY KEY=1\n1A=2");

        expect(array_map(fn (InvalidLine $l) => [$l->number, $l->key], $parsed->invalidLines))
            ->toBe([[4, 'MY KEY'], [5, '1A']])
            ->and($parsed->values)->toBe(['OK' => '1']);
    });

    it('never returns integer keys', function () {
        expect(Parser::parse("A=1\n123=2")->keys())->each->toBeString();
    });
});

describe('duplicate keys', function () {                                               // [T1.3]
    it('reports duplicate keys and keeps the last value', function () {
        $parsed = Parser::parse("A=1\nB=2\nA=3");

        expect($parsed->values)->toBe(['A' => '3', 'B' => '2'])
            ->and($parsed->duplicateKeys)->toBe(['A']);
    });

    it('lists a key only once even with three occurrences', function () {
        expect(Parser::parse("A=1\nA=2\nA=3")->duplicateKeys)->toBe(['A']);
    });

    it('treats "export A" and "A" as the same key', function () {
        expect(Parser::parse("export A=1\nA=2")->duplicateKeys)->toBe(['A']);
    });

    it('does not count commented-out lines', function () {
        expect(Parser::parse("A=1\n# A=2")->duplicateKeys)->toBe([]);
    });

    it('lets an empty last value win over a filled one', function () {
        expect(Parser::parse("A=1\nA=")->values)->toBe(['A' => '']);
    });
});

describe('optional keys', function () {                                                // [T2.4]
    it('detects the @optional annotation', function (string $line) {
        expect(Parser::parse($line)->optionalKeys)->toBe(['KEY']);
    })->with([
        'empty value' => ['KEY= # @optional'],
        'glued to the hash' => ['KEY= #@optional'],
        'with a value' => ['KEY=default # @optional'],
        'double quotes' => ['KEY="" # @optional'],
        'single quotes' => ["KEY='x' # @optional"],
        'with an explanation after' => ['KEY= # @optional only some clients use it'],
        'with an explanation before' => ['KEY= # used by Sentry, @optional'],
    ]);

    it('ignores anything that is not exactly the annotation', function (string $line) {
        expect(Parser::parse($line)->optionalKeys)->toBe([]);
    })->with([
        'no comment' => ['KEY='],
        'plain word' => ['KEY= # optional'],
        'longer word' => ['KEY= # @optionals'],
        'prefix' => ['KEY= # not@optional'],
        'hash glued to the value is part of the value' => ['KEY=a#@optional'],
        'inside quotes' => ['KEY="# @optional"'],
    ]);

    it('does not include the annotation in the value', function () {
        expect(Parser::parse('KEY=default # @optional')->values)->toBe(['KEY' => 'default']);
    });

    it('uses the last occurrence of a duplicate key', function () {
        expect(Parser::parse("A= # @optional\nA=")->optionalKeys)->toBe([])
            ->and(Parser::parse("A=\nA= # @optional")->optionalKeys)->toBe(['A']);
    });

    it('ignores the annotation on a line of its own', function () {
        expect(Parser::parse("# @optional\nA=")->optionalKeys)->toBe([]);
    });

    it('reads the optional fixture', function () {
        expect(Parser::parseFile(fixture('optional/.env.example'))->optionalKeys)
            ->toBe(['SENTRY_DSN', 'MAIL_BCC']);
    });
});

describe('fromEnvironment', function () {                                              // [T2.5]
    it('builds a ParsedEnv from process variables', function () {
        $parsed = Parser::fromEnvironment(['A' => '1', 'B' => '']);

        expect($parsed->values)->toBe(['A' => '1', 'B' => ''])
            ->and($parsed->source)->toBe(ParsedEnv::FROM_ENVIRONMENT)
            ->and($parsed->duplicateKeys)->toBe([])
            ->and($parsed->optionalKeys)->toBe([]);
    });

    it('silently skips names that are not valid keys', function () {
        expect(Parser::fromEnvironment([
            '=C:' => 'C:\\',
            'ProgramFiles(x86)' => 'x',
            0 => 'x',
            'A' => '1',
        ])->values)->toBe(['A' => '1']);
    });

    it('skips non-string values', function () {
        expect(Parser::fromEnvironment(['A' => ['x'], 'B' => '1'])->values)->toBe(['B' => '1']);
    });

    it('uses values as they are, without parsing quotes or comments', function () {
        expect(Parser::fromEnvironment(['A' => '"x"', 'B' => 'y # z'])->values)
            ->toBe(['A' => '"x"', 'B' => 'y # z']);
    });
});
```

### tests/Unit/ComparatorTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Env\Comparator;
use Fabriciope\EnvChecker\Env\ParsedEnv;

covers(Comparator::class);

it('passes when every required key is set, regardless of order and values', function () {
    $c = Comparator::fromArrays(
        base: ['NAME' => 'joao', 'AGE' => '20'],
        against: ['AGE' => '18', 'NAME' => 'example'],
    );

    expect($c->hasErrors())->toBeFalse()
        ->and($c->missingKeys)->toBe([])
        ->and($c->emptyKeys)->toBe([])
        ->and($c->extraKeys)->toBe([]);
});

it('passes for two empty files', function () {
    expect(Comparator::fromArrays([], [])->hasErrors())->toBeFalse();
});

it('reports missing keys as an error, in the order of the example file', function () {
    $c = Comparator::fromArrays(['NAME' => 'pedro'], ['NAME' => 'x', 'OCCUPATION' => 'x', 'AGE' => '18']);

    expect($c->missingKeys)->toBe(['OCCUPATION', 'AGE'])
        ->and($c->hasMissingKeys())->toBeTrue()
        ->and($c->hasErrors())->toBeTrue();
});

it('reports empty keys as an error', function () {                                      // [T2.3]
    $c = Comparator::fromArrays(['A' => '', 'B' => '1'], ['A' => 'x', 'B' => 'y']);

    expect($c->emptyKeys)->toBe(['A'])
        ->and($c->hasEmptyKeys())->toBeTrue()
        ->and($c->hasErrors())->toBeTrue();
});

it('treats null and whitespace-only values as empty', function () {                    // [T2.3]
    expect(Comparator::fromArrays(['A' => null, 'B' => '  '], ['A' => 'x', 'B' => 'y'])->emptyKeys)
        ->toBe(['A', 'B']);
});

it('treats "0" and "false" as set', function () {
    expect(Comparator::fromArrays(['A' => '0', 'B' => 'false'], ['A' => '', 'B' => ''])->emptyKeys)->toBe([]);
});

it('ignores the values of the example file', function () {
    expect(Comparator::fromArrays(['A' => '1'], ['A' => ''])->hasErrors())->toBeFalse();
});

it('does not report a key that only exists in the base file as empty', function () {
    $c = Comparator::fromArrays(['EXTRA' => ''], []);

    expect($c->emptyKeys)->toBe([])
        ->and($c->extraKeys)->toBe(['EXTRA']);
});

it('reports extra keys as a warning, and as an error only in strict mode', function () { // [T2.3]
    $c = Comparator::fromArrays(['NAME' => 'x', 'GENDER' => 'm', 'B' => '1'], ['NAME' => '']);

    expect($c->extraKeys)->toBe(['GENDER', 'B'])
        ->and($c->hasExtraKeys())->toBeTrue()
        ->and($c->hasErrors())->toBeFalse()
        ->and($c->hasErrors(strict: true))->toBeTrue();
});

it('reports duplicate keys in either file as an error', function (ParsedEnv $base, ParsedEnv $against) { // [T2.3]
    $c = new Comparator($base, $against);

    expect($c->hasDuplicateKeys())->toBeTrue()
        ->and($c->hasErrors())->toBeTrue();
})->with([
    'duplicate in base' => [new ParsedEnv(['A' => '1'], ['A']), new ParsedEnv(['A' => ''])],
    'duplicate in against' => [new ParsedEnv(['A' => '1']), new ParsedEnv(['A' => ''], ['A'])],
]);

it('always returns keys as strings', function () {                                      // [T2.3]
    $c = Comparator::fromArrays([123 => 'x'], [456 => 'y']);

    expect($c->missingKeys)->toBe(['456'])
        ->and($c->extraKeys)->toBe(['123']);
});

describe('optional keys', function () {                                                 // [T2.4]
    it('does not report a missing optional key', function () {
        $c = Comparator::fromArrays(['A' => '1'], ['A' => '', 'SENTRY' => ''], optionalKeys: ['SENTRY']);

        expect($c->missingKeys)->toBe([])
            ->and($c->hasErrors())->toBeFalse();
    });

    it('does not report an empty optional key', function () {
        $c = Comparator::fromArrays(['A' => '1', 'SENTRY' => ''], ['A' => '', 'SENTRY' => ''], optionalKeys: ['SENTRY']);

        expect($c->emptyKeys)->toBe([])
            ->and($c->hasErrors())->toBeFalse();
    });

    it('does not report a filled optional key as extra', function () {
        $c = Comparator::fromArrays(['SENTRY' => 'dsn'], ['SENTRY' => ''], optionalKeys: ['SENTRY']);

        expect($c->extraKeys)->toBe([])
            ->and($c->hasErrors(strict: true))->toBeFalse();
    });

    it('only honors the annotation in the reference file', function () {
        $c = new Comparator(
            new ParsedEnv(['SENTRY' => ''], optionalKeys: ['SENTRY']),
            new ParsedEnv(['SENTRY' => '']),
        );

        expect($c->emptyKeys)->toBe(['SENTRY']);
    });
});

describe('ignoring extra keys (process environment)', function () {                    // [T2.5]
    it('never reports extra keys, not even in strict mode', function () {
        $c = new Comparator(
            new ParsedEnv(['A' => '1', 'PATH' => '/bin', 'HOME' => '/root']),
            new ParsedEnv(['A' => '']),
            ignoreExtraKeys: true,
        );

        expect($c->extraKeys)->toBe([])
            ->and($c->hasErrors(strict: true))->toBeFalse();
    });

    it('still reports missing and empty keys', function () {
        $c = new Comparator(new ParsedEnv(['A' => '']), new ParsedEnv(['A' => '', 'B' => '']), ignoreExtraKeys: true);

        expect($c->missingKeys)->toBe(['B'])
            ->and($c->emptyKeys)->toBe(['A']);
    });
});
```

### tests/Unit/InputTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Input;
use Fabriciope\EnvChecker\Exceptions\InvalidCommandArgumentException;

covers(Input::class);

function parsedInput(string ...$args): Input
{
    $input = Input::make(['envchecker', ...$args]);
    $input->parse();

    return $input;
}

describe('parsing', function () {
    it('accepts no arguments', function () {
        expect(parsedInput()->allOptions())->toBe([]);
    });

    it('recognizes all value options', function () {
        $input = parsedInput('--base=.env', '--against=.env.example', '--format=json');

        expect($input->getLongOption('base'))->toBe('.env')
            ->and($input->getLongOption('against'))->toBe('.env.example')
            ->and($input->getLongOption('format'))->toBe('json');
    });

    it('accepts edge-case values', function (string $arg, string $expected) {
        expect(parsedInput($arg)->getLongOption('base'))->toBe($expected);
    })->with([
        'zero' => ['--base=0', '0'],                                                    // [T0.5]
        'equals inside value' => ['--base=a=b', 'a=b'],
        'spaces inside value' => ['--base=my file.env', 'my file.env'],
        'surrounding spaces trimmed' => ['  --base=x  ', 'x'],
        'absolute path' => ['--base=/etc/app/.env', '/etc/app/.env'],
        'windows path' => ['--base=C:\\app\\.env', 'C:\\app\\.env'],
        'process environment' => ['--base=@env', '@env'],
        'file literally named @env' => ['--base=./@env', './@env'],
    ]);

    it('rejects invalid arguments', function (array $args) {
        parsedInput(...$args);
    })->throws(InvalidCommandArgumentException::class)->with([
        'short flag' => [['-x']],
        'single dash long name' => [['-base']],
        'positional argument' => [['base']],
        'only dashes' => [['--']],
        'empty name' => [['--=x']],
        'unknown option' => [['--fromat=json']],
        'empty value' => [['--base=']],
        'missing value' => [['--base']],                                                // [T0.3]
        'unknown format' => [['--format=xml']],
        'format is case-sensitive' => [['--format=JSON']],
        'process environment as reference' => [['--against=@env']],                    // [T2.5]
        'repeated option' => [['--base=a', '--base=b']],                                // [T3.1]
        'flag with value' => [['--strict=1']],                                          // [T5.1]
        'help with value' => [['--help=yes']],                                          // [T5.1]
        'version with empty value' => [['--version=']],                                 // [T5.1]
        'repeated flag' => [['--strict', '--strict']],                                  // [T3.1/T5.1]
    ]);

    it('mentions the offending argument in the message', function (string $arg, string $expected) {
        expect(fn () => parsedInput($arg))->toThrow(InvalidCommandArgumentException::class, $expected);
    })->with([
        ['-x', '-x'],
        ['--fromat=json', '--fromat'],
        ['--format=xml', '--format'],
    ]);

    it('stops at the first invalid argument', function () {
        expect(fn () => parsedInput('--base=a', '-x', '--nope'))
            ->toThrow(InvalidCommandArgumentException::class, '-x');
    });
});

describe('reading options', function () {
    it('returns the default value for an option that was not given', function () {
        expect(parsedInput()->getLongOption('base', 'default'))->toBe('default')
            ->and(parsedInput()->getLongOption('base'))->toBe('');
    });

    it('reports whether an option was given', function () {
        $input = parsedInput('--base=x');

        expect($input->hasLongOption('base'))->toBeTrue()
            ->and($input->hasLongOption('against'))->toBeFalse();
    });
});

describe('boolean flags', function () {                                                // [T5.1]
    it('recognizes boolean flags', function (string $flag) {
        expect(parsedInput("--{$flag}")->hasFlag($flag))->toBeTrue();
    })->with(['help', 'version', 'strict']);

    it('reports absent flags as false', function () {
        expect(parsedInput()->hasFlag('strict'))->toBeFalse();
    });

    it('does not treat value options as flags', function () {
        expect(parsedInput('--base=x')->hasFlag('base'))->toBeFalse();
    });

    it('combines flags and value options', function () {
        $input = parsedInput('--strict', '--format=json', '--base=@env');

        expect($input->hasFlag('strict'))->toBeTrue()
            ->and($input->getLongOption('format'))->toBe('json')
            ->and($input->getLongOption('base'))->toBe('@env');
    });
});
```

### tests/Unit/OutputTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Output;

covers(Output::class);

/** @return array{0: Output, 1: resource, 2: resource} */
function memoryOutput(bool $colors = false, ?bool $errorColors = null): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');

    return [new Output($out, $err, colors: $colors, errorColors: $errorColors), $out, $err];
}

/** @param resource $stream */
function readStream($stream): string
{
    rewind($stream);

    return (string) stream_get_contents($stream);
}

it('writes text to stdout without a newline', function () {
    [$output, $out, $err] = memoryOutput();
    $output->write('abc');

    expect(readStream($out))->toBe('abc')->and(readStream($err))->toBe('');
});

it('writes a line to stdout with PHP_EOL', function () {
    [$output, $out] = memoryOutput();
    $output->line('abc');

    expect(readStream($out))->toBe('abc'.PHP_EOL);
});

it('writes errors to stderr with the program prefix', function () {
    [$output, $out, $err] = memoryOutput();
    $output->error('boom');

    expect(readStream($err))->toBe('envchecker: boom'.PHP_EOL)
        ->and(readStream($out))->toBe('');
});

it('styles text only when colors are enabled', function () {
    [$plain] = memoryOutput(colors: false);
    [$colored] = memoryOutput(colors: true);

    expect($plain->style('x', 31))->toBe('x')
        ->and($colored->style('x', 31))->toBe("\033[31mx\033[0m");
});

it('decides stderr colors independently from stdout', function () {                  // [T3.2]
    [$output, $out, $err] = memoryOutput(colors: false, errorColors: true);
    $output->line('report');
    $output->error('boom');

    expect(readStream($out))->toBe('report'.PHP_EOL)
        ->and(readStream($err))->toBe("\033[31menvchecker: \033[0mboom".PHP_EOL);
});

it('does not support colors on non-tty streams', function () {                         // [T3.2]
    expect(Output::supportsColor(fopen('php://memory', 'r')))->toBeFalse();
});

it('does not support colors when NO_COLOR is set', function () {                        // [T3.2]
    putenv('NO_COLOR=1');

    try {
        expect(Output::supportsColor(STDOUT))->toBeFalse();
    } finally {
        putenv('NO_COLOR');
    }
});

describe('sanitize', function () {                                                     // [T1.4]
    it('escapes control characters', function (string $input, string $expected) {
        expect(Output::sanitize($input))->toBe($expected);
    })->with([
        'escape' => ["a\x1B[2Jb", 'a\x1B[2Jb'],
        'bel' => ["a\x07b", 'a\x07b'],
        'null byte' => ["a\x00b", 'a\x00b'],
        'newline' => ["a\nb", 'a\x0Ab'],
        'carriage return' => ["a\rb", 'a\x0Db'],
        'tab' => ["a\tb", 'a\x09b'],
        'del' => ["a\x7Fb", 'a\x7Fb'],
        '8-bit CSI (C1)' => ["a\u{9B}b", 'a\xC2\x9Bb'],
    ]);

    it('keeps regular text and UTF-8 untouched', function (string $text) {
        expect(Output::sanitize($text))->toBe($text);
    })->with(['plain', 'ação ÅSA 日本', '/path/to/.env', 'C:\\app\\.env', '']);

    it('sanitizes error messages', function () {
        [$output, , $err] = memoryOutput();
        $output->error("bad \x1B]0;pwned\x07 path");

        expect(readStream($err))->not->toContain("\x1B")
            ->and(readStream($err))->toContain('\x1B]0;pwned\x07');
    });
});
```

### tests/Unit/PathResolverTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Support\PathResolver;

covers(PathResolver::class);

const DS = DIRECTORY_SEPARATOR;

describe('join', function () {
    it('joins relative paths to the current directory', function () {
        expect((new PathResolver('/work', '/root'))->fromCwd('.env'))->toBe('/work'.DS.'.env');
    });

    it('joins relative paths to the project root', function () {
        expect((new PathResolver('/work', '/root'))->fromProjectRoot('.env'))->toBe('/root'.DS.'.env');
    });

    it('does not duplicate the separator when the base ends with one', function (string $base) {
        expect((new PathResolver($base, $base))->fromCwd('.env'))->toBe(rtrim($base, '/\\').DS.'.env');
    })->with(['/work/', '/work\\', 'C:\\work\\']);

    it('keeps subdirectories in relative paths', function () {
        expect((new PathResolver('/work', '/root'))->fromCwd('config/.env'))->toBe('/work'.DS.'config/.env');
    });

    it('keeps absolute paths as they are', function (string $path) {
        $resolver = new PathResolver('/work', '/root');

        expect($resolver->fromCwd($path))->toBe($path)
            ->and($resolver->fromProjectRoot($path))->toBe($path);
    })->with([
        'unix' => ['/etc/app/.env'],
        'windows backslash' => ['C:\\app\\.env'],
        'windows forward slash' => ['C:/app/.env'],
        'lowercase drive' => ['d:\\app\\.env'],
        'unc' => ['\\\\server\\share\\.env'],
    ]);

    it('does not treat a drive-like relative path as absolute', function () {
        expect((new PathResolver('/work', '/root'))->fromCwd('C:file'))->toBe('/work'.DS.'C:file');
    });
});

describe('chooseProjectRoot', function () {                                            // [T3.3]
    beforeEach(function () {
        $this->root = (string) realpath(dirname(__DIR__, 2));
        $this->inside = $this->root.DS.'src';
        $this->outside = (string) realpath(sys_get_temp_dir());
    });

    it('uses the composer root when the cwd is inside it', function () {
        expect(PathResolver::chooseProjectRoot($this->inside, $this->root))->toBe($this->root);
    });

    it('uses the composer root when the cwd is the root itself', function () {
        expect(PathResolver::chooseProjectRoot($this->root, $this->root))->toBe($this->root);
    });

    it('uses the cwd when it is outside the composer root (global install)', function () {
        expect(PathResolver::chooseProjectRoot($this->outside, $this->root))->toBe($this->outside);
    });

    it('uses the cwd when there is no composer root', function () {
        expect(PathResolver::chooseProjectRoot($this->inside, false))->toBe($this->inside);
    });

    it('does not match a sibling directory with the same prefix', function () {
        expect(PathResolver::chooseProjectRoot('/app-other', '/app'))->toBe('/app-other')
            ->and(PathResolver::chooseProjectRoot('/application', '/app'))->toBe('/application');
    });
});

it('builds itself from the environment', function () {
    expect(PathResolver::fromEnvironment())->toBeInstanceOf(PathResolver::class);
});
```

### tests/Unit/FilePermissionsTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Support\FilePermissions;

covers(FilePermissions::class);                                                        // [T2.6]

function fileWithMode(int $mode): string
{
    $path = tempEnv('A=1');
    chmod($path, $mode);

    return $path;
}

it('returns the mode when other users have any permission', function (int $mode) {
    expect(FilePermissions::exposedMode(fileWithMode($mode)))->toBe($mode);
})->with([
    'world-readable' => [0o644],
    'world-writable' => [0o602],
    'world-executable' => [0o601],
    'everything' => [0o777],
])->skip(skipOnWindows(), 'POSIX permissions only');

it('returns null when only the owner or group have permissions', function (int $mode) {
    expect(FilePermissions::exposedMode(fileWithMode($mode)))->toBeNull();
})->with([
    'owner only' => [0o600],
    'owner read-only' => [0o400],
    'group readable' => [0o640],
    'group writable' => [0o660],
])->skip(skipOnWindows(), 'POSIX permissions only');

it('sees permission changes without a stale stat cache', function () {
    $path = fileWithMode(0o600);
    FilePermissions::exposedMode($path);
    chmod($path, 0o644);

    expect(FilePermissions::exposedMode($path))->toBe(0o644);
})->skip(skipOnWindows(), 'POSIX permissions only');

it('returns null for a file that does not exist', function () {
    expect(FilePermissions::exposedMode('/nonexistent/.env'))->toBeNull();
});
```

### tests/Unit/ExceptionsTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Env\InvalidLine;
use Fabriciope\EnvChecker\Exceptions\EnvCheckerException;
use Fabriciope\EnvChecker\Exceptions\EnvFileNotFoundException;
use Fabriciope\EnvChecker\Exceptions\InvalidCommandArgumentException;
use Fabriciope\EnvChecker\Exceptions\InvalidEnvFileException;

covers(EnvFileNotFoundException::class, InvalidCommandArgumentException::class, InvalidEnvFileException::class);

it('builds the file not found message and exposes the path', function () {
    $e = new EnvFileNotFoundException('/x/.env');

    expect($e->getMessage())->toBe('Env file could not be found: /x/.env')
        ->and($e->path)->toBe('/x/.env');                                              // [T2.7]
});

it('builds the invalid argument message', function () {
    expect((new InvalidCommandArgumentException('--nope'))->getMessage())
        ->toBe('Invalid argument passed to the command: --nope');
});

it('lists every invalid line with its number', function () {                            // [T1.3]
    $e = InvalidEnvFileException::invalidKeys('/x/.env', [new InvalidLine(2, 'MY KEY'), new InvalidLine(5, '1A')]);

    expect($e->getMessage())->toBe('Invalid variable names in /x/.env: line 2 (MY KEY), line 5 (1A)');
});

it('builds the file too large message', function () {                                   // [T1.6]
    expect(InvalidEnvFileException::tooLarge('/x/.env', 10)->getMessage())
        ->toBe('Env file is larger than 10 bytes: /x/.env');
});

it('shares a common exception type', function (Throwable $e) {                          // [T4.1]
    expect($e)->toBeInstanceOf(EnvCheckerException::class);
})->with([
    fn () => new EnvFileNotFoundException('x'),
    fn () => new InvalidCommandArgumentException('x'),
    fn () => InvalidEnvFileException::tooLarge('x', 1),
]);
```

### tests/Feature/CompareParsedEnvTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Env\Comparator;
use Fabriciope\EnvChecker\Env\Parser;

function compareFixture(string $dir): Comparator
{
    return new Comparator(
        Parser::parseFile(fixture("{$dir}/.env")),
        Parser::parseFile(fixture("{$dir}/.env.example")),
    );
}

it('finds every kind of difference in the with_differences fixture', function () {
    $c = compareFixture('with_differences');

    expect($c->hasErrors())->toBeTrue()
        ->and($c->missingKeys)->toBe(['JOB'])
        ->and($c->emptyKeys)->toBe(['AGE'])
        ->and($c->extraKeys)->toBe(['GENDER', 'HEIGHT']);
});

it('finds no errors in the ok fixture', function () {
    expect(compareFixture('ok')->hasErrors(strict: true))->toBeFalse();
});

it('ignores line endings', function () {
    expect(compareFixture('crlf')->hasErrors(strict: true))->toBeFalse();
});

it('ignores the BOM', function () {                                                      // [T1.5]
    expect(compareFixture('bom')->hasErrors(strict: true))->toBeFalse();
});

it('honors optional keys from the example file', function () {                           // [T2.4]
    $c = compareFixture('optional');

    expect($c->hasErrors(strict: true))->toBeFalse()
        ->and($c->missingKeys)->toBe([])
        ->and($c->emptyKeys)->toBe([]);
});
```

### tests/Feature/KernelTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Kernel;
use Fabriciope\EnvChecker\Support\PathResolver;

function fixtureArgs(string $dir, string ...$extra): array
{
    return ['--base='.fixture("{$dir}/.env"), '--against='.fixture("{$dir}/.env.example"), ...$extra];
}

/** Base e exemplo que produzem todos os tipos de resultado de uma vez. */
const FULL_BASE = "B=\nD=1\nD=2\nE=1\n";
const FULL_AGAINST = "A=\nB=\nD=\nF=\nF=\n";

describe('exit codes', function () {
    it('returns 0 when every required key is set', function () {
        [$code, $out, $err] = checkContents("A=1\n", "A=\n");

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($out)->toBe('All required keys are set'.PHP_EOL)                     // [T2.3]
            ->and($err)->toBe('');
    });

    it('returns 0 for the ok fixture', function () {
        expect(runEnvChecker(fixtureArgs('ok'))[0])->toBe(Kernel::SUCCESS);
    });

    it('returns 1 for the with_differences fixture', function () {
        expect(runEnvChecker(fixtureArgs('with_differences'))[0])->toBe(Kernel::DIFFERENCES_ERROR);
    });

    it('returns 1 for each kind of error', function (string $base, string $against) {
        expect(checkContents($base, $against)[0])->toBe(Kernel::DIFFERENCES_ERROR);
    })->with([
        'missing key' => ["A=1\n", "A=\nB=\n"],
        'empty key' => ["A=\n", "A=x\n"],                                               // [T2.3]
        'empty key with inline comment' => ["A= # fill me\n", "A=\n"],                  // [T2.1]
        'duplicate in base' => ["A=1\nA=2\n", "A=\n"],                                  // [T2.3]
        'duplicate in against' => ["A=1\n", "A=\nA=\n"],                                // [T2.3]
    ]);

    it('returns 0 when there are only extra keys, and warns about them', function () {  // [T2.3]
        [$code, $out] = checkContents("A=1\nEXTRA=1\n", "A=\n");

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($out)->toBe('1 extra keys: EXTRA'.PHP_EOL.'All required keys are set'.PHP_EOL);
    });

    it('returns 0 when optional keys are missing or empty', function () {              // [T2.4]
        [$code, $out] = checkContents("A=1\nMAIL_BCC=\n", "A=\nSENTRY_DSN= # @optional\nMAIL_BCC= # @optional\n");

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($out)->toBe('All required keys are set'.PHP_EOL);
    });

    it('returns 64 for an invalid argument, before reading any file', function () {
        [$code, $out, $err] = runEnvChecker(['-x'], new PathResolver('/nonexistent', '/nonexistent'));

        expect($code)->toBe(Kernel::USAGE_ERROR)
            ->and($out)->toBe('')
            ->and($err)->toBe('envchecker: Invalid argument passed to the command: -x'.PHP_EOL);
    });

    it('returns 64 for an unknown format', function () {
        [$code, , $err] = runEnvChecker(fixtureArgs('ok', '--format=wrong-format'));

        expect($code)->toBe(Kernel::USAGE_ERROR)
            ->and($err)->toContain('Invalid argument passed to the command: --format');
    });

    it('returns 64 for a repeated option', function () {                              // [T3.1]
        expect(runEnvChecker([...fixtureArgs('ok'), '--base=x'])[0])->toBe(Kernel::USAGE_ERROR);
    });

    it('returns 64 for --against=@env', function () {                                  // [T2.5]
        expect(runEnvChecker(['--against=@env'])[0])->toBe(Kernel::USAGE_ERROR);
    });

    it('returns 66 when a file does not exist', function (string $flag) {
        $missing = fixture('with_differences/.wrong-env');
        $args = 'base' === $flag
            ? ["--base={$missing}", '--against='.fixture('ok/.env.example')]
            : ['--base='.fixture('ok/.env'), "--against={$missing}"];

        [$code, $out, $err] = runEnvChecker($args);

        expect($code)->toBe(Kernel::NOINPUT_ERROR)
            ->and($out)->toBe('')
            ->and($err)->toContain('.wrong-env');
    })->with(['base', 'against']);

    it('returns 66 when the path is a directory', function () {
        expect(runEnvChecker(['--base='.fixture('ok'), '--against='.fixture('ok/.env.example')])[0])
            ->toBe(Kernel::NOINPUT_ERROR);
    });

    it('returns 65 when a file has invalid variable names', function () {             // [T1.3]
        [$code, $out, $err] = checkContents("OK=1\nMY KEY=1\n", "OK=\n");

        expect($code)->toBe(Kernel::DATA_ERROR)
            ->and($out)->toBe('')
            ->and($err)->toContain('line 2 (MY KEY)');
    });

    it('returns 65 when a file is too large', function () {                            // [T1.6]
        [$code, , $err] = checkContents(str_repeat('#', 1_048_577), "A=\n");

        expect($code)->toBe(Kernel::DATA_ERROR)
            ->and($err)->toContain('larger than');
    });

    it('returns 70 on an unexpected error', function () {                              // [T0.7]
        $paths = new readonly class('/', '/') extends PathResolver {
            public function fromProjectRoot(string $path): string
            {
                throw new RuntimeException('boom');
            }
        };

        [$code, $out, $err] = runEnvChecker([], $paths);

        expect($code)->toBe(Kernel::SOFTWARE_ERROR)
            ->and($out)->toBe('')
            ->and($err)->toBe('envchecker: Unexpected internal error: boom'.PHP_EOL);
    });
});

describe('missing .env hint', function () {                                            // [T2.7]
    it('suggests --base=@env when the base file is missing', function () {
        [$code, , $err] = runEnvChecker([], new PathResolver('/nonexistent', '/nonexistent'));

        expect($code)->toBe(Kernel::NOINPUT_ERROR)
            ->and($err)->toContain('--base=@env');
    });

    it('does not suggest it when only the example file is missing', function () {
        [$code, , $err] = runEnvChecker(['--base='.tempEnv("A=1\n"), '--against=/nonexistent/.env.example']);

        expect($code)->toBe(Kernel::NOINPUT_ERROR)
            ->and($err)->not->toContain('@env');
    });
});

describe('text report', function () {
    it('prints every section in a fixed order', function () {                          // [T2.3]
        [$code, $out] = checkContents(FULL_BASE, FULL_AGAINST);

        expect($code)->toBe(Kernel::DIFFERENCES_ERROR)
            ->and($out)->toBe(implode(PHP_EOL, [
                '2 missing keys: A, F',
                '1 empty keys: B',
                '1 duplicate keys in base: D',
                '1 duplicate keys in against: F',
                '1 extra keys: E',
            ]).PHP_EOL);
    });

    it('omits empty sections', function () {
        expect(checkContents("A=1\n", "A=\nB=\n")[1])->toBe('1 missing keys: B'.PHP_EOL);
    });

    it('reports the with_differences fixture', function () {
        [, $out] = runEnvChecker(fixtureArgs('with_differences'));

        expect($out)->toContain('1 missing keys: JOB')
            ->and($out)->toContain('1 empty keys: AGE')
            ->and($out)->toContain('2 extra keys: GENDER, HEIGHT')
            ->and($out)->not->toContain('All required keys are set');
    });

    it('is the default format', function () {
        expect(checkContents(FULL_BASE, FULL_AGAINST)[1])
            ->toBe(checkContents(FULL_BASE, FULL_AGAINST, '--format=text')[1]);
    });

    it('prints no color codes when output is not a terminal', function () {
        expect(checkContents(FULL_BASE, FULL_AGAINST)[1])->not->toContain("\033");
    });
});

describe('json report', function () {
    it('prints a single JSON line with every field', function () {                     // [T2.3]
        [$code, $out, $err] = checkContents(FULL_BASE, FULL_AGAINST, '--format=json');

        expect($code)->toBe(Kernel::DIFFERENCES_ERROR)
            ->and($err)->toBe('')
            ->and($out)->toEndWith(PHP_EOL)
            ->and(substr_count($out, "\n"))->toBe(1)
            ->and(json_decode($out, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'has_differences' => true,
                'missing_keys' => ['A', 'F'],
                'empty_keys' => ['B'],
                'extra_keys' => ['E'],
                'duplicate_keys' => ['base' => ['D'], 'against' => ['F']],
                'warnings' => [],
            ]);
    });

    it('uses empty arrays, not objects or nulls, when there is nothing to report', function () {
        [, $out] = checkContents("A=1\n", "A=\n", '--format=json');

        expect($out)->toContain('"missing_keys":[]')
            ->and($out)->toContain('"empty_keys":[]')
            ->and($out)->toContain('"extra_keys":[]')
            ->and($out)->toContain('"warnings":[]')
            ->and($out)->not->toContain('All required');
    });

    it('keeps has_differences in line with the exit code', function (string $base, string $against, bool $expected) {
        [$code, $out] = checkContents($base, $against, '--format=json');

        expect(json_decode($out, true)['has_differences'])->toBe($expected)
            ->and($code)->toBe($expected ? Kernel::DIFFERENCES_ERROR : Kernel::SUCCESS);
    })->with([
        'clean' => ["A=1\n", "A=\n", false],
        'only extra keys' => ["A=1\nB=1\n", "A=\n", false],                              // [T2.3]
        'empty key' => ["A=\n", "A=x\n", true],                                         // [T2.3]
    ]);

    it('reports keys as strings', function () {                                        // [T1.3]
        [, $out] = checkContents("A=1\n", "A=\nB=\n", '--format=json');

        expect(json_decode($out, true)['missing_keys'])->each->toBeString();
    });

    it('prints errors as plain text on stderr, not JSON', function () {
        [$code, $out, $err] = runEnvChecker(['--base=/nope', '--format=json']);

        expect($code)->toBe(Kernel::NOINPUT_ERROR)
            ->and($out)->toBe('')
            ->and($err)->toStartWith('envchecker: ');
    });
});

describe('--base=@env', function () {                                                  // [T2.5]
    it('passes when every required key is in the process environment', function () {
        [$code, $out] = checkEnvironment(['A' => '1', 'PATH' => '/usr/bin', 'HOME' => '/root'], "A=\n");

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($out)->toBe('All required keys are set'.PHP_EOL);
    });

    it('never reports unrelated process variables, not even in strict mode', function () {
        [$code, $out] = checkEnvironment(['A' => '1', 'PATH' => '/usr/bin'], "A=\n", '--strict', '--format=json');

        expect($code)->toBe(Kernel::SUCCESS)
            ->and(json_decode($out, true)['extra_keys'])->toBe([])
            ->and($out)->not->toContain('PATH');
    });

    it('fails on missing and empty variables', function (array $env, string $expected) {
        [$code, $out] = checkEnvironment($env, "A=\nB=\n");

        expect($code)->toBe(Kernel::DIFFERENCES_ERROR)
            ->and($out)->toContain($expected);
    })->with([
        'missing' => [['A' => '1'], '1 missing keys: B'],
        'empty' => [['A' => '1', 'B' => ''], '1 empty keys: B'],
        'whitespace only' => [['A' => '1', 'B' => '   '], '1 empty keys: B'],
    ]);

    it('honors optional keys', function () {
        expect(checkEnvironment(['A' => '1'], "A=\nSENTRY_DSN= # @optional\n")[0])->toBe(Kernel::SUCCESS);
    });

    it('ignores process variables with invalid names', function () {
        expect(checkEnvironment(['A' => '1', 'ProgramFiles(x86)' => 'x', '=C:' => 'C:\\'], "A=\n")[0])
            ->toBe(Kernel::SUCCESS);
    });

    it('still validates the example file', function () {
        expect(checkEnvironment(['A' => '1'], "A=\nMY KEY=\n")[0])->toBe(Kernel::DATA_ERROR);
    });

    it('reads the real process environment when none is injected', function () {
        putenv('ENVCHECKER_TEST_VAR=1');

        try {
            [$code] = runEnvChecker(['--base=@env', '--against='.tempEnv("ENVCHECKER_TEST_VAR=\n")]);
        } finally {
            putenv('ENVCHECKER_TEST_VAR');
        }

        expect($code)->toBe(Kernel::SUCCESS);
    });

    it('does not check file permissions', function () {
        expect(json_decode(checkEnvironment(['A' => '1'], "A=\n", '--format=json')[1], true)['warnings'])->toBe([]);
    });
});

describe('file permissions', function () {                                             // [T2.6]
    it('warns when the base file is accessible by other users, without failing', function () {
        $base = tempEnv("A=1\n");
        chmod($base, 0o644);

        [$code, $out] = runEnvChecker(["--base={$base}", '--against='.tempEnv("A=\n")]);

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($out)->toBe(
                "warning: {$base} is accessible by other users (mode 0644); consider chmod 600".PHP_EOL
                .'All required keys are set'.PHP_EOL
            );
    })->skip(skipOnWindows(), 'POSIX permissions only');

    it('does not warn for a 0600 file', function () {
        expect(checkContents("A=1\n", "A=\n")[1])->not->toContain('warning');
    });

    it('does not check the example file', function () {
        $against = tempEnv("A=\n");
        chmod($against, 0o644);

        expect(runEnvChecker(['--base='.tempEnv("A=1\n"), "--against={$against}"])[1])->not->toContain('warning');
    })->skip(skipOnWindows(), 'POSIX permissions only');

    it('lists the warning in the JSON output', function () {
        $base = tempEnv("A=1\n");
        chmod($base, 0o640 | 0o004);

        [, $out] = runEnvChecker(["--base={$base}", '--against='.tempEnv("A=\n"), '--format=json']);

        expect(json_decode($out, true)['warnings'])->toHaveCount(1)
            ->and(json_decode($out, true)['warnings'][0])->toContain('mode 0644');
    })->skip(skipOnWindows(), 'POSIX permissions only');

    it('does not fail even in strict mode', function () {                              // [T5.4]
        $base = tempEnv("A=1\n");
        chmod($base, 0o644);

        expect(runEnvChecker(["--base={$base}", '--against='.tempEnv("A=\n"), '--strict'])[0])
            ->toBe(Kernel::SUCCESS);
    })->skip(skipOnWindows(), 'POSIX permissions only');
});

describe('path resolution', function () {                                              // [T0.7]
    it('uses .env and .env.example from the project root by default', function () {
        $paths = new PathResolver(cwd: '/nonexistent', projectRoot: fixture('ok'));

        expect(runEnvChecker([], $paths)[0])->toBe(Kernel::SUCCESS);
    });

    it('resolves flag paths relative to the current directory', function () {
        $paths = new PathResolver(cwd: fixture('with_differences'), projectRoot: '/nonexistent');

        expect(runEnvChecker(['--base=.env', '--against=.env.example'], $paths)[0])
            ->toBe(Kernel::DIFFERENCES_ERROR);
    });

    it('mixes a flag path with a default path', function () {
        $paths = new PathResolver(cwd: fixture('with_differences'), projectRoot: fixture('ok'));

        // base = with_differences/.env (cwd); against = ok/.env.example (raiz)
        expect(runEnvChecker(['--base=.env'], $paths)[0])->toBe(Kernel::DIFFERENCES_ERROR);
    });
});

describe('encodings', function () {
    it('accepts CRLF files', function () {
        expect(runEnvChecker(fixtureArgs('crlf'))[0])->toBe(Kernel::SUCCESS);
    });

    it('accepts files with a BOM', function () {                                       // [T1.5]
        expect(runEnvChecker(fixtureArgs('bom'))[0])->toBe(Kernel::SUCCESS);
    });

    it('accepts UTF-8 values containing the 0x85 byte', function () {                  // [T1.2]
        expect(checkContents("URL=https://x?q=\u{C5}&b=1\n", "URL=\n")[0])->toBe(Kernel::SUCCESS);
    });
});

describe('--help', function () {                                                       // [T5.2]
    it('prints the usage and exits 0 without reading files', function () {
        [$code, $out, $err] = runEnvChecker(['--help'], new PathResolver('/nonexistent', '/nonexistent'));

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($err)->toBe('');

        foreach (['Usage:', '--base=<file>', '--base=@env', '--against=<file>', '--format=<format>', '--strict', '--version', '@optional'] as $text) {
            expect($out)->toContain($text);
        }
    });

    it('still validates the other arguments first', function () {
        expect(runEnvChecker(['--help', '--format=xml'])[0])->toBe(Kernel::USAGE_ERROR);
    });

    it('wins over --version', function () {
        expect(runEnvChecker(['--version', '--help'])[1])->toContain('Usage:');
    });
});

describe('--version', function () {                                                    // [T5.3]
    it('prints the version and exits 0 without reading files', function () {
        [$code, $out, $err] = runEnvChecker(['--version'], new PathResolver('/nonexistent', '/nonexistent'));

        expect($code)->toBe(Kernel::SUCCESS)
            ->and($err)->toBe('')
            ->and($out)->toMatch('/^envchecker \S+\R\z/');
    });
});

describe('--strict', function () {                                                     // [T5.4]
    it('fails on extra keys', function () {
        expect(checkContents("A=1\nEXTRA=1\n", "A=\n")[0])->toBe(Kernel::SUCCESS)
            ->and(checkContents("A=1\nEXTRA=1\n", "A=\n", '--strict')[0])->toBe(Kernel::DIFFERENCES_ERROR);
    });

    it('passes when everything is clean', function () {
        expect(checkContents("A=1\n", "A=\n", '--strict')[0])->toBe(Kernel::SUCCESS);
    });

    it('does not print the success line when it fails', function () {
        expect(checkContents("A=1\nEXTRA=1\n", "A=\n", '--strict')[1])->not->toContain('All required');
    });

    it('reports has_differences = true in JSON when extra keys fail', function () {
        [, $out] = checkContents("A=1\nEXTRA=1\n", "A=\n", '--strict', '--format=json');

        expect(json_decode($out, true)['has_differences'])->toBeTrue();
    });
});
```

### tests/Feature/SecurityTest.php

```php
<?php

declare(strict_types=1);

use Fabriciope\EnvChecker\Console\Kernel;
use Fabriciope\EnvChecker\Support\PathResolver;

const SECRET = 'sup3r-s3cret-v4lue';

it('never prints values from files, in any format or scenario', function (string $base, string $against, array $extra) {
    [, $out, $err] = checkContents($base, $against, ...$extra);

    expect($out)->not->toContain(SECRET)
        ->and($err)->not->toContain(SECRET);
})->with([
    'missing key, text' => ['TOKEN='.SECRET."\n", "TOKEN=\nOTHER=\n", []],
    'extra key, text' => ['TOKEN='.SECRET."\n", "\n", []],
    'missing key, json' => ['TOKEN='.SECRET."\n", "TOKEN=\nOTHER=\n", ['--format=json']],
    'extra key, json' => ['TOKEN='.SECRET."\n", "\n", ['--format=json']],
    'strict' => ['TOKEN='.SECRET."\nX=".SECRET."\n", "TOKEN=\n", ['--strict']],
    'duplicate key' => ['TOKEN='.SECRET."\nTOKEN=".SECRET."\n", "TOKEN=\n", []],                  // [T2.3]
    'invalid key next to a secret' => ['TOKEN='.SECRET."\nMY KEY=".SECRET."\n", "TOKEN=\n", []],  // [T1.3]
    'secret in the example file' => ["TOKEN=x\n", 'TOKEN='.SECRET."\nB=".SECRET."\n", []],
    'secret next to an optional annotation' => ['A='.SECRET."\n", 'A= # @optional '.SECRET."\n", []], // [T2.4]
]);

it('never prints values from the process environment', function (array $extra) {     // [T2.5]
    [, $out, $err] = checkEnvironment(
        ['TOKEN' => SECRET, 'UNRELATED' => SECRET, 'EMPTY' => ''],
        "TOKEN=\nEMPTY=\nMISSING=\n",
        ...$extra,
    );

    expect($out.$err)->not->toContain(SECRET);
})->with([
    'text' => [[]],
    'json' => [['--format=json']],
    'strict' => [['--strict']],
]);

it('does not let a malicious key reach the terminal', function () {                  // [T1.3/T1.4]
    [$code, $out, $err] = checkContents("A=1\n", "A=\nX\x1B[2J\x1B]0;pwned\x07=1\n");

    expect($code)->toBe(Kernel::DATA_ERROR)
        ->and($out.$err)->not->toContain("\x1B")
        ->and($out.$err)->not->toContain("\x07")
        ->and($err)->toContain('\x1B[2J');
});

it('does not let a malicious path reach the terminal', function () {                 // [T1.4]
    [$code, , $err] = runEnvChecker(["--base=/nope/\x1B[2J.env"]);

    expect($code)->toBe(Kernel::NOINPUT_ERROR)
        ->and($err)->not->toContain("\x1B")
        ->and($err)->toContain('\x1B[2J');
});

it('does not let a malicious internal error message reach the terminal', function () { // [T0.7/T1.4]
    $paths = new readonly class('/', '/') extends PathResolver {
        public function fromProjectRoot(string $path): string
        {
            throw new RuntimeException("boom\x1B[2J");
        }
    };

    [, , $err] = runEnvChecker([], $paths);

    expect($err)->not->toContain("\x1B");
});

it('does not let a malicious path reach the permission warning', function () {       // [T1.4/T2.6]
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR."envchecker_\x1B[2J_".bin2hex(random_bytes(4));
    mkdir($dir);
    $base = $dir.DIRECTORY_SEPARATOR.'.env';
    file_put_contents($base, "A=1\n");
    chmod($base, 0o644);

    try {
        [, $out] = runEnvChecker(["--base={$base}", '--against='.tempEnv("A=\n")]);
    } finally {
        unlink($base);
        rmdir($dir);
    }

    expect($out)->toContain('warning:')
        ->and($out)->not->toContain("\x1B");
})->skip(skipOnWindows(), 'POSIX permissions only');
```

---

## Matriz de rastreabilidade

Cada achado → tarefa → testes que o cobrem.

| # | Achado | Tipo | Tarefa | Testes |
|---|---|---|---|---|
| 1 | Injeção de sequências ANSI/OSC pelo nome da chave | 🔴 Segurança | T1.3, T1.4 | `SecurityTest`; `EnvParserTest` › key validation; `OutputTest` › sanitize |
| 2 | Caminhos e mensagens de exceção crus no stderr/logs | 🔴 Segurança | T1.4 | `SecurityTest` › malicious path / internal error / permission warning |
| 3 | Valores nunca impressos (garantia a preservar, inclusive em `@env`) | 🔴 Segurança | D8 | `SecurityTest` › never prints values |
| 4 | Arquivo inteiro lido na memória, sem limite | 🟠 Robustez | T1.6 | `EnvParserTest` › size limit; `KernelTest` › 65 too large |
| 5 | BOM UTF-8 → falsas diferenças (deploy bloqueado) | 🔴 Bug | T1.5 | `EnvParserTest` › BOM; `CompareParsedEnvTest`; `KernelTest` › encodings |
| 6 | Chaves numéricas viram `int` / JSON numérico | 🔴 Bug | T1.3, T2.3 | `EnvParserTest` › integer keys; `ComparatorTest` › strings; `KernelTest` › json strings |
| 7 | `\R` quebra linha no byte `0x85` de UTF-8 | 🔴 Bug | T1.2 | `EnvParserTest` › lines; `KernelTest` › encodings |
| 8 | Comentário no fim da linha esconde chave vazia (README errado) | 🟠 Bug | T2.1 | `EnvParserTest` › values; `KernelTest` › empty key with inline comment |
| 9 | Aspas desbalanceadas removidas | 🟠 Bug | T2.1 | `EnvParserTest` › values |
| 10 | `export` + tab ou vários espaços | 🟠 Bug | T2.2 | `EnvParserTest` › values (export) |
| 11 | Critérios invertidos para produção (sobrando falha, vazia passa) | 🔴 Produto | T2.3 | `KernelTest` › exit codes; `ComparatorTest` |
| 12 | Duplicadas não detectadas | 🟢 Produto | T1.3, T2.3 | `EnvParserTest` › duplicates; `KernelTest` › text/json |
| 13 | Sem forma de marcar chaves opcionais | 🟢 Produto | T2.4 | `EnvParserTest` › optional; `ComparatorTest` › optional; `KernelTest` |
| 14 | Não valida variáveis do processo (containers/PaaS) | 🟢 Produto | T2.5 | `EnvParserTest` › fromEnvironment; `ComparatorTest` › ignoring extra; `KernelTest` › `@env` |
| 15 | `.env` de produção legível por outros usuários | 🟢 Segurança | T2.6 | `FilePermissionsTest`; `KernelTest` › file permissions |
| 16 | Erro de `.env` ausente sem orientação | 🟢 UX | T2.7 | `KernelTest` › missing .env hint |
| 17 | `TypeError` com `null` no `Comparator` | 🟠 Bug | T2.3 | `ComparatorTest` › null and whitespace |
| 18 | API assimétrica do `Comparator` / TODO do DTO | 🟡 Qualidade | T1.1, T2.3 | `ComparatorTest` |
| 19 | Opção repetida aceita em silêncio | 🟠 Bug | T3.1 | `InputTest`; `KernelTest` › 64 repeated |
| 20 | Cor do stderr decidida pelo stdout (ANSI nos logs) | 🟠 Bug | T3.2 | `OutputTest` › independent colors |
| 21 | Raiz errada na instalação global | 🟠 Bug | T3.3 | `PathResolverTest` › chooseProjectRoot |
| 22 | `Kernel` não testável (paths, ambiente, exit 70) | 🟡 Testabilidade | T0.7 | `KernelTest` › path resolution, 70 |
| 23 | Exceções não `final`, sem tipo comum | 🟡 Qualidade | T4.1 | `ArchTest`; `ExceptionsTest` |
| 24 | Sem `--help` / `--version` | 🟢 Feature | T5.1 – T5.3 | `KernelTest`; `InputTest` › boolean flags |
| 25 | Sem modo rigoroso para CI | 🟢 Feature | T5.4 | `KernelTest` › `--strict`; `ComparatorTest` |
| 26 | Anotações no `bin/envchecker` | 🟡 Higiene | T0.1 | verificação manual |
| 27 | Comparação frouxa e tipos largos em `Input` | 🟡 Qualidade | T0.5, T6.2 | `InputTest` › `--base=0`; PHPStan |
| 28 | `is_file` + `file_exists` redundantes | 🟡 Qualidade | T0.6 | `EnvParserTest` › directory / missing |
| 29 | Lacunas, teste pelo motivo errado, typos, boilerplate | 🟡 Testes | T0.3 + suíte final | toda a suíte |
| 30 | `phpunit.xml` permissivo | 🔵 Infra | T0.4 | — |
| 31 | Sem CI, sem análise estática, sem cobertura mínima | 🔵 Infra | T6.1 – T6.4 | job `production-smoke` |
| 32 | Posicionamento como ferramenta de dev (`--dev`) | 🔵 Docs | T6.5 | — |

---

## Checklist final de release

- [ ] Todas as tarefas T0.1 – T6.6 marcadas
- [ ] `composer check` verde (lint + PHPStan + testes)
- [ ] `composer test:coverage` ≥ 95%
- [ ] `composer test:mutate` ≥ 85%
- [ ] CI verde em Linux, Windows e macOS × PHP 8.3 / 8.4 / 8.5 × deps lowest/highest, incluindo o job `production-smoke`
- [ ] Testado num projeto real instalado **sem `--dev`** (`composer install --no-dev`), com `.env` em `0600` e em `0644`
- [ ] Testado num container Docker com `--base=@env` no entrypoint
- [ ] Testado com `composer global require` fora de um projeto (T3.3)
- [ ] `git archive HEAD | tar -t` não contém `tests/`, `docs/` nem arquivos de configuração de desenvolvimento
- [ ] README e CHANGELOG revisados; `[Unreleased]` → `[0.1.0] - AAAA-MM-DD`
- [ ] Tag `v0.1.0` e release no Packagist
