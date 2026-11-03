<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Galat validasi relasi Transaksi <-> Hutang/Piutang/Proyeksi: pesannya aman ditampilkan ke pengguna. */
final class LinkError extends RuntimeException
{
    /** true = kemungkinan pembayaran ganda yang masih bisa dikonfirmasi pengguna (kotak "ini pembayaran terpisah"). */
    public bool $duplicate = false;
}
