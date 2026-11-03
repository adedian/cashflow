<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use RuntimeException;

/**
 * Sisa realisasi estimasi (Proyeksi berdiri sendiri). Estimasi awal = forecast_items.amount; total realisasi = Σ forecast_realizations
 * (transaksi tidak dibatalkan); sisa = estimasi − realisasi — selalu dihitung, tidak pernah diketik.
 *
 *   Realisasi Sebagian : ada realisasi, masih ada sisa -> sisa otomatis tetap estimasi aktif (dapat direalisasikan lagi lewat Transaksi)
 *   Akhiri             : satu-satunya tindakan: sisa ditutup (closed_*) -> "Selesai — Sisa Diakhiri": otomatis hilang dari daftar Proyeksi
 *                        (dan cetak), tidak lagi estimasi aktif / pengingat / pilihan realisasi. Histori tetap tersimpan & dapat dilihat lewat
 *                        "Tampilkan riwayat". TIDAK membuat transaksi/Mutasi Keluar, TIDAK mengubah saldo, realisasi, maupun transaksi tersimpan.
 */
final class ForecastService
{
    private const EPS = '0.004';

    public const STATUS_LABEL = [
        'belum' => 'Belum terealisasi',
        'sebagian' => 'Realisasi Sebagian',
        'terealisasi' => 'Terealisasi Penuh',
        'diakhiri' => 'Selesai — Sisa Diakhiri',
        'nonaktif' => 'Nonaktif',
    ];

    /** Σ realisasi sah (transaksi tidak dibatalkan). */
    public static function realized(int $fid): string
    {
        return bcadd((string) Db::val('SELECT COALESCE(SUM(r.amount), 0) FROM forecast_realizations r JOIN transactions t ON t.id = r.transaction_id WHERE r.forecast_id = ? AND t.voided_at IS NULL', [$fid]), '0', 2);
    }

    /** Sisa = estimasi − realisasi valid (min 0). */
    public static function remaining(array $f, string $realized): string
    {
        $left = bcsub((string) $f['amount'], $realized, 2);
        return bccomp($left, self::EPS, 3) > 0 ? $left : '0.00';
    }

    /** @return string belum|sebagian|terealisasi|diakhiri|nonaktif */
    public static function status(array $f, string $realized): string
    {
        if (!(int) $f['is_active']) {
            return 'nonaktif';
        }
        $rem = self::remaining($f, $realized);
        if (bccomp($rem, '0', 2) === 0 && (bccomp($realized, '0', 2) > 0 || $f['paid_at'] !== null)) {
            return 'terealisasi';
        }
        if ($f['closed_at'] !== null) {
            return 'diakhiri';
        }
        if ($f['paid_at'] !== null) {
            return 'terealisasi';
        }
        return bccomp($realized, '0', 2) > 0 ? 'sebagian' : 'belum';
    }

    private static function actor(): ?int
    {
        $u = Auth::user();
        return $u ? (int) $u['id'] : null;
    }

    /** Kunci baris & validasi umum: item berdiri sendiri (tidak tertaut Hutang/Piutang), aktif. */
    private static function lockStandalone(int $fid): array
    {
        $f = Db::one('SELECT * FROM forecast_items WHERE id = ? FOR UPDATE', [$fid]);
        if (!$f) {
            throw new RuntimeException('Item Proyeksi tidak ditemukan.');
        }
        if (!(int) $f['is_active']) {
            throw new RuntimeException('Item Proyeksi ini nonaktif.');
        }
        if (Db::val('SELECT id FROM debts WHERE forecast_item_id = ?', [$fid]) || Db::val('SELECT id FROM invoices WHERE forecast_item_id = ?', [$fid])) {
            throw new RuntimeException('Item Proyeksi ini tertaut ke Hutang/Piutang; sisanya mengikuti Hutang/Piutang tersebut.');
        }
        return $f;
    }

    /** Akhiri: tutup sisa estimasi (butuh realisasi > 0 dan sisa > 0). Mengembalikan sisa yang diakhiri. */
    public static function close(int $fid, string $note, int $userId): string
    {
        $note = mb_substr(trim($note), 0, 255);
        return (string) Db::tx(static function () use ($fid, $note, $userId) {
            $f = self::lockStandalone($fid);
            if ($f['closed_at'] !== null) {
                throw new RuntimeException('Sisa estimasi ini sudah diakhiri pada ' . fmt_date($f['closed_at'], 'd/m/Y H:i') . '.');
            }
            $real = self::realized($fid);
            if (bccomp($real, '0', 2) <= 0) {
                throw new RuntimeException('Belum ada realisasi. Sisa baru dapat diakhiri setelah ada realisasi sebagian lewat menu Transaksi (untuk membatalkan seluruh estimasi, nonaktifkan item).');
            }
            $rem = self::remaining($f, $real);
            if (bccomp($rem, '0', 2) <= 0) {
                throw new RuntimeException('Estimasi sudah terealisasi penuh; tidak ada sisa untuk diakhiri.');
            }
            $now = date('Y-m-d H:i:s');
            Db::update('forecast_items', $fid, ['closed_at' => $now, 'closed_by' => $userId, 'closed_remaining' => $rem, 'close_note' => $note !== '' ? $note : null]);
            Audit::log('forecast_close', 'forecast', (string) $fid,
                'Akhiri sisa estimasi: ' . mb_substr((string) $f['description'], 0, 100) . ' (estimasi ' . $f['amount'] . ', realisasi ' . $real . ', sisa diakhiri ' . $rem . ')',
                ['closed_at' => null], ['closed_at' => $now, 'estimasi' => (string) $f['amount'], 'realisasi' => $real, 'sisa_diakhiri' => $rem, 'catatan' => $note]);
            Version::bump('forecast');
            return $rem;
        });
    }

    /** Buka kembali sisa yang diakhiri (histori penutupan tetap di audit log). */
    public static function reopen(int $fid, int $userId): string
    {
        return (string) Db::tx(static function () use ($fid, $userId) {
            $f = self::lockStandalone($fid);
            if ($f['closed_at'] === null) {
                throw new RuntimeException('Sisa estimasi ini tidak dalam keadaan diakhiri.');
            }
            Db::update('forecast_items', $fid, ['closed_at' => null, 'closed_by' => null, 'closed_remaining' => null, 'close_note' => null]);
            // item yang terealisasi penuh tetap mengikuti realisasi
            LinkService::syncRealization($fid);
            $rem = self::remaining($f, self::realized($fid));
            Audit::log('forecast_reopen', 'forecast', (string) $fid, 'Buka kembali sisa estimasi: ' . mb_substr((string) $f['description'], 0, 100) . ' (sisa ' . $rem . ')',
                ['closed_at' => $f['closed_at'], 'sisa_diakhiri' => (string) $f['closed_remaining'], 'catatan' => $f['close_note']], ['closed_at' => null, 'sisa' => $rem]);
            Version::bump('forecast');
            return $rem;
        });
    }

    /**
     * Ringkasan satu item untuk tampilan: estimasi awal, total realisasi, sisa, status, penutupan, dan apakah menunggu keputusan.
     * @return array<string,mixed>
     */
    public static function summary(array $f, ?string $realized = null): array
    {
        $real = $realized ?? self::realized((int) $f['id']);
        $rem = self::remaining($f, $real);
        $st = self::status($f, $real);
        return [
            'estimate' => (string) $f['amount'], 'realized' => $real, 'remaining' => $rem, 'status' => $st, 'label' => self::STATUS_LABEL[$st],
            'closed' => $f['closed_at'] !== null, 'closed_remaining' => $f['closed_remaining'] !== null ? (string) $f['closed_remaining'] : null,
            'can_close' => $st === 'sebagian',
        ];
    }
}
