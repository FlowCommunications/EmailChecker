<?php
require("../vendor/autoload.php");

use Flow\EmailChecker\ConnectionPool;
use Flow\EmailChecker\MailboxUser;

$loop = React\EventLoop\Factory::create();

$emails = array(
    'stephen@flowsa.com',
    'i-dont-exist-asdf-1234@gmail.com'
);

$connectionPool = new ConnectionPool($loop, 'flowsa.com', 'stephen', function (ConnectionPool $pool) use (& $emails) {
    $email = array_shift($emails);

    if (!$email) {
        return false; // Returning false will cause the connection pool to drain and eventually stop
    }

    $pool->add(
        $email,
        function (MailboxUser $email) { // Bind in a closure for the callback that occurs after an email is resolved
            echo $email->getEmail() . ($email->exists() ? ' exists' : ' does not exist') . "\n";
        }
    );

}, function ($str) {
    echo "$str\n";
});

$connectionPool->setConcurrency(10);
$connectionPool->run();
