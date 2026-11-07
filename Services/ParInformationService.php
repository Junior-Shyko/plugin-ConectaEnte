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
    const CACHE_PREFIX = 'conectaente:par-information:';
    const FAILURE_SUFFIX = ':failure';
    const FAILURE_TTL = 300;

    private $client;
    private $cache;
    private $ttl;
    private $identity;
    private $log;

    /**
     * @param object   $cache    Doctrine CacheProvider (contains, fetch, save, delete)
     * @param string   $identity modo e host da configuração: outro ambiente nunca lê a entrada deste
     * @param callable $log      recebe a mensagem de cada aviso; sem ele, nada é registrado
     */
    public function __construct(Client $client, $cache, $ttl, $identity, callable $log = null)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->ttl = max(60, (int) $ttl);
        $this->identity = (string) $identity;
        $this->log = $log ?: function () {
        };
    }

    /**
     * Chave por ente, configuração e credencial. Trocar o token muda a chave, então uma busca que terminou
     * depois da troca grava numa entrada que a tela nunca lê; o token entra só como hash.
     */
    public function cacheKey(FederativeEntity $entity)
    {
        return self::CACHE_PREFIX . sha1(implode('|', [
            $this->identity,
            $entity->getId(),
            $entity->getCnpj(),
            $entity->getToken(),
        ]));
    }

    /**
     * Só o que está no cache. Uma tree definitiva tem prioridade; depois, a última falha; sem nada, `pending`.
     * Quem enfileira a busca é o chamador.
     */
    public function cached(FederativeEntity $entity)
    {
        $key = $this->cacheKey($entity);

        foreach ([$key, $key . self::FAILURE_SUFFIX] as $candidate) {
            if (!$this->cache->contains($candidate)) {
                continue;
            }

            $value = $this->cache->fetch($candidate);

            if ($value instanceof ParInformationResult) {
                return $value;
            }
        }

        return ParInformationResult::pending();
    }

    /**
     * Consulta a API e grava o resultado. Uma resposta definitiva vai para o cache; uma falha vai para uma
     * entrada de vida curta, para a tela dizer que está indisponível e a próxima busca tentar de novo.
     */
    public function fetch(FederativeEntity $entity)
    {
        $key = $this->cacheKey($entity);
        $result = $this->client->getParInformation($entity->getToken(), $entity->getCnpj());

        if ($result->getOtherCnpjs()) {
            $this->report(sprintf(
                'ConectaEnte: o token do ente %d devolveu árvores de outros CNPJs (%s); a do CNPJ do ente é a única usada.',
                $entity->getId(),
                implode(', ', array_unique($result->getOtherCnpjs()))
            ));
        }

        if ($result->isDefinitive()) {
            $this->cache->delete($key . self::FAILURE_SUFFIX);

            if ($this->cache->save($key, $result, $this->ttl) === false) {
                $this->report("ConectaEnte: a árvore do PAR do ente {$entity->getId()} foi buscada, mas não pôde ser gravada no cache.");
            }
        } else {
            $this->cache->save($key . self::FAILURE_SUFFIX, $result, self::FAILURE_TTL);
            $this->report(sprintf('ConectaEnte: árvore do PAR do ente %d não atualizada: %s', $entity->getId(), $result->getMessage()));
        }

        return $result;
    }

    /** Descarta o que estava no cache do ente: usado quando o token muda. */
    public function forget(FederativeEntity $entity)
    {
        $key = $this->cacheKey($entity);

        $this->cache->delete($key);
        $this->cache->delete($key . self::FAILURE_SUFFIX);
    }

    private function report($message)
    {
        call_user_func($this->log, $message);
    }
}
