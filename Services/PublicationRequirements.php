<?php

namespace ConectaEnte\Services;

use ConectaEnte\Metadata\CultBrMetadata as Meta;
use ConectaEnte\Vocabulary\AffirmativeAction;
use ConectaEnte\Vocabulary\AffirmativeActionGroup;
use ConectaEnte\Vocabulary\FundingSource;
use ConectaEnte\Vocabulary\RegistrationChannel;
use ConectaEnte\Vocabulary\TargetingField;
use ConectaEnte\Vocabulary\TargetingOption;
use MapasCulturais\i;
use Respect\Validation\Validator;

class PublicationRequirements
{
    const YES = 'sim';
    const NO = 'nao';
    const LEGAL_QUOTA_COUNT = 3;
    const MINIMUM_QUOTA_COUNT = 4;

    const NUMBER_VACANCIES = 'numberVacancies';
    const RULES_FILE = 'rules';

    /**
     * @param object $opportunity oportunidade (ou objeto equivalente) com os campos lidos abaixo
     *
     * @return array[] chave do campo → mensagens em PT-BR; vazio quando pode publicar
     */
    public function missingFields($opportunity)
    {
        return array_merge(
            $this->vacanciesErrors($opportunity),
            $this->totalResourceErrors($opportunity),
            $this->proponentTypeErrors($opportunity),
            $this->rulesFileErrors($opportunity),
            $this->descriptionErrors($opportunity),
            $this->registrationDateErrors($opportunity),
            $this->executionTypeErrors($opportunity),
            $this->targetingErrors($opportunity),
            $this->specificationErrors($opportunity),
            $this->fundingErrors($opportunity),
            $this->quotaErrors($opportunity),
            $this->channelErrors($opportunity),
            $this->affirmativeErrors($opportunity)
        );
    }

    private function vacanciesErrors($opportunity)
    {
        // inteiro, sem o parser monetário: "1.500" e "0.5" seriam aceitos, e o payload os enviaria truncados
        $vacancies = filter_var($opportunity->{self::NUMBER_VACANCIES}, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($vacancies !== false) {
            return [];
        }

        return [self::NUMBER_VACANCIES => [i::__('O campo "Total de vagas" é obrigatório e deve ser um número inteiro maior que zero.')]];
    }

    private function totalResourceErrors($opportunity)
    {
        if ($this->isPositiveNumber($opportunity->{Meta::TOTAL_RESOURCE})) {
            return [];
        }

        return [Meta::TOTAL_RESOURCE => [i::__('O campo "Valor total do edital" é obrigatório e deve ser maior que zero.')]];
    }

    private function proponentTypeErrors($opportunity)
    {
        return $this->choiceErrors($opportunity, Meta::PROPONENT_TYPES);
    }

    private function rulesFileErrors($opportunity)
    {
        if ($opportunity->getFile(self::RULES_FILE)) {
            return [];
        }

        return [self::RULES_FILE => [i::__('O campo "Adicionar regulamento" é obrigatório.')]];
    }

    private function descriptionErrors($opportunity)
    {
        $description = trim((string) $opportunity->longDescription) !== '' ? $opportunity->longDescription : $opportunity->shortDescription;

        if (trim((string) $description) !== '') {
            return [];
        }

        return ['shortDescription' => [i::__('O campo "Descrição curta" é obrigatório.')]];
    }

    private function registrationDateErrors($opportunity)
    {
        $errors = [];

        if (!$opportunity->registrationFrom) {
            $errors['registrationFrom'] = [i::__('O campo "Data inicial das inscrições" é obrigatório.')];
        }

        if (!$opportunity->registrationTo) {
            $errors['registrationTo'] = [i::__('O campo "Data final das inscrições" é obrigatório.')];
        }

        return $errors;
    }

    private function executionTypeErrors($opportunity)
    {
        return $this->choiceErrors($opportunity, Meta::EXECUTION_TYPE);
    }

    private function targetingErrors($opportunity)
    {
        $errors = [];

        foreach ([Meta::SEGMENTS, Meta::CULTURAL_STAGES, Meta::THEMATIC_AGENDAS, Meta::PRIORITY_TERRITORIES] as $key) {
            $errors += $this->choiceErrors($opportunity, $key);
            $errors += $this->notTargetedConflict($opportunity, $key);
        }

        return $errors;
    }

    private function notTargetedConflict($opportunity, $key)
    {
        $values = Meta::values($opportunity->$key);

        if (!in_array(TargetingOption::NOT_TARGETED, $values, true) || count($values) < 2) {
            return [];
        }

        return [$key => [sprintf(i::__('A opção "%s" de "%s" não pode ser combinada com outras opções.'), TargetingOption::text(TargetingOption::NOT_TARGETED, TargetingField::fromMetadataKey($key)), Meta::definitions()[$key]['label'])]];
    }

    private function specificationErrors($opportunity)
    {
        $values = [];

        foreach ([Meta::SEGMENTS, Meta::SEGMENTS_OTHER, Meta::CULTURAL_STAGES, Meta::CULTURAL_STAGES_OTHER, Meta::THEMATIC_AGENDAS, Meta::THEMATIC_AGENDAS_OTHER] as $key) {
            $values[$key] = $opportunity->$key;
        }

        $labels = Meta::definitions();
        $errors = [];

        foreach (Meta::missingSpecifications($values) as $key) {
            $errors[$key] = [sprintf(i::__('O campo "%s" é obrigatório quando a opção de especificar é selecionada.'), $labels[$key]['label'])];
        }

        return $errors;
    }

    private function fundingErrors($opportunity)
    {
        $key = Meta::FUNDING_SOURCES;
        $block = $this->block($opportunity->$key);
        $answer = isset($block['houveUtilizacao']) ? $block['houveUtilizacao'] : null;

        if ($answer === self::NO) {
            return [];
        }

        if ($answer !== self::YES) {
            return [$key => [i::__('O campo "Houve utilização de recursos de outras fontes?" é obrigatório.')]];
        }

        $others = $this->listOf($block, FundingSource::OTHER_SOURCES);
        $hasAmountSource = false;

        foreach (FundingSource::cases() as $source) {
            if ($source !== FundingSource::OTHER_SOURCES && isset($block[$source]) && $block[$source] !== null) {
                $hasAmountSource = true;
            }
        }

        if (!$hasAmountSource && !$others) {
            return [$key => [i::__('Em "Houve utilização de recursos de outras fontes?", selecione pelo menos uma fonte de recurso.')]];
        }

        foreach ($others as $source) {
            if (trim((string) (isset($source['nomeFonte']) ? $source['nomeFonte'] : '')) === '') {
                return [$key => [i::__('Em "Houve utilização de recursos de outras fontes?", preencha o nome de todas as fontes.')]];
            }
        }

        return [];
    }

    private function quotaErrors($opportunity)
    {
        $key = Meta::QUOTA_RESERVATION;
        $quotas = array_values(array_map(function ($quota) {
            return is_array($quota) ? $quota : [];
        }, $this->block($opportunity->$key)));

        $message = $this->quotaMessage($quotas, (int) $opportunity->{self::NUMBER_VACANCIES});

        return $message === null ? [] : [$key => [$message]];
    }

    private function quotaMessage(array $quotas, $vacancies)
    {
        $incomplete = i::__('Em "Reserva de vagas (cotas)", configure todas as cotas ou marque como Não aplicável.');

        if (count($quotas) < self::MINIMUM_QUOTA_COUNT) {
            return $incomplete;
        }

        $legal = array_slice($quotas, 0, self::LEGAL_QUOTA_COUNT);

        $allNotApplicable = true;

        foreach ($legal as $quota) {
            if (empty($quota['naoAplicavel'])) {
                $allNotApplicable = false;
            }
        }

        if ($allNotApplicable) {
            return null;
        }

        foreach ($legal as $quota) {
            $slots = $this->numberOrNull(isset($quota['vagas']) ? $quota['vagas'] : null);
            $amount = $this->numberOrNull(isset($quota['valorDestinado']) ? $quota['valorDestinado'] : null);

            if (!empty($quota['naoAplicavel']) && ($slots > 0 || $amount > 0)) {
                return i::__('Em "Reserva de vagas (cotas)", uma cota marcada como Não aplicável deve ter número de vagas e valor destinado iguais a zero.');
            }

            if (empty($quota['naoAplicavel']) && ($slots === null || $amount === null || $slots < 0 || $amount < 0)) {
                return $incomplete;
            }
        }

        $sum = 0;

        foreach ($quotas as $quota) {
            $slots = $this->numberOrNull(isset($quota['vagas']) ? $quota['vagas'] : null);

            if ($slots !== null && $slots < 0) {
                return $incomplete;
            }

            if ($slots !== null && $slots != floor($slots)) {
                return i::__('Em "Reserva de vagas (cotas)", o número de vagas de cada cota deve ser inteiro.');
            }

            $sum += (int) $slots;
        }

        if ($vacancies >= 1 && $sum !== $vacancies) {
            return sprintf(i::__('Em "Reserva de vagas (cotas)", a soma das vagas (%d) deve ser igual ao Total de vagas (%d).'), $sum, $vacancies);
        }

        return null;
    }

    private function channelErrors($opportunity)
    {
        $key = Meta::REGISTRATION_CHANNELS;
        $block = $this->block($opportunity->$key);
        $answer = isset($block['previstasNoEdital']) ? $block['previstasNoEdital'] : null;

        if ($answer === self::NO) {
            return [];
        }

        if ($answer !== self::YES) {
            return [$key => [i::__('O campo "Formas de inscrição previstas no edital" é obrigatório.')]];
        }

        $channels = isset($block['formas']) && is_array($block['formas']) ? $block['formas'] : [];

        if (!$channels) {
            return [$key => [i::__('Em "Formas de inscrição previstas no edital", selecione pelo menos uma forma.')]];
        }

        $messages = [];

        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                $messages[] = i::__('Em "Formas de inscrição previstas no edital", cada forma marcada deve ter tipo e descrição.');
                continue;
            }

            $type = (string) (isset($channel['tipo']) ? $channel['tipo'] : '');
            $description = trim((string) (isset($channel['descricao']) ? $channel['descricao'] : ''));

            if ($type === '') {
                $messages[] = i::__('Em "Formas de inscrição previstas no edital", escolha o tipo de cada forma marcada.');
            } elseif (RegistrationChannel::tryFrom($type) === null) {
                $messages[] = sprintf(i::__('A forma de inscrição "%s" não tem correspondente no CultBR.'), $type);
            }

            if ($description === '') {
                $messages[] = i::__('Em "Formas de inscrição previstas no edital", preencha a descrição de cada forma marcada.');
            } elseif ($type === RegistrationChannel::EMAIL && !Validator::email()->validate($description)) {
                $messages[] = i::__('Em "Formas de inscrição previstas no edital", informe um e-mail válido.');
            }
        }

        return $this->keyed($key, $messages);
    }

    private function affirmativeErrors($opportunity)
    {
        $key = Meta::AFFIRMATIVE_ACTIONS;
        $block = $this->block($opportunity->$key);
        $options = $this->strings(isset($block['opcoes']) ? $block['opcoes'] : []);
        $title = 'Em "Outras modalidades de ações afirmativas", ';
        $messages = [];

        $known = array_filter($options, function ($option) {
            return AffirmativeAction::tryFrom($option) !== null;
        });

        if (!$known) {
            $messages[] = i::__($title . 'selecione pelo menos uma opção.');
        }

        if (count(array_unique($options)) > 1 && array_filter($options, [AffirmativeAction::class, 'isExclusive'])) {
            $messages[] = i::__($title . 'a opção "Não são previstas outras ações afirmativas" não pode ser combinada com outras opções.');
        }

        foreach ($options as $option) {
            if (AffirmativeAction::tryFrom($option) === null) {
                $messages[] = sprintf(i::__('A ação afirmativa "%s" não tem correspondente no CultBR.'), $option);
            }
        }

        foreach ($known as $option) {
            if (AffirmativeAction::hasGroups($option)) {
                $messages = array_merge($messages, $this->groupMessages($block, $option));
            }
        }

        if (in_array(AffirmativeAction::OTHER_LEGISLATION, $options, true)) {
            $messages = array_merge($messages, $this->otherLegislationMessages($block, $title));
        }

        return $this->keyed($key, $messages);
    }

    private function groupMessages(array $block, $option)
    {
        $groups = $this->strings(isset($block[$option]) ? $block[$option] : []);
        $valid = array_filter($groups, function ($group) {
            return AffirmativeActionGroup::tryFrom($group) !== null;
        });
        $messages = [];

        if (!$valid) {
            $messages[] = sprintf(i::__('Em "Outras modalidades de ações afirmativas", selecione pelo menos uma subcategoria de "%s".'), AffirmativeAction::text($option));
        }

        foreach ($groups as $group) {
            if (AffirmativeActionGroup::tryFrom($group) === null) {
                $messages[] = sprintf(i::__('O grupo "%s" de "%s" não tem correspondente no CultBR.'), $group, AffirmativeAction::text($option));
            }
        }

        return $messages;
    }

    private function otherLegislationMessages(array $block, $title)
    {
        $description = isset($block['outra_legislacao_descricao']) && is_string($block['outra_legislacao_descricao']) ? trim($block['outra_legislacao_descricao']) : '';
        $max = AffirmativeAction::otherLegislationMaxLength();

        if ($description === '') {
            return [i::__($title . 'preencha a descrição da outra ação afirmativa.')];
        }

        if (mb_strlen($description) > $max) {
            return [sprintf(i::__('Em "Outras modalidades de ações afirmativas", a descrição da outra ação afirmativa deve ter no máximo %d caracteres.'), $max)];
        }

        return [];
    }

    private function choiceErrors($opportunity, $key)
    {
        $definition = Meta::definitions()[$key];
        $label = $definition['label'];
        $values = Meta::values($opportunity->$key);
        $messages = [];
        $known = array_filter($values, function ($value) use ($definition) {
            return array_key_exists($value, $definition['options']);
        });

        if (!$known) {
            $messages[] = sprintf(i::__('O campo "%s" é obrigatório.'), $label);
        }

        foreach ($values as $value) {
            if (!array_key_exists($value, $definition['options'])) {
                $messages[] = sprintf(i::__('A opção "%s" de "%s" não tem correspondente no CultBR.'), $value, $label);
            }
        }

        return $this->keyed($key, $messages);
    }

    private function keyed($key, array $messages)
    {
        return $messages ? [$key => array_values(array_unique($messages))] : [];
    }

    private function block($value)
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        } elseif (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        return is_array($value) ? $value : [];
    }

    private function listOf(array $block, $key)
    {
        return isset($block[$key]) && is_array($block[$key]) ? array_filter($block[$key], 'is_array') : [];
    }

    private function strings($values)
    {
        return array_values(array_filter((array) $values, 'is_string'));
    }

    private function numberOrNull($value)
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function isPositiveNumber($value)
    {
        $decimal = Money::toDecimal($value);

        return $decimal !== null && (float) $decimal > 0;
    }
}
