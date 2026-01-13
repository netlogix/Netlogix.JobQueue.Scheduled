<?php

declare(strict_types=1);

namespace Netlogix\JobQueue\Scheduled\Domain\Model;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Flowpack\JobQueue\Common\Job\JobInterface;
use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Utility\Algorithms;

use function fopen;
use function fputs;
use function is_object;
use function is_resource;
use function is_string;
use function serialize;
use function stream_get_contents;
use function unserialize;

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
     * @var resource
     * @ORM\Column(type="blob")
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
     * @var int
     * @ORM\Column(type="smallint")
     */
    protected $running = 0;

    /**
     * @param resource $job
     * @param string $queue
     * @param DateTimeImmutable $duedate
     * @param string $groupName
     * @param string $identifier
     * @param int $incarnation
     * @param string $claimed
     * @param int $running
     */
    protected function __construct(
        $job,
        string $queue,
        DateTimeImmutable $duedate,
        string $groupName,
        string $identifier,
        int $incarnation,
        string $claimed,
        int $running
    ) {
        $this->job = self::convertToSerializedJob($job);
        $this->queue = $queue;
        $this->duedate = $duedate;
        $this->activity = new DateTimeImmutable();
        $this->groupName = $groupName;
        $this->identifier = $identifier;
        $this->incarnation = $incarnation;
        $this->claimed = $claimed;
        $this->running = $running;
    }

    public static function createNew(
        JobInterface $job,
        string $queue,
        DateTimeImmutable $duedate,
        string $groupName,
        ?string $identifier = null
    ): static {
        return static::createInternal(
            job: serialize($job),
            queue: $queue,
            duedate: $duedate,
            groupName: $groupName,
            identifier: $identifier ?? Algorithms::generateUUID(),
            incarnation: 0,
            claimed: '',
            running: 0
        );
    }

    /**
     * @param string|resource|JobInterface $job
     * @param string $queue
     * @param DateTimeImmutable $duedate
     * @param string $groupName
     * @param string $identifier
     * @param int $incarnation
     * @param string $claimed
     * @param int|bool $running
     * @return static
     * @internal
     */
    public static function createInternal(
        mixed $job,
        string $queue,
        DateTimeImmutable $duedate,
        string $groupName,
        string $identifier,
        int $incarnation,
        string $claimed,
        int|bool $running
    ): static {
        return new static(
            $job,
            $queue,
            $duedate,
            $groupName,
            $identifier,
            $incarnation,
            $claimed,
            // FIXME: This should probably be int only, but allowing bool avoids rewriting all tests
            is_bool($running) ? ($running ? 1 : 0) : $running
        );
    }

    public function initializeObject() {
        $this->claimed = trim($this->claimed);
    }

    /**
     * @param string | resource | JobInterface $job
     * @return resource
     */
    protected static function convertToSerializedJob(mixed $job)
    {
        if (is_resource($job)) {
            return $job;
        }

        if(is_object($job) && $job instanceof JobInterface) {
            $job = serialize($job);
        }

        if (is_string($job)) {
            $jobMemory = fopen('php://memory', 'r+');
            fputs($jobMemory, $job);
            return $jobMemory;
        }

        throw new InvalidArgumentException('The $job parameter must be of type string, resource or JobInterface.', 1761847348);
    }

    public function forRescheduling(
        DateTimeImmutable $duedate,
    ): static
    {
        return ScheduledJob::createInternal(
            job: $this->job,
            queue: $this->queue,
            duedate: $duedate,
            groupName: $this->groupName,
            identifier: $this->identifier,
            incarnation: $this->incarnation + 1,
            claimed: '',
            running: $this->running
        );
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
        return unserialize(stream_get_contents($this->job, null, 0));
    }

    public function getSerializedJob(): string
    {
        return stream_get_contents($this->job, null, 0);
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

    public function getRunning(): int
    {
        return $this->running;
    }
}
