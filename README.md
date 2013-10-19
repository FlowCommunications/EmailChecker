EmailChecker
============

**EmailChecker** connects to mail servers over SMTP and asks them to verify email addresses. The library uses ReactPHP's event-driven IO layer to handle all the socket communication.

**Key features:**

- *N* connections are opened concurrently and handled asyncronously thanks to ReactPHP.
- Connections are pooled and kept alive so that multiple requests to the same domain are handled efficiently.

**Caveats:**

- SMTP servers respond with any old random response and can't be trusted especially when requesting a mailbox's RCPT (though this approach is better than nothing).
- MX records are resolved using `getmxrr()` and therefore that operation *is* blocking.

**To do:**

- Implement `React/DNS` when is supports MX record resolution.
- Unit test all of the things.

Example:
-------

```
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
        return false; // Returning false will cause the connection pool to drain and eventually die
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
```