(function (angular) {
    'use strict';

    var module = angular.module('conecta-ente.history', []);

    // o entity.app lê esta lista só na inicialização, então entrar aqui antes do bootstrap basta (como em par-cascade.js)
    if (window.MapasCulturais && MapasCulturais.angularAppDependencies && MapasCulturais.angularAppDependencies.indexOf('conecta-ente.history') < 0) {
        MapasCulturais.angularAppDependencies.push('conecta-ente.history');
    }

    function pretty(value) {
        return value === null || value === undefined ? '' : JSON.stringify(value, null, 2);
    }

    function format(template, values) {
        return values.reduce(function (text, value, index) {
            return text.replace('%' + (index + 1) + '$s', value).replace('%s', value);
        }, template);
    }

    /**
     * Corpo que não era JSON (ou foi cortado) chega no envelope {"raw": "...", "truncated": true}: mostra-se o
     * texto cru, sem as chaves do envelope.
     */
    function bodyOf(response) {
        if (response === null || response === undefined) {
            return {text: '', truncated: false};
        }

        if (typeof response === 'object' && typeof response.raw === 'string') {
            return {text: response.raw, truncated: !!response.truncated};
        }

        return {text: pretty(response), truncated: false};
    }

    // rejeita quando a cópia não aconteceu: o botão só pode dizer «Copiado» se o texto foi mesmo para a área de transferência
    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        return new Promise(function (resolve, reject) {
            var area = document.createElement('textarea');
            var copied = false;

            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();

            try {
                copied = document.execCommand('copy');
            } catch (e) {
                copied = false;
            }

            document.body.removeChild(area);
            copied ? resolve() : reject(new Error('copy failed'));
        });
    }

    module.directive('conectaEnteSendHistory', ['$http', '$timeout', function ($http, $timeout) {
        return {
            restrict: 'E',
            scope: true,
            template: [
                '<p class="conectaente-history__state" ng-if="state === \'loading\'">{{ texts.loading }}</p>',
                '<div class="conectaente-history__state" ng-if="state === \'failed\'">',
                '  <p>{{ message }}</p>',
                '  <button type="button" class="btn btn-default btn-small" ng-click="load(1)">{{ texts.retry }}</button>',
                '</div>',
                '<p class="conectaente-history__empty" ng-if="state === \'ok\' && !items.length">{{ texts.empty }}</p>',
                '<div class="conectaente-history__log" ng-repeat="log in items" ng-class="\'conectaente-history__log--\' + log.status">',
                '  <button type="button" class="conectaente-history__head" ng-click="log.open = !log.open" ng-attr-aria-expanded="{{ log.open ? \'true\' : \'false\' }}">',
                '    <strong class="conectaente-history__badge" ng-class="\'conectaente-history__badge--\' + log.status">{{ log.statusLabel }}</strong>',
                '    <span class="conectaente-history__uuid">{{ log.requestUuid }}</span>',
                '    <span class="conectaente-history__date">{{ log.when }}</span>',
                '    <span class="conectaente-history__user" ng-if="log.userId">{{ log.byUser }}</span>',
                '  </button>',
                '  <div class="conectaente-history__body" ng-if="log.open">',
                '    <p ng-if="!log.attempts.length">{{ texts.noAttempts }}</p>',
                '    <div class="conectaente-history__attempt" ng-repeat="attempt in log.attempts">',
                '      <button type="button" class="conectaente-history__attempt-head" ng-click="attempt.open = !attempt.open" ng-attr-aria-expanded="{{ attempt.open ? \'true\' : \'false\' }}">',
                '        <strong class="conectaente-history__badge" ng-class="\'conectaente-history__badge--\' + attempt.status">{{ attempt.statusLabel }}</strong>',
                '        <span>{{ attempt.title }}</span>',
                '      </button>',
                '      <dl class="conectaente-history__attempt-body" ng-if="attempt.open">',
                '        <dt>{{ texts.sentAt }}</dt><dd>{{ attempt.when }}</dd>',
                '        <dt>{{ texts.endpoint }}</dt><dd>{{ attempt.method }} {{ attempt.endpoint }}</dd>',
                '        <dt ng-if="attempt.parEditalId">{{ texts.parEditalId }}</dt><dd ng-if="attempt.parEditalId">{{ attempt.parEditalId }}</dd>',
                '        <dt ng-if="attempt.requestId">{{ texts.requestId }}</dt><dd ng-if="attempt.requestId">{{ attempt.requestId }}</dd>',
                '        <dt ng-if="attempt.errorMessage">{{ texts.error }}</dt><dd ng-if="attempt.errorMessage">{{ attempt.errorMessage }}</dd>',
                '      </dl>',
                '      <div ng-if="attempt.open">',
                '        <div class="conectaente-history__block" ng-if="attempt.payloadText">',
                '          <div class="conectaente-history__block-head"><strong>{{ texts.payload }}</strong>',
                '            <button type="button" class="btn btn-default btn-small" ng-click="copy(attempt.payloadText, attempt, \'payload\')">{{ attempt.copied === \'payload\' ? texts.copied : (attempt.copyFailed === \'payload\' ? texts.copyFailed : texts.copy) }}</button></div>',
                '          <pre>{{ attempt.payloadText }}</pre>',
                '        </div>',
                '        <div class="conectaente-history__block">',
                '          <div class="conectaente-history__block-head"><strong>{{ texts.response }}</strong>',
                '            <button type="button" class="btn btn-default btn-small" ng-if="attempt.responseText" ng-click="copy(attempt.responseText, attempt, \'response\')">{{ attempt.copied === \'response\' ? texts.copied : (attempt.copyFailed === \'response\' ? texts.copyFailed : texts.copy) }}</button></div>',
                '          <p ng-if="!attempt.headersText && !attempt.bodyText">{{ texts.noResponse }}</p>',
                '          <p class="conectaente-history__sub" ng-if="attempt.headersText">{{ texts.headers }}</p>',
                '          <pre ng-if="attempt.headersText">{{ attempt.headersText }}</pre>',
                '          <p class="conectaente-history__sub" ng-if="attempt.bodyText">{{ texts.body }}</p>',
                '          <pre ng-if="attempt.bodyText">{{ attempt.bodyText }}</pre>',
                '          <p class="conectaente-history__warning" ng-if="attempt.truncated">{{ texts.truncated }}</p>',
                '        </div>',
                '      </div>',
                '    </div>',
                '  </div>',
                '</div>',
                '<button type="button" class="btn btn-default" ng-if="state === \'ok\' && hasMore" ng-click="load(page + 1)" ng-disabled="loadingMore">{{ texts.loadMore }}</button>'
            ].join('\n'),
            link: function ($scope, element, attrs) {
                var config = JSON.parse(attrs.config);
                var opportunityId = MapasCulturais.entity.object.id;

                $scope.texts = config.texts;
                $scope.state = 'loading';
                $scope.message = '';
                $scope.items = [];
                $scope.page = 0;
                $scope.hasMore = false;
                $scope.loadingMore = false;

                function present(log) {
                    log.open = false;
                    log.when = new Date(log.createdAt).toLocaleString('pt-BR');
                    log.byUser = format(config.texts.byUser, [log.userId]);

                    log.attempts.forEach(function (attempt) {
                        var status = attempt.httpStatus ? ' · HTTP ' + attempt.httpStatus : '';
                        var duration = attempt.durationMs !== null && attempt.durationMs !== undefined ? ' · ' + attempt.durationMs + ' ms' : '';
                        var body = bodyOf(attempt.response);

                        attempt.open = false;
                        attempt.when = new Date(attempt.sentAt).toLocaleString('pt-BR');
                        attempt.title = format(config.texts.attempt, [attempt.attempt, attempt.maxAttempts]) + status + duration;
                        attempt.payloadText = pretty(attempt.payload);
                        attempt.headersText = attempt.responseHeaders
                            ? Object.keys(attempt.responseHeaders).map(function (name) {
                                return name + ': ' + attempt.responseHeaders[name];
                            }).join('\n')
                            : '';
                        attempt.bodyText = body.text;
                        attempt.truncated = body.truncated;
                        // "Copiar" leva cabeçalhos e corpo juntos, na ordem em que aparecem
                        attempt.responseText = [attempt.headersText, attempt.bodyText].filter(Boolean).join('\n\n');
                    });

                    return log;
                }

                $scope.load = function (page) {
                    var first = page === 1;

                    if (first) {
                        $scope.state = 'loading';
                    }

                    $scope.loadingMore = true;

                    $http.get(MapasCulturais.createUrl('conecta-ente', 'logs', [opportunityId]), {params: {page: page}}).then(
                        function (response) {
                            var data = response.data || {};

                            var current = first ? [] : $scope.items;
                            // com envios novos entre uma página e outra o deslocamento repete o último da anterior
                            var known = {};

                            current.forEach(function (log) {
                                known[log.id] = true;
                            });

                            $scope.items = current.concat((data.items || []).filter(function (log) {
                                return !known[log.id];
                            }).map(present));
                            $scope.page = data.page || page;
                            $scope.hasMore = !!data.hasMore;
                            $scope.state = 'ok';
                            $scope.loadingMore = false;
                        },
                        function (response) {
                            var detail = response.data && response.data.data && response.data.data.message;

                            $scope.state = 'failed';
                            $scope.message = detail || config.texts.failed;
                            $scope.loadingMore = false;
                        }
                    );
                };

                $scope.copy = function (text, attempt, what) {
                    function flash(field) {
                        $timeout(function () {
                            attempt.copied = null;
                            attempt.copyFailed = null;
                            attempt[field] = what;
                            $timeout(function () {
                                attempt[field] = null;
                            }, 1500);
                        });
                    }

                    copyText(text).then(function () {
                        flash('copied');
                    }, function () {
                        flash('copyFailed');
                    });
                };

                $scope.load(1);
            }
        };
    }]);
})(window.angular);
