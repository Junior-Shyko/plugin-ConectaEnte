<?php

namespace Tests\ConectaEnte;

use ConectaEnte\Http\SendResult;
use ConectaEnte\Services\RetryPolicy;
use ConectaEnte\Vocabulary\SendOutcome;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    public function testAcceptedResponseIsSuccess()
    {
        $this->assertSame(SendOutcome::SUCCESS, RetryPolicy::outcome(SendResult::ok([]), 1, 3));
    }

    public function testServerErrorIsRetriedWhileAttemptsRemain()
    {
        $this->assertSame(SendOutcome::PENDING, RetryPolicy::outcome(SendResult::unreachable(500), 1, 3));
        $this->assertSame(SendOutcome::PENDING, RetryPolicy::outcome(SendResult::unreachable(503), 2, 3));
    }

    public function testServerErrorOnTheLastAttemptIsError()
    {
        $this->assertSame(SendOutcome::ERROR, RetryPolicy::outcome(SendResult::unreachable(500), 3, 3));
    }

    public function testConnectionFailureIsRetried()
    {
        $this->assertSame(SendOutcome::PENDING, RetryPolicy::outcome(SendResult::unreachable(0, 'Could not resolve host'), 1, 3));
    }

    /**
     * @dataProvider definitiveRefusals
     */
    public function testClientRefusalIsRejectedWithoutRetry($status)
    {
        $this->assertSame(SendOutcome::REJECTED, RetryPolicy::outcome(SendResult::rejected('recusado', $status), 1, 3));
    }

    public function definitiveRefusals()
    {
        return [[400], [401], [403], [404], [422]];
    }

    public function testSingleAttemptLimitTurnsTheFirstFailureIntoError()
    {
        $this->assertSame(SendOutcome::ERROR, RetryPolicy::outcome(SendResult::unreachable(500), 1, 1));
    }
}
