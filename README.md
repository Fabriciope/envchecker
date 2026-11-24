# envchecker

Compara o `.env` com o `.env.example` e aponta as chaves **faltando**, **vazias** e **sobrando**. Instala como dependência de desenvolvimento e roda no seu projeto via `vendor/bin/envchecker`, no terminal ou no CI.

```console
$ vendor/bin/envchecker
1 missing keys: DB_PASSWORD
1 empty keys: DB_HOST
1 extra keys: LEGACY_TOKEN
$ echo $?
1
```

- Sem dependências em tempo de execução: só PHP e Composer.
- Exit codes pensados para CI.
- Saída em texto ou JSON (`--format=json`).

## Sumário

- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Uso](#uso)
- [O que é verificado](#o-que-é-verificado)
- [Como os caminhos são resolvidos](#como-os-caminhos-são-resolvidos)
- [Formato do `.env` suportado](#formato-do-env-suportado)
- [Exit codes](#exit-codes)
- [Saída](#saída)
- [Uso no CI](#uso-no-ci)
- [Como funciona](#como-funciona)
- [Desenvolvimento](#desenvolvimento)
- [Changelog](#changelog)
- [Licença](#licença)

## Requisitos

- PHP 8.3 ou superior
- Composer 2.2 ou superior

## Instalação

```bash
composer require --dev fabriciope/envchecker
```

O Composer cria o executável em `vendor/bin/envchecker`. Não é preciso configurar nada.

## Uso

Na raiz do projeto, sem argumentos, o comando compara `.env` com `.env.example`:

```bash
vendor/bin/envchecker
```

Para comparar outros arquivos, use as flags:

```bash
# um ambiente específico contra o exemplo
vendor/bin/envchecker --base=.env.testing

# dois arquivos quaisquer
vendor/bin/envchecker --base=config/.env.staging --against=config/.env.staging.example

# caminhos absolutos também funcionam
vendor/bin/envchecker --base=/etc/minha-app/.env
```

### Opções

| Opção | Padrão | Descrição |
|---|---|---|
| `--base=<arquivo>` | `.env` na raiz do projeto | O arquivo verificado. |
| `--against=<arquivo>` | `.env.example` na raiz do projeto | O arquivo de referência, com todas as chaves esperadas. |
| `--format=<text\|json>` | `text` | Formato da saída: texto para pessoas, JSON para scripts. Outro valor é recusado com exit code `64`. |

As opções só aceitam a forma longa, com o valor depois de `=` (`--base=.env.testing`). `--base .env.testing`, com espaço, não é reconhecido. Argumentos que não começam com `--` e flags desconhecidas (por exemplo, `--fromat=json`) são recusados com exit code `64`, antes de qualquer arquivo ser lido.

## O que é verificado

O `.env.example` é a referência: ele lista todas as chaves que a aplicação espera. O `.env` é comparado contra ele.

| Situação | Exemplo | Conta como diferença? |
|---|---|---|
| **Chave faltando**: existe no `.env.example`, não existe no `.env` | `DB_PASSWORD` só no exemplo | Sim |
| **Chave sobrando**: existe no `.env`, não existe no `.env.example` | `LEGACY_TOKEN` só no `.env` | Sim |
| **Chave vazia**: existe nos dois, mas está sem valor no `.env` | `DB_HOST=` ou `DB_HOST=""` | Não. É reportada, mas não muda o exit code |

Os valores do `.env.example` são ignorados: ele pode conter valores de exemplo ou ficar vazio. A ordem das chaves nos arquivos também não importa.

## Como os caminhos são resolvidos

- **Arquivos padrão** (`.env` e `.env.example`) são procurados na **raiz do projeto**, que o envchecker descobre pelo Composer. O comando funciona igual se for executado de uma subpasta:

  ```bash
  cd app/Models
  ../../vendor/bin/envchecker   # usa o .env da raiz do projeto
  ```

- **Caminhos passados em `--base` e `--against`** são relativos ao **diretório atual**, como em qualquer ferramenta de terminal. Caminhos absolutos (`/…` ou `C:\…`) são usados como estão.

## Formato do `.env` suportado

```dotenv
# Comentários de linha inteira são ignorados
APP_NAME=MinhaApp

# Aspas simples ou duplas em volta do valor são removidas
APP_URL="https://exemplo.com"

# O prefixo "export" é aceito
export DB_HOST=localhost

# As duas chaves abaixo são consideradas vazias
DB_PASSWORD=
MAIL_FROM=""
```

- Linhas em branco e linhas sem `=` são ignoradas.
- Quebras de linha `\n` (Linux/macOS), `\r\n` (Windows) e `\r` são aceitas.
- Espaços em volta da chave e do valor são removidos.

Limitações conhecidas:

- Valores que ocupam várias linhas não são suportados.
- Comentários no fim da linha (`CHAVE=valor # comentário`) passam a fazer parte do valor. Isso não afeta a verificação, que só olha se o valor está vazio.
- Variáveis interpoladas (`${OUTRA}`) não são expandidas.

## Exit codes

| Código | Significado |
|---|---|
| `0` | Nenhuma chave faltando ou sobrando. Chaves vazias podem ter sido reportadas. |
| `1` | Há chaves faltando ou sobrando. |
| `64` | Uso incorreto: argumento inválido, flag desconhecida, flag sem valor (por exemplo, `--base` sem `=`) ou `--format` com valor desconhecido. |
| `66` | Um dos arquivos não existe ou não pode ser lido. |
| `70` | Erro interno inesperado. |

Os códigos `64`, `66` e `70` seguem a convenção do `sysexits.h`.

## Saída

- **O relatório vai para o STDOUT**, e mensagens de erro vão para o STDERR. Assim, `vendor/bin/envchecker > relatorio.txt` grava só o relatório, e os erros continuam aparecendo no terminal.
- **Cores só em terminal interativo.** Com a saída redirecionada para arquivo, pipe ou CI, o texto sai sem códigos de cor. Para desligar as cores mesmo no terminal, defina `NO_COLOR` ([no-color.org](https://no-color.org)):

  ```bash
  NO_COLOR=1 vendor/bin/envchecker
  ```

### JSON

```bash
vendor/bin/envchecker --format=json
```

```json
{"has_differences":true,"missing_keys":["DB_PASSWORD"],"empty_keys":["DB_HOST"],"extra_keys":["LEGACY_TOKEN"]}
```

| Campo | Tipo | Descrição |
|---|---|---|
| `has_differences` | boolean | `true` se há chaves faltando ou sobrando; o mesmo critério do exit code `1`. |
| `missing_keys` | lista de strings | Chaves do `.env.example` ausentes no `.env`. |
| `empty_keys` | lista de strings | Chaves presentes nos dois, mas vazias no `.env`. |
| `extra_keys` | lista de strings | Chaves do `.env` ausentes no `.env.example`. |

O exit code é o mesmo nos dois formatos. Exemplo com [jq](https://jqlang.org):

```bash
vendor/bin/envchecker --format=json | jq -r '.missing_keys[]'
```

## Uso no CI

O exit code `1` faz o job falhar quando o `.env` do ambiente não tem alguma chave do `.env.example`.

**Como script do Composer**, para rodar com `composer check-env`:

```json
{
    "scripts": {
        "check-env": "envchecker"
    }
}
```

**No GitHub Actions**, conferindo se o `.env.example` está em dia com o `.env.testing` usado nos testes:

```yaml
- name: Verifica variáveis de ambiente
  run: vendor/bin/envchecker --base=.env.testing
```

## Como funciona

```text
vendor/bin/envchecker        proxy gerado pelo Composer
        │
        ▼
bin/envchecker               carrega o autoload do projeto e monta os objetos
        │
        ▼
Console\Kernel               lê as flags, chama o domínio, imprime, devolve o exit code
   ├── Console\Input         interpreta --base, --against, --format
   ├── Support\PathResolver  resolve caminhos (diretório atual ou raiz do projeto)
   ├── Env\Parser            texto do .env → array de chaves e valores
   ├── Env\Comparator        dois arrays → chaves faltando, vazias e sobrando
   └── Console\Output        relatório no STDOUT, erros no STDERR
```

- `Env\` contém as regras do problema e não depende do terminal. `Console\` faz a ponte entre o terminal e essas regras. Um teste de arquitetura garante essa separação.
- Exceções são convertidas em exit codes num único lugar, o `Kernel`.
- A raiz do projeto vem de `Composer\InstalledVersions`, disponível no autoload de todo projeto com Composer 2.

## Desenvolvimento

```bash
git clone https://github.com/Fabriciope/envchecker.git
cd envchecker
composer install
composer test
```

Para rodar o comando a partir do próprio repositório, aponte as flags para uma das fixtures. Sem flags, os arquivos padrão seriam procurados na raiz do repositório, que não tem `.env`.

```bash
cd tests/Fixtures/with_differences
../../../bin/envchecker --base=.env --against=.env.example
```

### Estrutura

```text
bin/envchecker         executável
src/
├── Console/           Kernel, Input, Output
├── Env/               Parser, Comparator
├── Support/           PathResolver
└── Exceptions/
tests/
├── ArchTest.php       regras de arquitetura
├── Feature/           o comando completo e a integração Parser + Comparator
├── Unit/              Parser, Comparator, Input
└── Fixtures/          um cenário por pasta (.env + .env.example)
```

### Testando como dependência de outro projeto

Para ver o comando funcionando dentro de outro projeto antes de publicar uma versão, use um repositório do tipo `path`:

```bash
mkdir ../playground && cd ../playground
composer init --no-interaction --name=teste/playground
composer config repositories.envchecker path ../envchecker
composer require --dev "fabriciope/envchecker:@dev"
vendor/bin/envchecker
```

As alterações no repositório do envchecker aparecem no playground na hora, via symlink.

## Changelog

As mudanças de cada versão estão em [CHANGELOG.md](CHANGELOG.md).

## Licença

Distribuído sob a licença MIT. Veja [LICENSE](LICENSE).
