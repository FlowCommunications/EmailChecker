<?php
require("../vendor/autoload.php");

use Flow\EmailChecker\MailboxUser;
use Flow\EmailChecker\ServerConnection;
use React\Socket\Connection;

$loop = React\EventLoop\Factory::create();
getmxrr('gmail.com', $hosts);
$stream = stream_socket_client('tcp://' . $hosts[0] . ':25', $errNo, $errStr, 10);
$conn = new Connection($stream, $loop);

$serverConnection = new ServerConnection($conn, 'flowsa.com', 'stephen', function ($str) {
    echo $str . "\n";
});

$conn->on('queue.empty', function (Connection $conn) {
        $conn->close();
    });

$emails = array(
    'stephenfrankza@gmail.com',
    'i-dont-exist-asdf-1234@gmail.com'
);

foreach ($emails as $email) {
    $serverConnection->add(
        new MailboxUser($email, function ($user) {
            echo $user->getEmail() . ($user->exists() ? ' exists' : ' does not exist') . "\n";
        })
    );
}

$loop->run();
