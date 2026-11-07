<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Jobs\SendOpportunityJob;
use ConectaEnte\Plugin;
use MapasCulturais\Definitions\JobType;
use PHPUnit\Framework\TestCase;

/**
 * Fumaça estática: a suíte não sobe o App (sem banco), então "o plugin carregou" é provado pelo código:
 * a classe é carregável, estende o plugin do core e o código-fonte registra os hooks e o job esperados.
 * Subir o App de verdade fica para a suíte do core.
 */
class SmokeTest extends TestCase
{
    private static $source;

    public static function setUpBeforeClass(): void
    {
        $reflection = new \ReflectionClass(Plugin::class);
        self::$source = file_get_contents($reflection->getFileName());
    }

    public function testPluginClassExtendsTheCorePlugin()
    {
        $this->assertTrue(is_subclass_of(Plugin::class, \MapasCulturais\Plugin::class));
    }

    public function testPluginHasTheLifecycleMethods()
    {
        foreach (['_init', 'register'] as $method) {
            $this->assertTrue(method_exists(Plugin::class, $method), $method);
        }
    }

    /**
     * @dataProvider hooks
     */
    public function testHookIsRegistered($hook)
    {
        $this->assertStringContainsString("hook('$hook'", self::$source);
    }

    public function hooks()
    {
        return [
            'save stamp' => ['entity(Opportunity).save:before'],
            'send on update' => ['entity(Opportunity).update:finish'],
            'send on seal' => ['entity(OpportunitySealRelation).save:finish'],
            'publication requirements' => ['entity(Opportunity).publish:before'],
            'seal guard' => ['POST(opportunity.createSealRelation):before'],
            'panel route' => ['GET(panel.federative-entities)'],
        ];
    }

    public function testSendJobIsRegistered()
    {
        $this->assertStringContainsString('registerJobType(new SendOpportunityJob(SendOpportunityJob::SLUG))', self::$source);
        $this->assertSame('conectaente-send-opportunity', SendOpportunityJob::SLUG);
        $this->assertTrue(is_subclass_of(SendOpportunityJob::class, JobType::class));
    }

    public function testTestEnvironmentIsInDevModeWithoutHostOrToken()
    {
        $this->assertSame('dev', getenv('CONECTAENTE_MODE'));
        $this->assertSame('now', getenv('CONECTAENTE_RETRY_DELAY'));
        $this->assertSame('', getenv('CONECTAENTE_HOST'));
    }
}
