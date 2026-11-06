#!/usr/bin/env bash
# Roda os testes do plugin em PHP 7.2 (o host não tem php). Argumentos vão ao phpunit.
#   tests/run.sh                          toda a suíte
#   tests/run.sh --filter testSegment     filtra por nome
#   tests/run.sh tests/src/VocabularyTest.php
# Bootstrap, suíte e variáveis de ambiente (modo dev) vêm de tests/phpunit.xml.
set -euo pipefail

PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROTECTED="$(cd "$PLUGIN/../../.." && pwd)"
PLUGIN_IN_CONTAINER="/protected/application/plugins/ConectaEnte"

exec docker run --rm \
    -v "$PROTECTED":/protected \
    -w "$PLUGIN_IN_CONTAINER" \
    php:7.2-cli \
    php /protected/vendor/bin/phpunit -c tests/phpunit.xml "$@"
