<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Plugin;
use PHPUnit\Framework\TestCase;

class PublicationErrorResponseTest extends TestCase
{
    public function testPendingFieldsAreAnsweredWithStatus200AndTheFieldsKeyed()
    {
        $missing = [
            'rules' => ['O campo "Adicionar regulamento" é obrigatório.'],
            'conectaente_parAcaoId' => ['O campo "Ação do PAR" é obrigatório.'],
        ];

        [$status, $body] = Plugin::publicationErrorResponse($missing);

        $this->assertSame(200, $status);
        $this->assertSame(['error' => true, 'data' => $missing], json_decode($body, true));
    }
}
