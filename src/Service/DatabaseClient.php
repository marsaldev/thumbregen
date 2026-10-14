<?php
/**
 * @author Marco Salvatore (marsaldev)
 * @license AFL-3.0
 */

namespace Marsaldev\Module\ThumbRegen\Service;

use Db;

/**
 * Thin wrapper around the legacy Db layer that survives "MySQL server has gone away"
 * on very long runs: on a connection error it reconnects and retries the query.
 *
 * Read-only by design: the module never writes to the database.
 */
class DatabaseClient
{
    const MAX_RETRIES = 3;

    /**
     * MySQL client errors meaning the connection is gone (server gone away, lost connection,
     * can't connect) plus the SQLSTATE / messages PDO uses for the same situations.
     */
    const CONNECTION_ERROR_PATTERN = '/gone away|lost connection|2002|2006|2013|connection refused|broken pipe|error while sending|server closed the connection|no connection to the server/i';

    /** @var int */
    private $reconnections = 0;

    /**
     * @param string $sql
     *
     * @return array
     */
    public function executeS($sql)
    {
        $result = $this->run(function (Db $db) use ($sql) {
            return $db->executeS($sql, true, false);
        });

        return is_array($result) ? $result : [];
    }

    /**
     * @param string $sql
     *
     * @return array|null
     */
    public function getRow($sql)
    {
        $result = $this->run(function (Db $db) use ($sql) {
            return $db->getRow($sql, false);
        });

        return is_array($result) ? $result : null;
    }

    /**
     * @param string $sql
     *
     * @return string|false|null
     */
    public function getValue($sql)
    {
        return $this->run(function (Db $db) use ($sql) {
            return $db->getValue($sql, false);
        });
    }

    /**
     * Cheap health check run before each batch, so that code we don't control
     * (ObjectModel, Configuration, hooks) gets a live connection too.
     */
    public function ensureConnection()
    {
        $this->getValue('SELECT 1');
    }

    /**
     * @return int
     */
    public function getReconnections()
    {
        return $this->reconnections;
    }

    /**
     * @param callable $query
     *
     * @return mixed
     */
    private function run(callable $query)
    {
        $attempt = 0;

        while (true) {
            $db = Db::getInstance();
            try {
                $result = $query($db);
                if (false === $result && $this->isConnectionErrorCode($db)) {
                    throw new \RuntimeException('MySQL server has gone away (error ' . $db->getNumberError() . ')');
                }

                return $result;
            } catch (\Throwable $e) {
                if ($attempt >= self::MAX_RETRIES || !$this->isConnectionError($e)) {
                    throw $e;
                }
                ++$attempt;
                $this->reconnect($attempt);
            }
        }
    }

    /**
     * Re-opens the legacy Db connection after code we don't control failed with a connection error,
     * retrying with backoff while the server is unreachable.
     *
     * @throws \Throwable when the database is still unreachable after MAX_RETRIES attempts
     */
    public function recover()
    {
        for ($attempt = 1; $attempt < self::MAX_RETRIES; ++$attempt) {
            try {
                $this->reconnect($attempt);

                return;
            } catch (\Throwable $e) {
                // Server still unreachable, wait longer and try again.
            }
        }
        $this->reconnect(self::MAX_RETRIES);
    }

    /**
     * @param int $attempt
     */
    private function reconnect($attempt)
    {
        // Exponential backoff: 1s, 2s, 4s...
        sleep((int) pow(2, $attempt - 1));

        $db = Db::getInstance();
        try {
            $db->disconnect();
        } catch (\Throwable $e) {
            // The link may already be dead, nothing to close.
        }
        $db->connect();
        ++$this->reconnections;
    }

    /**
     * @param Db $db
     *
     * @return bool
     */
    private function isConnectionErrorCode(Db $db)
    {
        try {
            return in_array((int) $db->getNumberError(), [2002, 2006, 2013], true);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * @param \Throwable $e
     *
     * @return bool
     */
    public function isConnectionError(\Throwable $e)
    {
        do {
            if (preg_match(self::CONNECTION_ERROR_PATTERN, $e->getMessage())) {
                return true;
            }
            $e = $e->getPrevious();
        } while (null !== $e);

        return false;
    }
}
