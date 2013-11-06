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

}