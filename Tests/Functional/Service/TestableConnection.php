<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Tests\Functional\Service;

use Doctrine\DBAL\Connection as DBALConnection;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Log\ThrowableStorageInterface;
use Netlogix\JobQueue\Scheduled\Service\Connection;

/**
 * Test double for the scheduler's Connection service. It lets tests inject a
 * mocked DBAL connection and throwable storage, exposes the otherwise
 * protected retry method, and disables the backoff sleep so retry behaviour can
 * be exercised deterministically and without wall-clock delays.
 *
 * Proxy(false) keeps Flow's dependency injection from overwriting the injected
 * mocks (via injectEntityManager()/injectThrowableStorage()) during construction.
 */
#[Flow\Proxy(false)]
class TestableConnection extends Connection
{
    public const MAX_RETRIES = 5;

    public function __construct(DBALConnection $dbal, ThrowableStorageInterface $throwableStorage)
    {
        $this->dbal = $dbal;
        $this->throwableStorage = $throwableStorage;
        $this->retryInterval = 0.0;
        $this->maxRetries = self::MAX_RETRIES;
    }

    public function run(callable $dbalInteraction, ?callable $logContext = null)
    {
        return $this->withAutoReconnectAndRetry($dbalInteraction, $logContext);
    }
}
