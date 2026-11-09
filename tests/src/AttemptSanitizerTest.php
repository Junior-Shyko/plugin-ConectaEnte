<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Services\AttemptSanitizer;
use PHPUnit\Framework\TestCase;

class AttemptSanitizerTest extends TestCase
{
    public function testInvalidBytesBecomeTheReplacementCharacter()
    {
        $this->assertSame("caf\u{FFFD} ok", AttemptSanitizer::text("caf\xE9 ok"));
    }

    public function testNulIsRemoved()
    {
        $this->assertSame('ab', AttemptSanitizer::text("a\0b"));
    }

    public function testTheSubstituteCharacterSettingIsRestored()
    {
        $before = mb_substitute_character();

        AttemptSanitizer::text("x\xE9");

        $this->assertSame($before, mb_substitute_character());
    }

    public function testJsonBodyComesBackDecoded()
    {
        $this->assertSame(['id_par_edital' => 5], AttemptSanitizer::response('{"id_par_edital":5}'));
    }

    public function testBodyThatIsNotJsonGoesInTheRawEnvelope()
    {
        $this->assertSame(['raw' => 'Internal Server Error'], AttemptSanitizer::response('Internal Server Error'));
    }

    public function testEmptyBodyIsNull()
    {
        $this->assertNull(AttemptSanitizer::response(''));
    }

    public function testHugeBodyIsCutInBytesWithoutBreakingACharacter()
    {
        $body = str_repeat('é', 40000);

        $response = AttemptSanitizer::response($body);

        $this->assertTrue($response['truncated']);
        $this->assertLessThanOrEqual(AttemptSanitizer::MAX_RESPONSE_BYTES, strlen($response['raw']));
        $this->assertTrue(mb_check_encoding($response['raw'], 'UTF-8'));
    }

    public function testJsonWithNulEscapeFallsBackToTheRawEnvelope()
    {
        $response = AttemptSanitizer::response('{"a":"x\u0000y"}');

        $this->assertArrayHasKey('raw', $response);
    }

    public function testHeadersAreLowercasedAndSanitized()
    {
        $headers = AttemptSanitizer::headers(['X-Request-Id' => 'r-1', 'Server' => "nginx\xE9"]);

        $this->assertSame(['x-request-id' => 'r-1', 'server' => "nginx\u{FFFD}"], $headers);
        $this->assertNotFalse(json_encode($headers));
    }

    public function testNoHeadersIsNull()
    {
        $this->assertNull(AttemptSanitizer::headers([]));
    }

    public function testNulIsRemovedFromThePayloadValuesBeforeEncoding()
    {
        $json = AttemptSanitizer::payloadJson(['a' => "x\0y", 'nested' => ['b' => "\0"]]);

        $this->assertSame(['a' => 'xy', 'nested' => ['b' => '']], json_decode($json, true));
    }

    public function testALiteralBackslashFollowedByU0000SurvivesInThePayload()
    {
        $json = AttemptSanitizer::payloadJson(['a' => 'termina em \\u0000']);

        $this->assertNotNull($json);
        $this->assertSame('termina em \\u0000', json_decode($json, true)['a']);
    }

    public function testALiteralBackslashU0000ResponseIsStoredDecoded()
    {
        $response = AttemptSanitizer::response(json_encode(['a' => 'termina em \\u0000']));

        $this->assertSame(['a' => 'termina em \\u0000'], $response);
    }

    public function testLimitedCutsInCharactersAndKeepsNull()
    {
        $this->assertSame('ééé', AttemptSanitizer::limited('ééééé', 3));
        $this->assertNull(AttemptSanitizer::limited(null, 3));
    }
}
