<?php
namespace Flow\EmailChecker;

use React\Promise\Deferred;
use React\Socket\Connection;

class ServerConnection
{
    const STATE_NULL = 'NULL_STATE';

    const STATE_GREETING = 'GREETING';

    const STATE_READY = 'READY';

    const STATE_ASKING = 'ASKING';

    const STATE_CLOSED = 'CLOSED';

    /**
     * @var Connection
     */
    protected $connection;

    protected $user;

    protected $domain;

    protected $host;

    /**
     * @var callable
     */
    protected $logger;

    protected $state = 0;

    protected $stack;

    protected $processingStack;

    protected $address;

    public function __construct(Connection $connection = null, $domain, $user, $logger = null)
    {
        $this->connection = $connection;
        $this->domain = $domain;
        $this->user = $user;
        $this->logger = $logger;
    }

    public function getStack()
    {
        return $this->stack;
    }

    public function run()
    {
        if ($this->isClosed()) {
            return;
        }

        if ($this->isAsking()) {
            return;
        } else {
            if ($this->isReady()) {
                $this->ask();
            } else {
                if (!$this->isGreeting()) {
                    $this->sayHello();
                }
            }
        }
    }

    public function isClosed()
    {
        return $this->state === static::STATE_CLOSED;
    }

    public function isAsking()
    {
        return $this->state === static::STATE_ASKING;
    }

    public function isReady()
    {
        return $this->state === static::STATE_READY;
    }

    public function ask()
    {
        $self = $this;
        $email = $this->shift();
        $connection = $this->connection;

        if ($email === null) {
            $this->setState(static::STATE_READY);
            return;
        }

        $this->setState(static::STATE_ASKING);

        $deferred = new Deferred();

        $this->write("RCPT TO: <" . $email->getEmail() . ">\r\n");

        $connection->once('data',
            function ($data) use ($deferred) {
                $deferred->resolve($data);
            }
        );

        $deferred->promise()->then(function ($data) use ($self, $email) {
                $email->setRaw($data);
                $self->unsetProcessing($email);
                $self->ask();
            }
        );
    }

    public function shift()
    {
        $email = array_shift($this->stack);

        if ($email) {
            $this->addProcessingStack($email);
        }

        return $email;
    }

    public function addProcessingStack($email)
    {
        $this->processingStack[spl_object_hash($email)] = $email;
    }

    public function setState($state)
    {
        $this->state = $state;

        $this->logState($this->state);
    }

    public function logState($data)
    {
        $data = trim($data);
        $address = $this->getAddress();

        $this->log("$address :: $data");
    }

    public function getAddress()
    {
        if (!$this->address) {
            $this->address = $this->connection->getRemoteAddress();
        }

        return $this->address;
    }

    public function log($data)
    {
        if (is_callable($this->logger)) {
            $data = trim($data);
            call_user_func_array($this->logger, array($data));
        }
    }

    public function write($str)
    {
        $this->logSent($str);

        $this->connection->write($str);
    }

    public function logSent($data)
    {
        $data = trim($data);
        $address = $this->getAddress();

        $this->log("$address -> $data");
    }

    public function unsetProcessing($email)
    {
        unset($this->processingStack[spl_object_hash($email)]);
    }

    public function isGreeting()
    {
        return $this->state === static::STATE_GREETING;
    }

    public function sayHello()
    {
        $this->setState(static::STATE_GREETING);

        $self = $this;
        $connection = $this->connection;

        $deferred = new Deferred();

        $this->connection->once('data',
            function ($data, $conn) use ($deferred) {
                $deferred->resolve(array($data, $conn));
            }
        );

        $deferred->promise()
            ->then(function ($data) use ($connection, $self) {
                    $deferred = new Deferred();

                    $self->write("HELO " . $self->getDomain() . "\r\n");
                    $connection->once('data',
                        function ($data) use ($deferred) {
                            $deferred->resolve($data);
                        }
                    );

                    return $deferred->promise();
                }
            )
            ->then(function ($data) use ($connection, $self) {
                    $deferred = new Deferred();

                    $self->write("MAIL FROM: <" . $self->getUser() . "@" . $self->getDomain() . ">\r\n");
                    $connection->once('data',
                        function ($data) use ($deferred) {
                            $deferred->resolve($data);
                        }
                    );

                    return $deferred->promise();
                }
            )
            ->then(function ($data) use ($connection, $self) {
                    $self->setState(ServerConnection::STATE_READY);
                    $self->ask();
                }
            );

    }

    /**
     * @return mixed
     */
    public function getDomain()
    {
        return $this->domain;
    }

    /**
     * @return mixed
     */
    public function getUser()
    {
        return $this->user;
    }

    public function rejectAll()
    {
        while ($email = $this->shift()) {
            if (!$email->isResolved()) {
                $email->setRaw('500 - Connection closed');
            }
        }
    }

    public function on($event, $callback)
    {
        $this->connection->on($event, $callback);
    }

    public function add($email)
    {
        $this->stack[spl_object_hash($email)] = $email;
    }

    public function count()
    {
        return count($this->stack);
    }

    public function processingCount()
    {
        return count($this->processingStack);
    }

    public function logReceived($data)
    {
        $address = $this->getAddress();

        $this->log("$address <- $data");
    }

}