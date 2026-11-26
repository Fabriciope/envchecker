# Changelog

Todas as mudanças relevantes deste projeto são registradas aqui.

O formato segue o [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e o projeto adota o [Versionamento Semântico](https://semver.org/lang/pt-BR/).

## [Unreleased]

## [0.1.0] - 2026-11-25

### Added

- Comando `envchecker`, instalado em `vendor/bin`, que compara o `.env` com o `.env.example` e aponta chaves faltando, vazias e sobrando.
- Flags `--base` e `--against` para escolher os arquivos comparados. Caminhos informados nas flags são relativos ao diretório atual (ou absolutos).
- Arquivos padrão (`.env` e `.env.example`) procurados na raiz do projeto, mesmo quando o comando é executado de uma subpasta.
- Flag `--format=text|json` para escolher o formato da saída; valores desconhecidos são recusados com exit code `64`.
- Flags desconhecidas e argumentos fora do formato `--nome=valor` são recusados com exit code `64`, antes da leitura dos arquivos.
- Exit codes: `0` sem diferenças, `1` com chaves faltando ou sobrando, `64` uso incorreto, `66` arquivo não encontrado, `70` erro interno.
- Relatório no STDOUT e erros no STDERR; cores apenas em terminal interativo, respeitando `NO_COLOR`.
- Parser de `.env` com suporte a comentários, prefixo `export`, aspas e quebras de linha `\n`, `\r\n` e `\r`.

[Unreleased]: https://github.com/Fabriciope/envchecker/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/Fabriciope/envchecker/releases/tag/v0.1.0
