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
];
?>
<div id="conecta-ente-cultbr" class="aba-content">
    <p class="registration-help">
        <?php i::_e('Dados que o CultBR (Conecta Ente) exige do edital de ente federado e que o Mapas Culturais não tem. Eles serão enviados junto com a oportunidade.'); ?>
    </p>

    <?php foreach ($choices as $key => $specifyKey): ?>
        <?php $label = $definitions[$key]['label']; ?>
        <p>
            <span class="label"><?= $label ?>:</span>
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
                      data-emptytext="<?php i::esc_attr_e('Selecione'); ?>"><?= htmlspecialchars((string) $entity->$key) ?></span>
            <?php endif; ?>
        </p>
        <?php if ($specifyKey !== null): ?>
            <p>
                <span class="label"><?= $definitions[$specifyKey]['label'] ?>:</span>
                <span class="js-editable"
                      data-edit="<?= $specifyKey ?>"
                      data-original-title="<?= htmlspecialchars($definitions[$specifyKey]['label'], ENT_QUOTES) ?>"
                      data-emptytext="<?php i::esc_attr_e('Preencha se escolheu a opção de especificar'); ?>"><?= htmlspecialchars((string) $entity->$specifyKey) ?></span>
            </p>
        <?php endif; ?>
    <?php endforeach; ?>

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
                <legend><?= htmlspecialchars($definitions[$key]['label']) ?></legend>
            </fieldset>
        <?php endforeach; ?>
    </div>
</div>
