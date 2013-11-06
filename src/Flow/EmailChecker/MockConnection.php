<?php
namespace Flow\EmailChecker;


class MockConnection extends ServerConnection
{

    public function run()
    {
        $this->rejectAll();
    }

    public function close()
    {
        $this->onClose();
    }
    
    public function getAddress()
    {
        return '0.0.0.0';
    }

}