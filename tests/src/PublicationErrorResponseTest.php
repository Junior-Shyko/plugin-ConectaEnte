<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Plugin;
use PHPUnit\Framework\TestCase;

class PublicationErrorResponseTest extends TestCase
{
    private $missing = [
        'rules' => ['O campo «Adicionar regulamento» é obrigatório.'],
        'conectaente_parAcaoId' => ['A Ação do PAR é obrigatória.'],
    ];

    // o front (AJAX) mapeia os campos pelo formato de validação do core: 200 com `error: true`
    public function testTheFrontGetsStatus200WithTheFieldsKeyed()
    {
        [$status, $body] = Plugin::publicationErrorResponse($this->missing, true);

        $this->assertSame(200, $status);
        $this->assertSame(['error' => true, 'data' => $this->missing], json_decode($body, true));
    }

    // um cliente de API não pode ler 200 como publicação feita
    public function testANonAjaxClientGetsStatus400WithTheSameBody()
    {
        [$status, $body] = Plugin::publicationErrorResponse($this->missing, false);

        $this->assertSame(400, $status);
        $this->assertSame(['error' => true, 'data' => $this->missing], json_decode($body, true));
    }
}
