<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Metadata\BlockUiConfig;
use ConectaEnte\Metadata\CultBrMetadata;
use PHPUnit\Framework\TestCase;

class BlockUiConfigTest extends TestCase
{
    private $config;

    protected function setUp(): void
    {
        $this->config = BlockUiConfig::toArray();
    }

    public function testKeysPointToTheRegisteredBlockMetadata()
    {
        $this->assertSame([
            'funding' => 'conectaente_fundingSources',
            'quota' => 'conectaente_quotaReservation',
            'channels' => 'conectaente_registrationChannels',
            'affirmative' => 'conectaente_affirmativeActions',
        ], $this->config['keys']);

        foreach ($this->config['keys'] as $key) {
            $this->assertContains($key, CultBrMetadata::keys());
        }
    }

    public function testFundingListsTheFourFixedSourcesAndKeepsTheFreeOneApart()
    {
        $values = array_column($this->config['funding']['sources'], 'value');

        $this->assertSame(['recursosProprios', 'conveniosParcerias', 'emendasParlamentares', 'remanescentesCiclo1'], $values);
        $this->assertSame('outrasFontes', $this->config['funding']['other']);
        $this->assertSame(255, $this->config['funding']['nameMax']);
    }

    public function testQuotaKeepsTheStoredTextAndTheOpenCompetitionApart()
    {
        $this->assertSame(
            ['Pessoas negras (pretas e pardas)', 'Pessoas indígenas', 'Pessoas com deficiência'],
            $this->config['quota']['legal']
        );
        $this->assertSame('Ampla concorrência', $this->config['quota']['open']);
        $this->assertArrayHasKey('Ampla concorrência', $this->config['quota']['labels']);
    }

    public function testChannelsAreTheSixOfTheContract()
    {
        $this->assertSame(
            ['email', 'presencial', 'correio', 'oral', 'sistema', 'outros'],
            array_column($this->config['channels']['channels'], 'value')
        );
        $this->assertSame('email', $this->config['channels']['email']);
    }

    public function testAffirmativeOptionsLeaveOutTheExclusiveOneAndFlagTheOnesWithGroups()
    {
        $options = $this->config['affirmative']['options'];

        $this->assertSame(
            ['bonus_agentes', 'bonus_tematicas', 'categoria_especifica', 'edital_especifico', 'outra_legislacao'],
            array_column($options, 'value')
        );
        $this->assertSame([true, true, true, true, false], array_column($options, 'hasGroups'));
        $this->assertSame('nao_previstas', $this->config['affirmative']['notPlanned']['value']);
        $this->assertSame(140, $this->config['affirmative']['descriptionMax']);
        $this->assertCount(9, $this->config['affirmative']['groups']);
    }

    public function testConfigSurvivesJsonEncoding()
    {
        $this->assertNotFalse(json_encode($this->config));
    }
}
