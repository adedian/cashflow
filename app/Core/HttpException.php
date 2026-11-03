<?php
declare(strict_types=1);

namespace App\Core;

/** Kesalahan dengan kode status HTTP (mis. 404) yang aman ditampilkan ke pengguna. */
final class HttpException extends \RuntimeException
{
    public function __construct(string $message, private int $status = 404)
    {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }
}
