<?php

namespace ConectaEnte\Services;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Http\Client;
use ConectaEnte\Http\ParInformationResult;

/**
 * A árvore do PAR por ente federado, sempre do cache na requisição do usuário. A API só é chamada pelos
 * jobs (ver Jobs/ParInformationFetchJob), nunca na tela.
 *
 * O cache usa `mscache` (namespace fixo) e não `cache`: o job grava fora de um subsite e a tela lê de dentro
 * de um, e com o `cache` comum os dois não enxergariam a mesma entrada.
 */
class ParInformationService
{
    const CACHE_PREFIX = 'conectaente:par-information:federative-entity:';

    private $client;
    private $cache;
    private $ttl;
    private $log;

    /**
     * @param object   $cache Doctrine CacheProvider (contains, fetch, save, delete)
     * @param callable $log   recebe a mensagem de cada aviso; sem ele, nada é registrado
     */
    public function __construct(Client $client, $cache, $ttl, callable $log = null)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->ttl = max(60, (int) $ttl);
        $this->log = $log ?: function () {
        };
    }

    public static function cacheKey(FederativeEntity $entity)
    {
        return self::CACHE_PREFIX . $entity->getId();
    }

    /** Só o que está no cache. Sem entrada, `pending`: a tela avisa, e quem enfileira a busca é o chamador. */
    public function cached(FederativeEntity $entity)
    {
        $key = self::cacheKey($entity);

        if (!$this->cache->contains($key)) {
            return ParInformationResult::pending();
        }

        $value = $this->cache->fetch($key);

        return $value instanceof ParInformationResult ? $value : ParInformationResult::pending();
    }

    /**
     * Consulta a API e grava o resultado definitivo. Falha não é gravada, para a próxima busca tentar de novo.
     */
    public function fetch(FederativeEntity $entity)
    {
        $result = $this->client->getParInformation($entity->getToken(), $entity->getCnpj());

        if ($result->getOtherCnpjs()) {
            $this->report(sprintf(
                'ConectaEnte: o token do ente %d devolveu árvores de outros CNPJs (%s); só a do CNPJ do ente foi usada.',
                $entity->getId(),
                implode(', ', array_unique($result->getOtherCnpjs()))
            ));
        }

        if ($result->isDefinitive()) {
            $this->cache->save(self::cacheKey($entity), $result, $this->ttl);
        } else {
            $this->report(sprintf('ConectaEnte: árvore do PAR do ente %d não atualizada: %s', $entity->getId(), $result->getMessage()));
        }

        return $result;
    }

    /** Descarta o que estava no cache: usado quando o token muda, porque a árvore pode ser de outro ente. */
    public function forget(FederativeEntity $entity)
    {
        $this->cache->delete(self::cacheKey($entity));
    }

    private function report($message)
    {
        call_user_func($this->log, $message);
    }
}
