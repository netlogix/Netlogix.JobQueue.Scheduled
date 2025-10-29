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
 * @Flow\Proxy(false)
 * @ORM\Table(
 *     name=ScheduledJob::TABLE_NAME,
 *     indexes={
 *          @ORM\Index(name="idx_groupname", columns={"groupname", "identifier"}),
 *          @ORM\Index(name="idx_claimed", columns={"claimed", "identifier"}),
 *          @ORM\Index(name="idx_for_retrieve", columns={"claimed", "groupname", "running"}),
 *          @ORM\Index(name="idx_for_update", columns={"groupname", "claimed", "duedate", "running"})
 *     }
 * )
 */
class ScheduledJob
{
    const TABLE_NAME = 'netlogix_jobqueue_scheduled_job';

    /**
     * @var string
     * @ORM\Column(name="groupname", length=36, options={"fixed": true, "default": "default"})
     */
    protected string $groupName = 'default';

    /**
     * @var string
     * @Flow\Identity
     * @ORM\Id
     */
    protected $identifier;

    /**
     * @var DateTimeImmutable
     */
    protected $duedate;

    /**
     * @var DateTimeImmutable
     */
    protected $activity;

    /**
     * @var string
     */
    protected $queue;

    /**
     * @var JobInterface
     * @ORM\Column(type="object")
     */
    protected $job;

    /**
     * @var int
     * @ORM\Column(options={"default": 0})
     */
    protected $incarnation = 0;

    /**
     * @var string
     * @ORM\Column(length=36, options={"fixed": true, "default": ""})
     */
    protected $claimed = '';

    /**
     * @var bool
     */
    protected $running = false;

    public function __construct(
        JobInterface $job,
        string $queue,
        DateTimeImmutable $duedate,
        string $groupName,
        string $identifier = '',
        int $incarnation = 0,
        string $claimed = '',
        bool $running = false
    ) {
        $this->job = $job;
        $this->queue = $queue;
        $this->duedate = $duedate;
        $this->activity = new DateTimeImmutable();
        $this->groupName = $groupName;
        $this->identifier = $identifier ?: Algorithms::generateUUID();
        $this->incarnation = $incarnation;
        $this->claimed = $claimed;
        $this->running = $running;
    }

    public function getGroupName(): string
    {
        return $this->groupName;
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

    public function getIncarnation(): int
    {
        return $this->incarnation;
    }

    public function getClaimed(): string
    {
        return $this->claimed;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }
}
