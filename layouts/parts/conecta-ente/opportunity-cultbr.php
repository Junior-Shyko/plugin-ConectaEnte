<?php

use ConectaEnte\Metadata\BlockUiConfig;
use ConectaEnte\Metadata\CultBrMetadata as Meta;
use MapasCulturais\i;

$definitions = Meta::definitions();

$choices = [
    Meta::EXECUTION_TYPE => null,
    Meta::SEGMENTS => Meta::SEGMENTS_OTHER,
    Meta::CULTURAL_STAGES => Meta::CULTURAL_STAGES_OTHER,
    Meta::THEMATIC_AGENDAS => Meta::THEMATIC_AGENDAS_OTHER,
    Meta::PRIORITY_TERRITORIES => null,
    Meta::PROPONENT_TYPES => null,
    Meta::TOTAL_RESOURCE => null,
];
?>
<div id="conecta-ente-cultbr" class="aba-content">
    <p class="registration-help">
        <?php i::_e('Dados que o CultBR (Conecta Ente) exige do edital de ente federado e que o Mapas Culturais não tem. Eles serão enviados junto com a oportunidade.'); ?>
    </p>
    <fieldset class="conectaente-par">
        <legend><strong><?= i::__('Dados do PAR') ?> </strong>*</legend>

    <?php foreach ($choices as $key => $specifyKey): ?>
        <?php $label = $definitions[$key]['label']; ?>
        <p  class="conectaente-paragraf">
            <span class="label"><?= $label ?> *:</span>
            <?php if ($definitions[$key]['type'] === 'multiselect'): ?>
                <editable-multiselect
                    entity-property="<?= $key ?>"
                    empty-label="<?php i::esc_attr_e('Selecione'); ?>"
                    allow-other="false"
                    box-title="<?= htmlspecialchars($label . ':', ENT_QUOTES) ?>"></editable-multiselect>
            <?php else: ?>
                <span class="js-editable"
                      data-edit="<?= $key ?>"
                      data-original-title="<?= htmlspecialchars($label, ENT_QUOTES) ?>"
                      data-emptytext="<?= $definitions[$key]['type'] === 'select' ? i::esc_attr__('Selecione') : i::esc_attr__('Informe') ?>"><?= htmlspecialchars((string) $entity->$key) ?></span>
            <?php endif; ?>
        </p>
        <?php if ($specifyKey !== null): ?>
            <p class="conectaente-paragraf">
                <span class="label"><?= $definitions[$specifyKey]['label'] ?>:</span>
                <span class="js-editable"
                      data-edit="<?= $specifyKey ?>"
                      data-original-title="<?= htmlspecialchars($definitions[$specifyKey]['label'], ENT_QUOTES) ?>"
                      data-emptytext="<?php i::esc_attr_e('Preencha se escolheu a opção de especificar'); ?>"><?= htmlspecialchars((string) $entity->$specifyKey) ?></span>
            </p>
        <?php endif; ?>
    <?php endforeach; ?>

        <p class="conectaente-paragraf">
        <span class="label"><?= i::__('Data de publicação do edital') ?> *:</span>
        <span class="js-editable"
              data-edit="<?= Meta::PUBLICATION_DATE ?>"
              data-type="date"
              data-original-title="<?= htmlspecialchars(i::__('Data de publicação do edital'), ENT_QUOTES) ?>"
              data-emptytext="<?php i::esc_attr_e('Informe a data'); ?>"><?= htmlspecialchars((string) $entity->getMetadata(Meta::PUBLICATION_DATE)) ?></span>
    </p>

    </fieldset>
    <?php
    // a cascata do PAR (par-cascade.js, AngularJS): lê a árvore do cache e grava os quatro ids juntos
    $parConfig = [
        'levels' => array_map(function ($key) use ($definitions) {
            return ['key' => $key, 'label' => $definitions[$key]['label']];
        }, Meta::PAR_KEYS),
        'texts' => [
            'select' => i::__('Selecione'),
            'update' => i::__('Atualizar'),
            'loading' => i::__('Carregando as opções do PAR…'),
            'unavailable' => i::__('Não foi possível carregar as opções do PAR. Tente atualizar.'),
            'simulated' => i::__('Árvore simulada (modo de teste).'),
            'noOptions' => i::__('O ente não tem exercícios no PAR.'),
            'staleValue' => i::__('O id guardado para «%s» não está na árvore atual: refaça a seleção.'),
        ],
    ];
    ?>
    <fieldset class="conectaente-par">
        <legend><strong><?= i::__('Dados do PAR') ?> </strong>*</legend>
        <p class="conectaente-paragraf">
        <span>Exercício, meta, ação e atividade do Plano de Ação e Referência do Ente Federado que selou este edital.</span>
        </p>
        <p class="conectaente-paragraf">
        <conecta-ente-par config="<?= htmlspecialchars(json_encode($parConfig), ENT_QUOTES) ?>"></conecta-ente-par>
        </p>
    </fieldset>



    <?php
    $blocks = [
        'funding' => Meta::FUNDING_SOURCES,
        'quota' => Meta::QUOTA_RESERVATION,
        'channels' => Meta::REGISTRATION_CHANNELS,
        'affirmative' => Meta::AFFIRMATIVE_ACTIONS,
    ];
    ?>
    <div id="conectaente-blocks" data-config="<?= htmlspecialchars(json_encode(BlockUiConfig::toArray()), ENT_QUOTES) ?>">
        <?php foreach ($blocks as $name => $key): ?>
            <fieldset class="conectaente-block" data-block="<?= $name ?>">
                <legend><?= htmlspecialchars($definitions[$key]['label']) ?> *</legend>
            </fieldset>
        <?php endforeach; ?>
    </div>
</div>
