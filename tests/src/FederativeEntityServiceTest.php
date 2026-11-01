<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Exceptions\DuplicateSealException;
use ConectaEnte\Http\TokenValidation;
use ConectaEnte\Repositories\FederativeEntityRepository;
use ConectaEnte\Services\FederativeEntityService;
use PHPUnit\Framework\TestCase;

class FederativeEntityServiceTest extends TestCase
{
    const TOKEN = 'cult_abcdefghijklmnopqrstuvwxyz0123';

    private $repository;
    private $verified = [];
    private $validation;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FederativeEntityRepository::class);
        $this->repository->method('sealExists')->willReturn(true);
        $this->validation = TokenValidation::accept('12345678000199', 'Governo X');
    }

    public function testEveryActionIsForbiddenForWhoIsNotSaasSuperAdmin()
    {
        $this->repository->expects($this->never())->method('save');
        $this->repository->expects($this->never())->method('delete');
        $this->repository->expects($this->never())->method('findAll');

        $service = $this->service(false);

        foreach ([
            $service->all(),
            $service->create(['name' => 'X', 'seal_id' => 1, 'token' => self::TOKEN]),
            $service->update(1, ['name' => 'Y']),
            $service->remove(1),
        ] as $result) {
            $this->assertSame(403, $result->getStatus());
            $this->assertTrue($result->isError());
        }
        $this->assertSame([], $this->verified);
    }

    public function testCreateVerifiesTheTrimmedTokenAndStoresItWithTheCnpj()
    {
        $this->repository->method('findBySealId')->willReturn(null);
        $saved = null;
        $this->repository->method('save')->willReturnCallback(function ($ente) use (&$saved) {
            $saved = $ente;

            return $ente;
        });

        $result = $this->service()->create(['name' => ' Governo X ', 'seal_id' => '10', 'token' => '  ' . self::TOKEN . "\n"]);

        $this->assertSame(201, $result->getStatus());
        $this->assertSame([self::TOKEN], $this->verified);
        $this->assertSame(self::TOKEN, $saved->getToken());
        $this->assertSame('Governo X', $saved->getName());
        $this->assertSame(10, $saved->getSealId());
        $this->assertSame('12345678000199', $saved->getCnpj());
    }

    public function testVerifyTokenReturnsTheSuggestedNameAndCnpjWithoutSaving()
    {
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->verifyToken(['token' => '  ' . self::TOKEN . ' ']);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame(['name' => 'Governo X', 'cnpj' => '12345678000199'], $result->getBody());
        $this->assertSame([self::TOKEN], $this->verified);
    }

    public function testVerifyTokenHandlesEmptyRejectedUnavailableAndForbidden()
    {
        $this->assertSame(400, $this->service()->verifyToken(['token' => ' '])->getStatus());
        $this->assertSame([], $this->verified);

        $this->validation = TokenValidation::reject('recusado');
        $this->assertSame(400, $this->service()->verifyToken(['token' => 'x-token'])->getStatus());

        $this->validation = TokenValidation::unreachable();
        $this->assertSame(503, $this->service()->verifyToken(['token' => 'x-token'])->getStatus());

        $verified = count($this->verified);
        $this->assertSame(403, $this->service(false)->verifyToken(['token' => 'x-token'])->getStatus());
        $this->assertCount($verified, $this->verified);
    }

    public function testCreateRequiresNameSealAndToken()
    {
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->create(['name' => '  ', 'token' => '']);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame(['name', 'token', 'seal_id'], array_keys($result->getBody()['data']));
        $this->assertSame([], $this->verified);
    }

    public function testCreateRejectsASealThatDoesNotExist()
    {
        $repository = $this->createMock(FederativeEntityRepository::class);
        $repository->method('sealExists')->willReturn(false);
        $repository->expects($this->never())->method('save');

        $result = $this->service(true, $repository)->create(['name' => 'X', 'seal_id' => 99, 'token' => self::TOKEN]);

        $this->assertSame(400, $result->getStatus());
        $this->assertArrayHasKey('seal_id', $result->getBody()['data']);
    }

    public function testCreateWithTakenSealIs409NamingTheExistingEntityAndSkipsTheApi()
    {
        $this->repository->method('findBySealId')->with(10)->willReturn($this->ente(3, 'Município Y', 10));
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->create(['name' => 'Outro', 'seal_id' => 10, 'token' => self::TOKEN]);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('Município Y', $result->getBody()['data']['seal_id']);
        $this->assertSame([], $this->verified);
    }

    public function testCreateWithConcurrentDuplicateIs409()
    {
        $this->repository->method('findBySealId')->willReturn(null);
        $this->repository->method('save')->willThrowException(new DuplicateSealException(10, 'Município Y'));

        $result = $this->service()->create(['name' => 'Outro', 'seal_id' => 10, 'token' => self::TOKEN]);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('Município Y', $result->getBody()['data']['seal_id']);
    }

    public function testRejectedTokenIsNotSaved()
    {
        $this->repository->method('findBySealId')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->validation = TokenValidation::reject('A Plataforma CultBR não reconheceu este token.');

        $result = $this->service()->create(['name' => 'X', 'seal_id' => 10, 'token' => self::TOKEN]);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('A Plataforma CultBR não reconheceu este token.', $result->getBody()['data']['token']);
    }

    public function testUnavailableVerificationIs503AndNothingIsSaved()
    {
        $this->repository->method('findBySealId')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->validation = TokenValidation::unreachable();

        $result = $this->service()->create(['name' => 'X', 'seal_id' => 10, 'token' => self::TOKEN]);

        $this->assertSame(503, $result->getStatus());
    }

    public function testResponseNeverCarriesTheToken()
    {
        $this->repository->method('findAll')->willReturn([$this->ente(1, 'X', 10)]);

        $body = $this->service()->all()->getBody();

        $this->assertArrayNotHasKey('token', $body[0]);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($body));
        $this->assertTrue($body[0]['token_registered']);
        $this->assertSame('0123', $body[0]['token_last4']);
    }

    public function testShortTokenDoesNotShowItsEnding()
    {
        $ente = $this->ente(1, 'X', 10);
        $ente->setToken('curto123');

        $present = $this->service()->present($ente);

        $this->assertTrue($present['token_registered']);
        $this->assertNull($present['token_last4']);
    }

    public function testUpdateWithNewTokenVerifiesBeforeReplacingTheOldOne()
    {
        $ente = $this->ente(1, 'X', 10);
        $this->repository->method('findById')->with(1)->willReturn($ente);
        $this->repository->expects($this->once())->method('save')->with($ente)->willReturn($ente);
        $this->validation = TokenValidation::accept('99999999000191', null);

        $result = $this->service()->update(1, ['token' => ' novo-token-bem-longo ']);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame(['novo-token-bem-longo'], $this->verified);
        $this->assertSame('novo-token-bem-longo', $ente->getToken());
        $this->assertSame('99999999000191', $ente->getCnpj());
    }

    public function testUpdateKeepsTheOldTokenWhenTheNewOneIsRejected()
    {
        $ente = $this->ente(1, 'X', 10);
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->expects($this->never())->method('save');
        $this->validation = TokenValidation::reject('recusado');

        $result = $this->service()->update(1, ['token' => 'novo-token-bem-longo']);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame(self::TOKEN, $ente->getToken());
    }

    // a entidade é gerenciada pelo Doctrine: um flush posterior gravaria o que foi alterado em memória
    public function testUpdateLeavesTheEntityUntouchedWhenTheNewTokenIsRejected()
    {
        $ente = $this->ente(1, 'Nome original', 10);
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->method('findBySealId')->willReturn(null);
        $this->repository->expects($this->never())->method('save');
        $this->validation = TokenValidation::reject('recusado');

        $result = $this->service()->update(1, ['name' => 'Nome novo', 'seal_id' => 20, 'token' => 'novo-token-bem-longo']);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame('Nome original', $ente->getName());
        $this->assertSame(10, $ente->getSealId());
        $this->assertSame(self::TOKEN, $ente->getToken());
    }

    public function testUpdateResendingTheSameTokenVerifiesAgainAndRefreshesTheCnpj()
    {
        $ente = $this->ente(1, 'X', 10);
        $ente->setCnpj('00000000000191');
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->method('save')->willReturn($ente);
        $this->validation = TokenValidation::accept('27174093000127', null);

        $result = $this->service()->update(1, ['token' => self::TOKEN]);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame([self::TOKEN], $this->verified);
        $this->assertSame('27174093000127', $ente->getCnpj());
    }

    public function testUpdateCollapsesRepeatedSpacesInTheName()
    {
        $ente = $this->ente(1, 'X', 10);
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->method('save')->willReturn($ente);

        $this->service()->update(1, ['name' => '  MUNICIPIO DA   SERRA ']);

        $this->assertSame('MUNICIPIO DA SERRA', $ente->getName());
    }

    public function testUpdateRejectsANameWithInvalidUtf8InsteadOfFailingOnSave()
    {
        $ente = $this->ente(1, 'X', 10);
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->update(1, ['name' => "Nome \xFF  inv\xE1lido"]);

        $this->assertSame(400, $result->getStatus());
        $this->assertSame(['name'], array_keys($result->getBody()['data']));
        $this->assertSame('X', $ente->getName());
    }

    public function testUpdateWithoutTokenDoesNotCallTheApi()
    {
        $ente = $this->ente(1, 'X', 10);
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->method('save')->willReturn($ente);

        $result = $this->service()->update(1, ['name' => 'Novo nome']);

        $this->assertSame(200, $result->getStatus());
        $this->assertSame('Novo nome', $ente->getName());
        $this->assertSame([], $this->verified);
    }

    public function testUpdateRejectsAnEmptyTokenInsteadOfIgnoringIt()
    {
        $this->repository->method('findById')->willReturn($this->ente(1, 'X', 10));

        $result = $this->service()->update(1, ['token' => '   ']);

        $this->assertSame(400, $result->getStatus());
        $this->assertArrayHasKey('token', $result->getBody()['data']);
    }

    public function testUpdateToASealOfAnotherEntityIs409()
    {
        $this->repository->method('findById')->willReturn($this->ente(1, 'X', 10));
        $this->repository->method('findBySealId')->with(20)->willReturn($this->ente(2, 'Município Y', 20));
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->update(1, ['seal_id' => 20]);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('Município Y', $result->getBody()['data']['seal_id']);
    }

    public function testCreateIs409WhenTheSealIsOnAnOpportunityThatAlreadyHasAnotherEntity()
    {
        $this->repository->method('findOpportunityHoldingAnotherEntitySeal')
            ->with(10, null)
            ->willReturn(['opportunity_id' => 8452, 'entity_name' => 'Município Y']);
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->create(['name' => 'X', 'seal_id' => 10, 'token' => self::TOKEN]);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('8452', $result->getBody()['data']['seal_id']);
        $this->assertStringContainsString('Município Y', $result->getBody()['data']['seal_id']);
        $this->assertSame([], $this->verified);
    }

    public function testUpdateToASealHeldByAnOpportunityWithAnotherEntityIs409()
    {
        $this->repository->method('findById')->willReturn($this->ente(1, 'X', 10));
        $this->repository->method('findOpportunityHoldingAnotherEntitySeal')
            ->with(20, 1)
            ->willReturn(['opportunity_id' => 7, 'entity_name' => 'Município Y']);
        $this->repository->expects($this->never())->method('save');

        $result = $this->service()->update(1, ['seal_id' => 20]);

        $this->assertSame(409, $result->getStatus());
        $this->assertStringContainsString('Município Y', $result->getBody()['data']['seal_id']);
    }

    public function testUpdateKeepingTheSameSealDoesNotLookForOpportunityConflicts()
    {
        $this->repository->method('findById')->willReturn($this->ente(1, 'X', 10));
        $this->repository->expects($this->never())->method('findOpportunityHoldingAnotherEntitySeal');

        $result = $this->service()->update(1, ['seal_id' => 10, 'name' => 'Novo']);

        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateAndRemoveOfUnknownEntityAre404()
    {
        $this->repository->method('findById')->willReturn(null);

        $this->assertSame(404, $this->service()->update(7, ['name' => 'X'])->getStatus());
        $this->assertSame(404, $this->service()->remove(7)->getStatus());
    }

    public function testRemoveDeletesTheEntity()
    {
        $ente = $this->ente(1, 'X', 10);
        $this->repository->method('findById')->willReturn($ente);
        $this->repository->expects($this->once())->method('delete')->with($ente);

        $this->assertSame(200, $this->service()->remove(1)->getStatus());
    }

    private function service($canManage = true, $repository = null)
    {
        return new FederativeEntityService($repository ?: $this->repository, function () use ($canManage) {
            return $canManage;
        }, function ($token) {
            $this->verified[] = $token;

            return $this->validation;
        }, function () {
            return $this->blankEntity();
        });
    }

    // o construtor do core precisa do App
    private function blankEntity()
    {
        return (new \ReflectionClass(FederativeEntity::class))->newInstanceWithoutConstructor();
    }

    private function ente($id, $name, $sealId)
    {
        $ente = $this->blankEntity();
        $ref = new \ReflectionProperty(FederativeEntity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($ente, $id);
        $ente->setName($name);
        $ente->setSealId($sealId);
        $ente->setToken(self::TOKEN);

        return $ente;
    }
}
