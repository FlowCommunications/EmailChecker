<?php
namespace Flow\EmailChecker;

use InvalidArgumentException;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;

class ConnectionPool
{
    /**
     * @var callable
     */
    protected $logger;

    protected $fromDomain;

    protected $fromUser;

    /**
     * @var \React\EventLoop\LoopInterface
     */
    protected $loop;

    /**
     * @var ServerConnection[]
     */
    protected $connections = array();

    protected $hostCache = array();

    protected $lastError = array();

    protected $concurrency = 10;

    protected $keepAlive = 60;

    /**
     * @var callable
     */
    protected $queuer;

    function __construct(LoopInterface $loop, $fromDomain, $fromUser, $queuer, $logger = null)
    {
        $this->loop = $loop;
        $this->fromDomain = $fromDomain;
        $this->fromUser = $fromUser;
        $this->queuer = $queuer;
        $this->logger = $logger;
    }

    /**
     * @param int $stayAlive
     */
    public function setStayAlive($keepAlive)
    {
        $this->keepAlive = $keepAlive;
    }

    public function setConcurrency($num)
    {
        $this->concurrency = $num;
    }

    public function tick()
    {
        $count = $this->count();

        if ($this->count() < $this->concurrency) {
            $result = call_user_func_array($this->queuer, array($this));

            if ($result === false) {
                $this->drain();
            }
        }

        foreach ($this->connections as $key => $connection) {
            if (time() - $connection->lastAlive() > $this->keepAlive) {
                $this->log($key . ' :: Server closed due to inactivity');
                $connection->close();
                echo ">> ".($connection->isClosed() ? 'closed' : 'still open');
            }

            if ($connection->isClosed()) {
                $this->unsetConnectionByDomain($connection->getDomain());
            }
        }

        $this->log('Queue: ' . $count);
        $this->log('Connections: ' . $this->connectionsCount());
    }

    public function count()
    {
        $total = 0;

        foreach ($this->connections as $connection) {
            $total += $connection->count();
        }

        return $total;
    }

    public function drain()
    {
        if ($this->count() === 0 && $this->processingCount() === 0) {
            $this->loop->stop();
        }
    }

    public function processingCount()
    {
        $total = 0;

        foreach ($this->connections as $connection) {
            $total += $connection->processingCount();
        }

        return $total;
    }

    public function log($data)
    {
        if ($this->logger) {
            $data = trim($data);
            call_user_func_array($this->logger, array($data));
        }
    }

    public function unsetConnectionByDomain($domain)
    {
        echo "unsert by domain: $domain\n";
        unset($this->connections[$domain]);
    }

    public function connectionsCount()
    {
        return count($this->connections);
    }

    public function run()
    {
        $this->loop->addPeriodicTimer(1, array($this, 'tick'));

        $this->loop->run();
    }

    public function add($emailAddress, $callback)
    {
        $mailboxUser = new MailboxUser($emailAddress, $callback);

        $parts = explode('@', $emailAddress);
        $domain = isset($parts[1]) ? $parts[1] : null;
        $user = isset($parts[0]) ? $parts[0] : null;

        if (!$domain && $user) {
            throw new InvalidArgumentException('Invalid email address "' . $emailAddress . '" provided');
        }

        $connection = $this->resolveConnection($domain);

        $connection->add($mailboxUser);
    }

    public function resolveConnection($domain)
    {
        if (!isset($this->hostCache[$domain])) {
            getmxrr($domain, $hosts, $weights);
            $this->hostCache[$domain] = array_unique($hosts);
        }

        $hosts = $this->hostCache[$domain];

        if (isset($this->connections[$domain])) {

            $serverConnection = $this->connections[$domain];

        } else {

            $stream = null;
            $host = null;

            foreach ($hosts as & $host) {

                $stream = @ stream_socket_client('tcp://' . $host . ':25', $errNo, $errStr, 10);

                if (!$stream) {
                    $this->log('Failed to connect: ' . $host);
                } else {
                    $this->log('Connected to: ' . $host);
                }

                if ($stream) {
                    break;
                }
            }

            if (!$stream) {
                $serverConnection = new MockConnection(null, $domain, $this->fromDomain, $this->fromUser, $this->logger);
            } else {
                $conn = new Connection($stream, $this->loop);

                $serverConnection = new ServerConnection($conn, $domain, $this->fromDomain, $this->fromUser, $this->logger);

            }

            $this->connections[$domain] = $serverConnection;

        }

        return $serverConnection;
    }

    public function unsetConnection($emailConnection)
    {
        foreach ($this->connections as $k => $connection) {
            if ($connection === $emailConnection) {
                unset($this->connections[$k]);
            }
        }
    }

}
