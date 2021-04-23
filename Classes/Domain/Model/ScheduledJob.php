<?php
declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain\Model;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Flowpack\JobQueue\Common\Job\JobInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Utility\Algorithms;

/**
 * @Flow\Entity
 * @ORM\Table(name=ScheduledJob::TABLE_NAME)
 */
class ScheduledJob
{
    const TABLE_NAME = 'netlogix_jobqueue_scheduled_job';

    /**
     * @var string
     * @ORM\Id
     * @Flow\Identity
     */
    protected $identifier;

    /**
     * @var DateTimeImmutable
     */
    protected $duedate;

    /**
     * @var string
     */
    protected $queue;

    /**
     * @var JobInterface
     * @ORM\Column(type="object")
     */
    protected $job;

    public function __construct(
        JobInterface $job,
        string $queue,
        DateTimeImmutable $duedate,
        ?string $identifier = null
    ) {
        $this->job = $job;
        $this->queue = $queue;
        $this->duedate = $duedate;
        $this->identifier = $identifier ?: Algorithms::generateUUID();
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function getJob(): JobInterface
    {
        return $this->job;
    }

    public function getQueueName(): string
    {
        return $this->queue;
    }

    public function getDuedate(): DateTimeImmutable
    {
        return $this->duedate;
    }
}
