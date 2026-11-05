<?php

namespace ConectaEnte\Services;

use ConectaEnte\Entities\FederativeEntity;
use ConectaEnte\Http\Client;
use ConectaEnte\Http\SendResult;
use ConectaEnte\Payload\OpportunityPayload;
use ConectaEnte\Vocabulary\SendOutcome;

class OpportunitySender
{
    private $client;
    private $payload;
    private $eligibility;
    private $live;

    public function __construct(Client $client, OpportunityPayload $payload, SendEligibility $eligibility, $live)
    {
        $this->client = $client;
        $this->payload = $payload;
        $this->eligibility = $eligibility;
        $this->live = (bool) $live;
    }

    /**
     * Reconfere a elegibilidade (o estado pode ter mudado desde o enfileiramento) e envia o edital.
     *
     * @param \MapasCulturais\Entities\Opportunity $opportunity
     */
    public function send($opportunity, FederativeEntity $entity, $attempt, $maxAttempts)
    {
        $check = $this->eligibility->check($opportunity);

        if (!$check->isEligible() || $check->getEntity()->getId() !== $entity->getId()) {
            return new SendReport(SendOutcome::ABANDONED, $check->getReason());
        }

        $payload = $this->payload->build($opportunity, $entity);

        // o modo dev monta e valida o payload, mas não chama o CultBR: nunca conta como sucesso
        if (!$this->live) {
            return new SendReport(SendOutcome::SIMULATED);
        }

        $result = $this->client->sendOpportunity($entity->getToken(), $opportunity->id, $payload);

        return new SendReport(
            RetryPolicy::outcome($result, $attempt, $maxAttempts),
            $this->messageOf($result),
            $this->parEditalIdOf($result),
            $result->getRequestId()
        );
    }

    private function messageOf(SendResult $result)
    {
        if ($result->isAccepted()) {
            return null;
        }

        if ($result->getMessage() !== null) {
            return $result->getMessage();
        }

        return $result->getStatus() ? "HTTP {$result->getStatus()}" : $result->getTransportError();
    }

    private function parEditalIdOf(SendResult $result)
    {
        $response = $result->getResponse();

        return $result->isAccepted() && isset($response['id_par_edital']) ? (string) $response['id_par_edital'] : null;
    }
}
