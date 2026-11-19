<?php

use MapasCulturais\i;

// a lista vem do endpoint conecta-ente/logs (send-history.js, AngularJS): a aba só entrega os textos
$config = [
    'texts' => [
        'loading' => i::__('Carregando o histórico…'),
        'empty' => i::__('Esta oportunidade ainda não teve envios ao CultBR.'),
        'failed' => i::__('Não foi possível carregar o histórico de envios.'),
        'retry' => i::__('Tentar novamente'),
        'loadMore' => i::__('Carregar mais'),
        'byUser' => i::__('por usuário #%s'),
        'attempt' => i::__('Tentativa %1$s/%2$s'),
        'noAttempts' => i::__('Nenhuma tentativa foi registrada neste envio.'),
        'sentAt' => i::__('Enviado em'),
        'endpoint' => i::__('Endpoint'),
        'payload' => i::__('Payload gerado'),
        'response' => i::__('Resposta do servidor'),
        'headers' => i::__('Cabeçalhos'),
        'body' => i::__('Corpo'),
        'error' => i::__('Erro'),
        'noResponse' => i::__('Sem resposta do servidor.'),
        'truncated' => i::__('Resposta truncada no registro.'),
        'copy' => i::__('Copiar'),
        'copied' => i::__('Copiado'),
        'copyFailed' => i::__('Não foi possível copiar'),
        'parEditalId' => i::__('ID do edital no CultBR'),
        'requestId' => i::__('ID da requisição (x-request-id)'),
    ],
];
?>
<div id="conecta-ente-send-history" class="aba-content">
    <p class="registration-help">
        <?php i::_e('Cada envio desta oportunidade ao CultBR, com o que foi enviado e o que o servidor respondeu em cada tentativa.'); ?>
    </p>
    <conecta-ente-send-history config="<?= htmlspecialchars(json_encode($config), ENT_QUOTES) ?>"></conecta-ente-send-history>
</div>
