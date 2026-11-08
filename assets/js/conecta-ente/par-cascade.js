(function (angular, $) {
    'use strict';

    // exercício → meta → ação → atividade: cada nível lista os filhos do nível anterior
    var LEVELS = [
        {children: null},
        {children: 'metas'},
        {children: 'acoes'},
        {children: 'atividades'}
    ];

    function findNode(list, id) {
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id) === String(id)) {
                return list[i];
            }
        }

        return null;
    }

    function labelOf(index, node) {
        // o exercício se chama pelo ano; os demais pelo nome. Sem nome, o id, para o nó continuar identificável
        var name = index === 0 ? node.ano : node.nome;

        return name !== null && name !== undefined && String(name) !== '' ? String(name) : 'Id ' + node.id;
    }

    /**
     * Cascata do PAR na aba do ente. Lê a árvore do cache pelo endpoint do plugin e grava os quatro ids juntos:
     * uma seleção incompleta grava vazio nos quatro, então nunca fica um nível sem os de cima.
     * Abrir a aba não grava nada; os inputs ocultos começam com o que já estava guardado, para o "Salvar" da
     * página não apagá-lo.
     */
    var module = angular.module('conecta-ente.par', []);

    // o entity.app lê esta lista (a mesma referência) só na inicialização, então entrar aqui antes do bootstrap basta.
    // Feito no cliente porque o tema redefine `angularAppDependencies` no servidor, depois do que o plugin registra.
    if (window.MapasCulturais && MapasCulturais.angularAppDependencies && MapasCulturais.angularAppDependencies.indexOf('conecta-ente.par') < 0) {
        MapasCulturais.angularAppDependencies.push('conecta-ente.par');
    }

    module.directive('conectaEntePar', ['$http', function ($http) {
        return {
            restrict: 'E',
            scope: true,
            template: [
                '<div class="conectaente-par__state" ng-if="state !== \'ok\'">',
                '  <p>{{ message }}</p>',
                '  <button type="button" class="btn btn-default btn-small" ng-click="load()" ng-disabled="state === \'loading\'">{{ texts.update }}</button>',
                '</div>',
                '<p class="conectaente-par__simulated" ng-if="state === \'ok\' && simulated">{{ texts.simulated }}</p>',
                '<p class="conectaente-par__warning" ng-if="warning">{{ warning }}</p>',
                '<p class="conectaente-par__empty" ng-if="state === \'ok\' && !options[0].length">{{ texts.noOptions }}</p>',
                '<p ng-repeat="level in levels">',
                '  <span class="label">{{ level.label }} *:</span>',
                '  <select ng-model="sel[level.index]" ng-change="change(level.index)" ng-disabled="!canChoose(level.index)"',
                '          ng-options="o.id as o.label for o in options[level.index]">',
                '    <option value="">{{ texts.select }}</option>',
                '  </select>',
                '</p>'
            ].join('\n'),
            link: function ($scope, element, attrs) {
                var config = JSON.parse(attrs.config);
                var entity = MapasCulturais.entity.object;
                var opportunityId = entity.id;
                var keys = config.levels.map(function (level) {
                    return level.key;
                });
                var tree = [];

                $scope.texts = config.texts;
                $scope.levels = config.levels.map(function (level, index) {
                    return {index: index, label: level.label};
                });
                $scope.state = 'loading';
                $scope.message = config.texts.loading;
                $scope.simulated = false;
                $scope.warning = '';
                $scope.sel = ['', '', '', ''];
                $scope.options = [[], [], [], []];

                // um input oculto por campo, como o editable-multiselect: o "Salvar" da página envia cada um
                var hidden = keys.map(function (key, index) {
                    var $hidden = $('<input class="js-include-editable" type="hidden">').appendTo(element);

                    $hidden.editable({name: key, showbuttons: false});
                    $hidden.editable('setValue', storedValue(key));

                    return $hidden;
                });

                function storedValue(key) {
                    var value = entity[key];

                    return value === null || value === undefined ? '' : String(value).trim();
                }

                // filhos disponíveis no nível: a raiz, ou os filhos do nó escolhido acima
                function listFor(index) {
                    if (index === 0) {
                        return tree;
                    }

                    var parent = findNode(listFor(index - 1), $scope.sel[index - 1]);

                    return parent ? (parent[LEVELS[index].children] || []) : [];
                }

                function refreshOptions() {
                    for (var index = 0; index < LEVELS.length; index++) {
                        var list = listFor(index);

                        $scope.options[index] = list.map(function (node) {
                            return {id: String(node.id), label: labelOf(index, node)};
                        });
                    }
                }

                // grava os quatro só quando a cadeia está completa; senão, os quatro ficam vazios
                function persist() {
                    var complete = $scope.sel.every(function (id) {
                        return id !== '';
                    });

                    keys.forEach(function (key, index) {
                        var value = complete ? $scope.sel[index] : '';

                        entity[key] = value;
                        hidden[index].editable('setValue', value);
                    });
                }

                function applyStored() {
                    $scope.sel = ['', '', '', ''];
                    $scope.warning = '';

                    for (var index = 0; index < LEVELS.length; index++) {
                        var stored = storedValue(keys[index]);

                        if (stored === '') {
                            break;
                        }

                        if (!findNode(listFor(index), stored)) {
                            // o id guardado não está na árvore atual: a tela avisa, e a publicação recusa até refazer
                            $scope.warning = config.texts.staleValue.replace('%s', config.levels[index].label);
                            break;
                        }

                        $scope.sel[index] = stored;
                    }

                    refreshOptions();
                }

                function handle(data) {
                    if (data && data.available) {
                        tree = data.exercicios || [];
                        $scope.state = 'ok';
                        $scope.simulated = !!data.simulated;
                        $scope.message = '';
                        applyStored();

                        return;
                    }

                    tree = [];
                    $scope.state = (data && data.reason) || 'unavailable';
                    $scope.message = (data && data.message) || config.texts.unavailable;
                    $scope.simulated = false;
                    $scope.warning = '';
                    $scope.sel = ['', '', '', ''];
                    refreshOptions();
                }

                $scope.load = function () {
                    $scope.state = 'loading';
                    $scope.message = config.texts.loading;

                    $http.get(MapasCulturais.createUrl('conecta-ente', 'parInformation', [opportunityId])).then(
                        function (response) {
                            handle(response.data);
                        },
                        function (response) {
                            // 404 e 403 também trazem o motivo no corpo da resposta
                            handle(response.data);
                        }
                    );
                };

                $scope.canChoose = function (index) {
                    return $scope.state === 'ok' && (index === 0 || $scope.sel[index - 1] !== '');
                };

                $scope.change = function (index) {
                    for (var below = index + 1; below < LEVELS.length; below++) {
                        $scope.sel[below] = '';
                    }

                    $scope.warning = '';
                    refreshOptions();
                    persist();
                };

                $scope.load();
            }
        };
    }]);
})(angular, jQuery);
