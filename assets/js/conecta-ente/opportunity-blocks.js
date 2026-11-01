(function ($) {
    'use strict';

    function el(tag, attrs, children) {
        var $node = $('<' + tag + '>');

        $.each(attrs || {}, function (name, value) {
            if (name === 'text') {
                $node.text(value);
            } else if (value !== null && value !== undefined && value !== false) {
                $node.attr(name, value === true ? name : value);
            }
        });

        $.each([].concat(children || []), function (_, child) {
            $node.append(child);
        });

        return $node;
    }

    function toNumber(value) {
        var number = parseFloat(String(value).replace(',', '.'));

        return isFinite(number) && number > 0 ? number : 0;
    }

    function toInt(value) {
        var number = Math.floor(Number(String(value).replace(',', '.')));

        return isFinite(number) && number > 0 ? number : 0;
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    /** O valor guardado na entidade pode vir como objeto ou como texto JSON. */
    function readStored(key) {
        var stored = window.MapasCulturais && MapasCulturais.entity && MapasCulturais.entity.object
            ? MapasCulturais.entity.object[key]
            : null;

        if (typeof stored === 'string') {
            try {
                stored = JSON.parse(stored);
            } catch (e) {
                stored = null;
            }
        }

        return stored && typeof stored === 'object' ? stored : null;
    }

    function radios(name, current, options, onChange) {
        var $group = el('div', {'class': 'conectaente-block__radios'});

        $.each(options, function (_, option) {
            var $input = el('input', {type: 'radio', name: name, value: option.value, checked: current === option.value});
            $input.on('change', function () {
                onChange(option.value);
            });
            $group.append(el('label', {}, [$input, ' ', option.label]));
        });

        return $group;
    }

    function checkbox(label, checked, onChange, disabled) {
        var $input = el('input', {type: 'checkbox', checked: !!checked, disabled: !!disabled});

        $input.on('change', function () {
            onChange($input.prop('checked'));
        });

        return el('label', {'class': 'conectaente-block__check'}, [$input, ' ', label]);
    }

    function numberInput(value, onChange, step) {
        var $input = el('input', {type: 'number', min: '0', step: step || '1', value: value === null ? '' : value});

        $input.on('change', function () {
            onChange($input.val());
        });

        return $input;
    }

    function mount($box, key, normalize, render) {
        var state = normalize(readStored(key));
        // abrir a aba não grava nada: só vale o que já estava guardado ou o que o administrador mexeu
        var touched = readStored(key) !== null;
        var $hidden = $('<input class="js-include-editable" type="hidden">').appendTo($box);
        var $ui = el('div', {'class': 'conectaente-block__ui'}).appendTo($box);

        $hidden.editable({name: key, showbuttons: false});

        function write() {
            $hidden.editable('setValue', JSON.stringify(state));
        }

        function commit() {
            touched = true;
            write();
        }

        // um cálculo da tela (ampla concorrência) só acompanha o que já é gravável
        function sync() {
            if (touched) {
                write();
            }
        }

        function redraw() {
            $ui.empty();
            render(state, $ui, redraw, commit, sync);
        }

        // o "Salvar" da página envia todo campo oculto, vazio ou não: sem isto, o que já estava guardado
        // e não foi mexido seria enviado vazio e apagado
        if (touched) {
            write();
        }

        redraw();

        return {redraw: redraw};
    }

    function fundingNormalize(cfg) {
        return function (raw) {
            var state = {
                houveUtilizacao: '',
                recursosProprios: null,
                conveniosParcerias: null,
                emendasParlamentares: null,
                remanescentesCiclo1: null,
                outrasFontes: null
            };

            if (raw && !$.isArray(raw)) {
                $.extend(state, raw);
            }

            if ($.isArray(state.outrasFontes)) {
                state.outrasFontes = $.map(state.outrasFontes, function (source) {
                    return {nomeFonte: String((source && source.nomeFonte) || ''), valor: toNumber(source && source.valor)};
                });
            } else {
                state.outrasFontes = null;
            }

            return state;
        };
    }

    function fundingRender(cfg) {
        var text = cfg.text;

        return function (state, $ui, redraw, commit) {
            $ui.append(el('p', {'class': 'conectaente-block__question', text: text.fundingQuestion}));
            $ui.append(radios('funding-used', state.houveUtilizacao, [
                {value: 'sim', label: text.yes},
                {value: 'nao', label: text.no}
            ], function (answer) {
                state.houveUtilizacao = answer;

                if (answer === 'nao') {
                    $.each(cfg.funding.sources, function (_, source) {
                        state[source.value] = null;
                    });
                    state[cfg.funding.other] = null;
                }

                commit();
                redraw();
            }));

            if (state.houveUtilizacao !== 'sim') {
                return;
            }

            // fonte desmarcada é null; marcada vale um número (0 é "usa e vale zero")
            $.each(cfg.funding.sources, function (_, source) {
                var $row = el('div', {'class': 'conectaente-block__row'});

                $row.append(checkbox(source.label, state[source.value] !== null, function (checked) {
                    state[source.value] = checked ? 0 : null;
                    commit();
                    redraw();
                }));

                if (state[source.value] !== null) {
                    $row.append(numberInput(state[source.value], function (value) {
                        state[source.value] = toNumber(value);
                        commit();
                    }, '0.01'));
                }

                $ui.append($row);
            });

            var other = state[cfg.funding.other];

            $ui.append(checkbox(cfg.funding.otherLabel, $.isArray(other), function (checked) {
                state[cfg.funding.other] = checked ? [{nomeFonte: '', valor: 0}] : null;
                commit();
                redraw();
            }));

            if (!$.isArray(other)) {
                return;
            }

            $.each(other, function (index, source) {
                var $name = el('input', {type: 'text', maxlength: cfg.funding.nameMax, value: source.nomeFonte, placeholder: text.sourceName});
                var $remove = el('button', {type: 'button', 'class': 'button', text: text.remove});

                $name.on('change', function () {
                    source.nomeFonte = $name.val();
                    commit();
                    // o botão de incluir outra fonte depende de todos os nomes estarem preenchidos
                    redraw();
                });
                $remove.on('click', function () {
                    other.splice(index, 1);
                    state[cfg.funding.other] = other.length ? other : null;
                    commit();
                    redraw();
                });

                $ui.append(el('div', {'class': 'conectaente-block__row'}, [
                    $name,
                    numberInput(source.valor, function (value) {
                        source.valor = toNumber(value);
                        commit();
                    }, '0.01'),
                    $remove
                ]));
            });

            var complete = $.grep(other, function (source) {
                return $.trim(source.nomeFonte) === '';
            }).length === 0;

            if (complete) {
                var $add = el('button', {type: 'button', 'class': 'button', text: text.addSource});

                $add.on('click', function () {
                    other.push({nomeFonte: '', valor: 0});
                    commit();
                    redraw();
                });
                $ui.append($add);
            }
        };
    }

    /**
     * O total de vagas da oportunidade. Na v5 é o metadado `numberVacancies` (editável só na
     * "Configuração de Cotas" de quem tem o selo SECULT); `vacancies` é o nome da v7. Vale o que
     * estiver na tela, que reflete a edição ainda não salva, e depois o que está guardado.
     */
    var ownTotal = null;

    function totalSlots() {
        if (ownTotal !== null) {
            return ownTotal;
        }

        var names = ['numberVacancies', 'vacancies'];
        var stored = window.MapasCulturais && MapasCulturais.entity && MapasCulturais.entity.object
            ? MapasCulturais.entity.object
            : {};
        var total = 0;

        $.each(names, function (_, name) {
            total = total || toInt($('.js-editable[data-edit="' + name + '"]').first().text()) || toInt(stored[name]);
        });

        return total;
    }

    function quotaNormalize(cfg) {
        var lawCount = cfg.quota.legal.length;

        function row(label, raw, withNotApplicable) {
            raw = raw || {};

            var item = {label: label, vagas: toInt(raw.vagas), valorDestinado: toNumber(raw.valorDestinado)};

            if (withNotApplicable) {
                item.naoAplicavel = !!raw.naoAplicavel;
            }

            return item;
        }

        // as três cotas da lei nas três primeiras posições, extras no meio e a ampla concorrência por último
        return function (raw) {
            var rows = $.isArray(raw) && raw.length >= lawCount ? raw : [];
            var hasOpen = rows.length > lawCount;
            var legal = $.map(cfg.quota.legal, function (label, index) {
                return row(label, rows[index], true);
            });
            var extras = $.map(hasOpen ? rows.slice(lawCount, -1) : [], function (extra) {
                return row($.trim(String((extra && extra.label) || '')), extra, true);
            });
            var open = row(cfg.quota.open, hasOpen ? rows[rows.length - 1] : null, true);

            return legal.concat(extras, [open]);
        };
    }

    function balanceOpenCompetition(rows) {
        var open = rows[rows.length - 1];
        var others = 0;

        $.each(rows.slice(0, -1), function (_, item) {
            others += item.vagas;
        });

        open.vagas = open.naoAplicavel ? 0 : Math.max(0, totalSlots() - others);
    }

    function quotaRender(cfg) {
        var text = cfg.text;

        var $vacancies = null;

        return function (rows, $ui, redraw, commit, sync) {
            // sem o campo da "Configuração de Cotas" na página, o total é digitado aqui e gravado em numberVacancies
            var ownField = $('.js-editable[data-edit="numberVacancies"]').length === 0;

            if (ownField && ownTotal === null) {
                ownTotal = toInt(window.MapasCulturais && MapasCulturais.entity && MapasCulturais.entity.object
                    ? MapasCulturais.entity.object.numberVacancies : 0);
            }

            balanceOpenCompetition(rows);

            var total = totalSlots();
            var $table = el('table', {'class': 'conectaente-block__table'});

            $ui.append(el('p', {'class': 'registration-help', text: text.quotaIntro}));

            if (ownField) {
                if (!$vacancies) {
                    $vacancies = $('<input class="js-include-editable" type="hidden">').appendTo($ui.parent());
                    $vacancies.editable({name: 'numberVacancies', showbuttons: false});

                    // mesmo motivo do mount(): o campo oculto vazio apagaria o total já guardado
                    if (ownTotal > 0) {
                        $vacancies.editable('setValue', String(ownTotal));
                    }
                }

                $ui.append(el('div', {'class': 'conectaente-block__row'}, [
                    el('label', {text: text.totalSlots + ': '}),
                    numberInput(ownTotal || '', function (value) {
                        ownTotal = toInt(value);
                        $vacancies.editable('setValue', String(ownTotal));
                        balanceOpenCompetition(rows);
                        commit();
                        redraw();
                    })
                ]));
            }
            $table.append(el('thead', {}, [el('tr', {}, [
                el('th', {text: text.quotaLabel}),
                el('th', {text: text.quotaSlots}),
                el('th', {text: text.quotaPercent}),
                el('th', {text: text.quotaAmount}),
                el('th', {text: text.notApplicable}),
                el('th')
            ])]));

            var $body = el('tbody').appendTo($table);

            $.each(rows, function (index, item) {
                var isOpen = index === rows.length - 1;
                var isExtra = index >= cfg.quota.legal.length && !isOpen;
                var label = cfg.quota.labels[item.label] || item.label;
                var disabled = !!item.naoAplicavel;
                var percent = total && !item.naoAplicavel ? (item.vagas / total * 100).toFixed(1).replace('.', ',') + '%' : '—';

                var $slots = numberInput(item.vagas, function (value) {
                    item.vagas = toInt(value);
                    commit();
                    redraw();
                });
                var $amount = numberInput(item.valorDestinado, function (value) {
                    item.valorDestinado = toNumber(value);

                    if (isOpen && item.valorDestinado > 0) {
                        item.naoAplicavel = false;
                    }

                    commit();
                    redraw();
                }, '0.01');

                // a ampla concorrência é calculada: as vagas dela não se digitam
                $slots.prop('disabled', disabled || isOpen);
                $amount.prop('disabled', disabled);

                var $remove = isExtra ? el('button', {type: 'button', 'class': 'button', text: text.remove}) : el('span');

                $remove.on('click', function () {
                    rows.splice(index, 1);
                    commit();
                    redraw();
                });

                $body.append(el('tr', {}, [
                    el('td', {text: label}),
                    el('td', {}, [$slots]),
                    el('td', {text: percent}),
                    el('td', {}, [$amount]),
                    el('td', {}, [checkbox('', item.naoAplicavel, function (checked) {
                        item.naoAplicavel = checked;

                        if (checked) {
                            item.vagas = 0;
                            item.valorDestinado = 0;
                        }

                        commit();
                        redraw();
                    })]),
                    el('td', {}, [$remove])
                ]));
            });

            $ui.append($table);
            if (!ownField) {
                $ui.append(el('p', {text: text.totalSlots + ': ' + (total || '—')}));
            }

            var $name = el('input', {type: 'text', maxlength: 255, placeholder: text.extraQuota});
            var $add = el('button', {type: 'button', 'class': 'button', text: text.addQuota});

            $add.on('click', function () {
                var label = $.trim($name.val());

                if (label === '') {
                    $name.focus();

                    return;
                }

                rows.splice(rows.length - 1, 0, {label: label, vagas: 0, valorDestinado: 0});
                commit();
                redraw();
            });
            $ui.append(el('div', {'class': 'conectaente-block__row'}, [$name, $add]));

            // o total de vagas é de outra aba: a ampla concorrência se ajusta ao abrir esta
            sync();
        };
    }

    function channelsNormalize(cfg) {
        var known = $.map(cfg.channels.channels, function (channel) {
            return channel.value;
        });

        return function (raw) {
            var state = {previstasNoEdital: '', formas: []};

            if (raw && !$.isArray(raw)) {
                state.previstasNoEdital = raw.previstasNoEdital === 'sim' || raw.previstasNoEdital === 'nao' ? raw.previstasNoEdital : '';
                state.formas = $.map($.isArray(raw.formas) ? raw.formas : [], function (form) {
                    return form && $.inArray(form.tipo, known) >= 0 ? {tipo: form.tipo, descricao: String(form.descricao || '')} : null;
                });
            }

            return state;
        };
    }

    function channelsRender(cfg) {
        var text = cfg.text;

        return function (state, $ui, redraw, commit) {
            $ui.append(el('p', {'class': 'conectaente-block__question', text: text.channelsQuestion}));
            $ui.append(radios('channels-planned', state.previstasNoEdital, [
                {value: 'sim', label: text.yes},
                {value: 'nao', label: text.no}
            ], function (answer) {
                state.previstasNoEdital = answer;

                if (answer === 'nao') {
                    state.formas = [];
                }

                commit();
                redraw();
            }));

            if (state.previstasNoEdital !== 'sim') {
                return;
            }

            $.each(cfg.channels.channels, function (_, channel) {
                var form = $.grep(state.formas, function (item) {
                    return item.tipo === channel.value;
                })[0];
                var $row = el('div', {'class': 'conectaente-block__row'});

                $row.append(checkbox(channel.label, !!form, function (checked) {
                    state.formas = $.grep(state.formas, function (item) {
                        return item.tipo !== channel.value;
                    });

                    if (checked) {
                        state.formas.push({tipo: channel.value, descricao: ''});
                    }

                    commit();
                    redraw();
                }));

                if (form) {
                    var $description = el('input', {
                        type: channel.value === cfg.channels.email ? 'email' : 'text',
                        value: form.descricao,
                        placeholder: channel.value === cfg.channels.email ? text.channelEmail : text.channelDescription
                    });

                    $description.on('change', function () {
                        form.descricao = $description.val();
                        commit();
                    });
                    $row.append($description);
                }

                $ui.append($row);
            });
        };
    }

    function affirmativeNormalize(cfg) {
        var withGroups = $.map($.grep(cfg.affirmative.options, function (option) {
            return option.hasGroups;
        }), function (option) {
            return option.value;
        });

        return function (raw) {
            var state = {opcoes: [], outra_legislacao_descricao: ''};

            $.each(withGroups, function (_, value) {
                state[value] = [];
            });

            if (raw && !$.isArray(raw)) {
                state.opcoes = $.isArray(raw.opcoes) ? raw.opcoes : [];
                state.outra_legislacao_descricao = typeof raw.outra_legislacao_descricao === 'string' ? raw.outra_legislacao_descricao : '';

                $.each(withGroups, function (_, value) {
                    state[value] = $.isArray(raw[value]) ? raw[value] : [];
                });
            }

            return state;
        };
    }

    function affirmativeRender(cfg) {
        var text = cfg.text;
        var aff = cfg.affirmative;
        var notPlanned = aff.notPlanned.value;

        function clearDetails(state) {
            $.each(aff.options, function (_, option) {
                if (option.hasGroups) {
                    state[option.value] = [];
                }
            });
            state.outra_legislacao_descricao = '';
        }

        return function (state, $ui, redraw, commit) {
            var exclusive = $.inArray(notPlanned, state.opcoes) >= 0;

            $ui.append(el('p', {'class': 'registration-help', text: text.affirmativeIntro}));

            // "não são previstas" exclui as demais
            $ui.append(checkbox(aff.notPlanned.label, exclusive, function (checked) {
                state.opcoes = checked ? [notPlanned] : [];

                if (checked) {
                    clearDetails(state);
                }

                commit();
                redraw();
            }));

            $.each(aff.options, function (_, option) {
                var chosen = $.inArray(option.value, state.opcoes) >= 0;
                var $block = el('div', {'class': 'conectaente-block__option'});

                $block.append(checkbox(option.label, chosen, function (checked) {
                    state.opcoes = $.grep(state.opcoes, function (value) {
                        return value !== option.value;
                    });

                    if (checked) {
                        state.opcoes.push(option.value);
                    } else if (option.hasGroups) {
                        state[option.value] = [];
                    } else {
                        state.outra_legislacao_descricao = '';
                    }

                    commit();
                    redraw();
                }, exclusive));

                if (chosen && option.hasGroups) {
                    $block.append(el('p', {'class': 'conectaente-block__sub', text: text.groups + ':'}));

                    $.each(aff.groups, function (_, group) {
                        $block.append(checkbox(group.label, $.inArray(group.value, state[option.value]) >= 0, function (checked) {
                            state[option.value] = $.grep(state[option.value], function (value) {
                                return value !== group.value;
                            });

                            if (checked) {
                                state[option.value].push(group.value);
                            }

                            commit();
                        }));
                    });
                }

                if (chosen && option.value === aff.other) {
                    var $description = el('textarea', {maxlength: aff.descriptionMax, rows: 3, placeholder: text.otherDescription});
                    var $counter = el('small', {text: state.outra_legislacao_descricao.length + '/' + aff.descriptionMax});

                    $description.val(state.outra_legislacao_descricao);
                    $description.on('input change', function () {
                        state.outra_legislacao_descricao = $description.val().slice(0, aff.descriptionMax);
                        $counter.text(state.outra_legislacao_descricao.length + '/' + aff.descriptionMax);
                        commit();
                    });
                    $block.append($description, $counter);
                }

                $ui.append($block);
            });
        };
    }

    // o script sai no <head>: sem esperar o DOM, a aba ainda não existe
    jQuery(function ($) {
        var $root = $('#conectaente-blocks');

        if (!$root.length) {
            return;
        }

        var cfg = $root.data('config');

        if (!cfg) {
            return;
        }

        var blocks = {
            funding: [fundingNormalize(cfg), fundingRender(cfg)],
            quota: [quotaNormalize(cfg), quotaRender(cfg)],
            channels: [channelsNormalize(cfg), channelsRender(cfg)],
            affirmative: [affirmativeNormalize(cfg), affirmativeRender(cfg)]
        };

        var mounted = {};

        $.each(blocks, function (name, pair) {
            var $box = $root.find('[data-block="' + name + '"]');

            if ($box.length) {
                mounted[name] = mount($box, cfg.keys[name], pair[0], pair[1]);
            }
        });

        // o total de vagas é editado na aba principal: ao voltar para esta, a ampla concorrência se refaz
        $('a[href="#conecta-ente-cultbr"]').on('click', function () {
            if (mounted.quota) {
                mounted.quota.redraw();
            }
        });
    });
})(jQuery);
