<?php

namespace Netlogix\JobQueue\Scheduled\Service;

class MySQLJobStatusService extends JobStatusService {

    protected function fetchOne(string $query, array $params = [], array $types = []) {
        return $this->scheduler->getConnection()->fetchOneReadUncommited($query, $params, $types);
    }

}
