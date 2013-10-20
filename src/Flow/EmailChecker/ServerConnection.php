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

    protected $buffer = '';

    public function __construct(Connection $connection = null, $domain, $user, $logger = null)
    {
        $this->connection = $connection;
        $this->domain = $domain;
        $this->user = $user;
        $this->logger = $logger;

        $connection->on('data', array($this, 'logReceived'));

        $connection->on('data', array($this, 'buffer'));

        $connection->on('close', array($this, 'onClose'));
    }

    public function buffer($data)
    {
        $this->buffer .= $data;

        if (strpos($this->buffer, "\r\n") > -1) {
            $this->connection->emit('message', array($this->buffer));
            $this->buffer = '';
        }
    }

    public function onClose()
    {
        $this->rejectAll();
        $this->setState(ServerConnection::STATE_CLOSED);
    }

    public function rejectAll()
    {
        while ($email = $this->shift()) {
            if (!$email->isResolved()) {
                $email->setRaw('400 - Connection closed');
            }
        }
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

    private function log($data)
    {
        if (is_callable($this->logger)) {
            $data = trim($data);
            call_user_func_array($this->logger, array($data));
        }
    }

    public function getStack()
    {
        return $this->stack;
    }

    public function on($event, $callback)
    {
        $this->connection->on($event, $callback);
    }

    public function add(MailboxUser $email)
    {
        $this->stack[spl_object_hash($email)] = $email;
        $this->run();
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

        if ($email === null) {
            $this->setState(static::STATE_READY);
            $this->connection->emit('queue.empty', array($this->connection));
            return;
        }

        $this->setState(static::STATE_ASKING);

        $this->write("RCPT TO: <" . $email->getEmail() . ">\r\n")->promiseMessage()->then(function ($data) use (
                $self,
                $email
            ) {
                $email->setRaw($data);
                $self->unsetProcessing($email);
                $self->ask();
            }
        );
    }

    /**
     * @return \React\Promise\DeferredPromise
     */
    public function promiseMessage()
    {
        $deferred = new Deferred();

        $this->connection->once('message',
            function ($data) use ($deferred) {
                $deferred->resolve($data);
            }
        );

        return $deferred->promise();
    }

    public function write($str)
    {
        $this->logSent($str);

        $this->connection->write($str);

        return $this;
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
        $self = $this;

        $this->setState(static::STATE_GREETING);

        $this->promiseMessage()
            ->then(function ($data) use ($self) {
                    return $self->write("HELO " . $self->getDomain() . "\r\n")->promiseMessage();
                }
            )->then(function ($data) use ($self) {
                    return $self->write("MAIL FROM: <" . $self->getUser() . "@" . $self->getDomain() . ">\r\n"
                    )->promiseMessage();
                }
            )
            ->then(function ($data) use ($self) {
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