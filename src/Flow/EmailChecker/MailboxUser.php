<?php
namespace Flow\EmailChecker;

class MailboxUser
{
    const EXISTS = 'EXISTS';

    const NOT_EXISTS = 'NOT_EXISTS';

    const INDETERMINATE = 'INDETERMINATE';

    protected $isResolved;

    protected $state;

    protected $email;

    protected $callback;

    protected $raw;

    protected $code;

    function __construct($email, $callback)
    {
        $this->email = $email;
        $this->callback = $callback;
    }

    public function exists()
    {
        return $this->state === static::EXISTS;
    }

    public function notExists()
    {
        return $this->state === static::NOT_EXISTS;
    }

    public function indeterminate()
    {
        return $this->state === static::INDETERMINATE;
    }

    public function isResolved()
    {
        return $this->isResolved;
    }

    /**
     * @return mixed
     */
    public function getEmail()
    {
        return $this->email;
    }

    public function setRaw($raw)
    {
        $this->raw = $raw;

        $this->resolveState($this->raw);
    }

    public function resolveState($raw)
    {
        preg_match('/^(\d{3})/', trim($raw), $matches);

        $code = isset($matches[1]) ? (int) $matches[1] : null;

        $this->code = $code;

        if ($code > 500) {
            $this->state = static::NOT_EXISTS;
        } else {
            if ($code === 250) {
                $this->state = static::EXISTS;
            } else {
                $this->state = static::INDETERMINATE;
            }
        }

        $this->callback($this);
    }

    public function callback()
    {
        call_user_func_array($this->callback, array($this));
    }


}