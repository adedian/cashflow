<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use RuntimeException;

/**
 * Logika bisnis transaksi: validasi server, penyimpanan atomik (transaksi DB), audit, dan penghitung versi realtime.
 * Saldo TIDAK disimpan; dihitung dari transaksi aktif oleh LedgerService, sehingga edit/void historis selalu konsisten.
 */
final class TransactionService
{
    /** @return array{0:array,1:array} [data bersih, daftar error per field] */
    public static function validate(array $in, ?array $existing = null): array
    {
        $err = [];
        $d = [];
        $src = null;

        $acc = Db::one('SELECT * FROM accounts WHERE id = ? AND is_active = 1', [(int) ($in['account_id'] ?? 0)]);
        if (!$acc) {
            $err['account_id'] = 'Pilih rekening yang valid.';
        }
        $d['account_id'] = $acc ? (int) $acc['id'] : 0;

        $date = Filters::date($in['tx_date'] ?? '');
        if ($date === null) {
            $err['tx_date'] = 'Tanggal tidak valid.';
        } else {
            if ($acc && $date < $acc['opening_date']) {
                $err['tx_date'] = 'Tanggal tidak boleh sebelum tanggal saldo awal rekening (' . fmt_date($acc['opening_date']) . ').';
            }
            if ($date > date('Y-m-d', strtotime('+366 days'))) {
                $err['tx_date'] = 'Tanggal terlalu jauh di masa depan.';
            }
        }
        $d['tx_date'] = $date ?? '';

        $dir = (string) ($in['direction'] ?? '');
        if (!in_array($dir, ['in', 'out'], true)) {
            $err['direction'] = 'Pilih jenis: Mutasi Masuk atau Mutasi Keluar.';
        }
        $amount = parse_amount($in['amount'] ?? '', true);
        if ($amount === null) {
            $err['amount'] = 'Nominal tidak valid (contoh: 1250000 atau 1.250.000,50).';
        } elseif (bccomp($amount, '0', 2) === 0) {
            $err['amount'] = 'Nominal tidak boleh nol.';
        } elseif (bccomp($amount, '9999999999999.99', 2) > 0 || bccomp($amount, '-9999999999999.99', 2) < 0) {
            $err['amount'] = 'Nominal terlalu besar.';
        }
        $d['debit'] = ($dir === 'in' && $amount !== null) ? $amount : '0.00';
        $d['credit'] = ($dir === 'out' && $amount !== null) ? $amount : '0.00';

        $cat = (int) ($in['category_id'] ?? 0);
        if ($cat > 0 && !Db::one('SELECT id FROM categories WHERE id = ? AND is_active = 1', [$cat])) {
            $err['category_id'] = 'Kategori tidak valid.';
        }
        $d['category_id'] = $cat > 0 ? $cat : null;
        $pr = (int) ($in['project_id'] ?? 0);
        if ($pr > 0 && !Db::one('SELECT id FROM projects WHERE id = ? AND is_active = 1', [$pr])) {
            $err['project_id'] = 'Proyek tidak valid.';
        }
        $d['project_id'] = $pr > 0 ? $pr : null;

        $lim = static fn(string $k, int $n) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $n);
        $d['voucher_code'] = $lim('voucher_code', 30) ?: null;
        $d['counterparty'] = PartyService::clean((string) ($in['counterparty'] ?? '')) ?: null;
        $d['description'] = $lim('description', 500);
        if ($d['description'] === '') {
            $err['description'] = 'Uraian wajib diisi.';
        }
        $d['description'] = $d['description'] ?: null;
        $d['note'] = trim((string) ($in['note'] ?? '')) !== '' ? mb_substr(trim((string) $in['note']), 0, 5000) : null;
        if (array_key_exists('source_ref', $in)) {
            // No. Invoice/PO = pilihan sumber (Piutang | Hutang | Proyeksi): nomor dokumen diturunkan dari sumber, bukan diketik
            $ref = trim((string) $in['source_ref']);
            if ($ref !== '') {
                $p = TxLinkService::parseRef($ref);
                $src = $p ? TxLinkService::source($p[0], $p[1], $existing ? (int) $existing['id'] : null) : null;
                if (!$src) {
                    $err['source_ref'] = 'Referensi Invoice/PO tidak ditemukan. Pilih ulang dari daftar.';
                }
                $d['ref_no'] = $src && ($src['ref_no'] ?? '') !== '' ? mb_substr((string) $src['ref_no'], 0, 190) : null;
            } else {
                // tanpa referensi: teks lama (data impor) dipertahankan kecuali dihapus pengguna / tautan sebelumnya dilepas
                $hadLink = $existing && ($l = TxLinkService::linkOf((int) $existing['id'])) && !$l['legacy'];
                $d['ref_no'] = ($existing && empty($in['ref_clear']) && !$hadLink) ? ($existing['ref_no'] ?? null) : null;
            }
        } else {
            $d['ref_no'] = $lim('ref_no', 190) ?: null;
        }
        // Pihak = pilihan dari master (nama baku dipakai). Boleh: nilai lama transaksi yang sama (data impor), atau pihak dari sumber
        // yang dipilih (supplier/customer) — dimasukkan ke master saat disimpan.
        if ($d['counterparty'] !== null) {
            $canon = PartyService::canonical($d['counterparty']);
            if ($canon !== null) {
                $d['counterparty'] = $canon;
            } elseif (!(($existing['counterparty'] ?? null) !== null && mb_strtolower((string) $existing['counterparty']) === mb_strtolower($d['counterparty']))
                && !(isset($src) && $src && isset($src['party']) && mb_strtolower((string) $src['party']) === mb_strtolower($d['counterparty']))) {
                $err['counterparty'] = 'Pilih pihak dari daftar, atau tambahkan lewat tombol “Tambah” di dropdown (atau Master Data → Customer).';
            }
        }
        if ($existing === null || ($in['counterparty_detail'] ?? null) !== null) {
            $d['counterparty_detail'] = $lim('counterparty_detail', 255) ?: null;
            // harus pilihan master rekening lawan; nilai lama transaksi yang sama tetap boleh (data impor / rekening nonaktif)
            if ($d['counterparty_detail'] !== null
                && $d['counterparty_detail'] !== ($existing['counterparty_detail'] ?? null)
                && !Db::val('SELECT id FROM counterparty_accounts WHERE label = ? AND is_active = 1', [$d['counterparty_detail']])) {
                $err['counterparty_detail'] = 'Pilih rekening lawan dari daftar (tambahkan di Master Data jika belum ada).';
            }
        }
        return [$d, $err];
    }

    public static function create(array $d, int $userId, ?string $idemKey, ?string $ref = null, bool $confirmDup = false): int
    {
        if ($idemKey !== null && !preg_match('/^[a-f0-9]{32}$/', $idemKey)) {
            $idemKey = null;
        }
        if ($idemKey !== null) {
            $dup = Db::val('SELECT id FROM transactions WHERE idem_key = ?', [$idemKey]);
            if ($dup) {
                return (int) $dup; // kiriman ganda (double-submit): kembalikan transaksi yang sudah tersimpan
            }
        }
        return (int) Db::tx(function () use ($d, $userId, $idemKey, $ref, $confirmDup) {
            $seq = (int) Db::val('SELECT COALESCE(MAX(seq), 0) + 1 FROM transactions WHERE account_id = ?', [$d['account_id']]);
            if (($d['counterparty'] ?? null) !== null) {
                PartyService::ensure($d['counterparty']);
            }
            $id = Db::insert('transactions', $d + ['seq' => $seq, 'idem_key' => $idemKey, 'created_by' => $userId]);
            Audit::log('tx_create', 'transaction', (string) $id, self::summaryLine($d), null, $d);
            if ($ref !== null) {
                TxLinkService::sync($id, $d, $ref, $userId, $confirmDup);   // LinkError -> seluruh penyimpanan dibatalkan
            }
            Version::bump('transactions');
            return $id;
        });
    }

    public static function update(int $id, array $d, int $userId, ?int $expectedVersion, ?string $ref = null, bool $confirmDup = false): void
    {
        Db::tx(function () use ($id, $d, $userId, $expectedVersion, $ref, $confirmDup) {
            $old = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }
            if ($old['voided_at'] !== null) {
                throw new RuntimeException('Transaksi yang sudah dibatalkan tidak dapat diubah. Pulihkan terlebih dahulu.');
            }
            if ($expectedVersion === null || (int) $old['row_version'] !== $expectedVersion) {
                throw new RuntimeException('Data ini baru saja diubah oleh pengguna lain. Muat ulang halaman untuk melihat versi terbaru.');
            }
            $set = $d;
            if (($d['counterparty'] ?? null) !== null) {
                PartyService::ensure($d['counterparty']);
            }
            if ((int) $old['account_id'] !== (int) $d['account_id']) {
                $set['seq'] = (int) Db::val('SELECT COALESCE(MAX(seq), 0) + 1 FROM transactions WHERE account_id = ?', [$d['account_id']]);
            }
            $set['updated_by'] = $userId;
            $set['row_version'] = (int) $old['row_version'] + 1;
            Db::update('transactions', $id, $set);
            $new = Db::one('SELECT * FROM transactions WHERE id = ?', [$id]);
            Audit::log('tx_update', 'transaction', (string) $id, 'Ubah: ' . self::summaryLine($d), self::pick($old), self::pick($new));
            if ($ref !== null) {
                TxLinkService::sync($id, $d, $ref, $userId, $confirmDup);
            } elseif (($link = TxLinkService::linkOf($id)) && !$link['legacy']) {
                // form lama tanpa kolom referensi: tautan dipertahankan, tetapi arah/nominal baru harus tetap muat pada sumbernya
                TxLinkService::sync($id, $d, $link['type'] . ':' . $link['id'], $userId, true);
            }
            Version::bump('transactions');
        });
    }

    public static function void(int $id, int $userId, string $reason): void
    {
        $reason = mb_substr(trim($reason), 0, 255);
        if ($reason === '') {
            throw new RuntimeException('Alasan pembatalan wajib diisi.');
        }
        Db::tx(function () use ($id, $userId, $reason) {
            $old = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$old) {
                throw new RuntimeException('Transaksi tidak ditemukan.');
            }
            if ($old['voided_at'] !== null) {
                throw new RuntimeException('Transaksi sudah dibatalkan.');
            }
            Db::q('UPDATE transactions SET voided_at = NOW(), voided_by = ?, void_reason = ? WHERE id = ?', [$userId, $reason, $id]);
            Audit::log('tx_void', 'transaction', (string) $id, 'Batalkan (void): ' . self::summaryLine($old) . ' — ' . $reason, self::pick($old), ['voided' => true, 'reason' => $reason]);
            TxLinkService::onVoid($id);   // pembayaran/penerimaan/realisasi yang bertaut tidak lagi dihitung
            Version::bump('transactions');
        });
    }

    public static function restore(int $id, int $userId): void
    {
        Db::tx(function () use ($id, $userId) {
            $old = Db::one('SELECT * FROM transactions WHERE id = ? FOR UPDATE', [$id]);
            if (!$old || $old['voided_at'] === null) {
                throw new RuntimeException('Transaksi tidak ditemukan atau tidak dalam keadaan dibatalkan.');
            }
            Db::q('UPDATE transactions SET voided_at = NULL, voided_by = NULL, void_reason = NULL, updated_by = ? WHERE id = ?', [$userId, $id]);
            Audit::log('tx_restore', 'transaction', (string) $id, 'Pulihkan transaksi: ' . self::summaryLine($old), ['voided' => true], ['voided' => false]);
            TxLinkService::onRestore($id);   // dihitung lagi bila masih muat dalam sisa sumbernya
            Version::bump('transactions');
        });
    }

    private static function pick(array $r): array
    {
        $keys = ['account_id', 'tx_date', 'voucher_code', 'counterparty', 'category_id', 'project_id', 'description', 'note', 'ref_no', 'debit', 'credit'];
        return array_intersect_key($r, array_flip($keys));
    }

    private static function summaryLine(array $d): string
    {
        $in = bccomp((string) $d['debit'], '0', 2) !== 0;
        return ($d['tx_date'] ?? '') . ' ' . ($in ? 'masuk ' . $d['debit'] : 'keluar ' . $d['credit']) . ' — ' . mb_substr((string) ($d['description'] ?? ''), 0, 120);
    }
}
