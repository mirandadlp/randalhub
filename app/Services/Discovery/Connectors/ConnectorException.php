<?php

namespace App\Services\Discovery\Connectors;

class ConnectorException extends \RuntimeException
{
    /**
     * @param  string  $kind  http | parse | robots | blocked | config
     * @param  bool  $permanent  true when retrying will not help (e.g. 404 board, robots disallow)
     */
    public function __construct(string $message, public string $kind = 'http', public bool $permanent = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
