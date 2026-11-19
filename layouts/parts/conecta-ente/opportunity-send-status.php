<?php

use MapasCulturais\i;

// a situação vem do endpoint conecta-ente/status (send-history.js, AngularJS): a aba só entrega os textos
$config = [
    'texts' => [
        'title' => i::__('Situação do envio ao CultBR'),
        'loading' => i::__('Carregando a situação do envio…'),
        'failed' => i::__('Não foi possível carregar a situação do envio.'),
        'retry' => i::__('Tentar novamente'),
        'lastSend' => i::__('Último envio'),
        'on' => i::__('em %s'),
        'httpStatus' => i::__('HTTP %s'),
        'parEditalId' => i::__('ID do edital no CultBR: %s'),
        'history' => i::__('Ver histórico de envio'),
    ],
];
?>
<conecta-ente-send-status config="<?= htmlspecialchars(json_encode($config), ENT_QUOTES) ?>"></conecta-ente-send-status>
