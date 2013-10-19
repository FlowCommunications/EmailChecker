<?php

echo "This example is not runnable\n";
exit;

class CheckEmailsCommand extends Command
{
    protected $name = "checkemails";

    protected $description = "Check yo' emails";

    public function fire()
    {
        $users = User::get(array('id', 'email'));

        $loop = React\EventLoop\Factory::create();

        $self = $this;

        $connectionPool = new Flow\EmailChecker\ConnectionPool(
            $loop, 'flowsa.com', 'stephen', function (Flow\EmailChecker\ConnectionPool $pool) use (
            $users,
            $self
        ) {
            $user = $users->shift();

            if (!$user) {
                return false;
            }

            $pool->add($user->email,
                function (Flow\EmailChecker\MailboxUser $mailboxUser) use ($user, $self) {
                    if ($mailboxUser->exists()) {
                        $self->info($user->email . ': exists');
                    } else {
                        $self->error($user->email . ': does not exist');
                    }
                }
            );

        }, array($this, 'comment'));

        $connectionPool->run();

    }
}

