<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Db;

/** Data referensi untuk filter & formulir. */
final class Lookup
{
    public static function accounts(bool $all = false): array
    {
        return Db::all('SELECT id, code, name, kind, balance_mode, credit_limit, opening_date, is_active FROM accounts' . ($all ? '' : ' WHERE is_active = 1') . ' ORDER BY sort_order, id');
    }

    public static function categories(bool $all = false): array
    {
        return Db::all('SELECT id, name, kind, is_active FROM categories' . ($all ? '' : ' WHERE is_active = 1') . " ORDER BY FIELD(kind,'pendapatan','biaya','pajak','pembiayaan','transfer','lainnya'), name");
    }

    public static function projects(bool $all = false): array
    {
        return Db::all('SELECT id, name, is_active FROM projects' . ($all ? '' : ' WHERE is_active = 1') . ' ORDER BY name');
    }

    /** Master rekening lawan (dropdown "Bank / rekening pihak"). */
    public static function counterpartyAccounts(bool $all = false): array
    {
        return Db::all('SELECT id, label, is_active FROM counterparty_accounts' . ($all ? '' : ' WHERE is_active = 1') . ' ORDER BY label');
    }

    public const KIND_LABEL = [
        'pendapatan' => 'Pendapatan', 'biaya' => 'Biaya', 'pajak' => 'Pajak', 'pembiayaan' => 'Pembiayaan (pinjaman/hutang bank)',
        'transfer' => 'Transfer antar rekening', 'lainnya' => 'Lainnya (bon, uang muka, kas kecil, dll.)',
    ];
}
