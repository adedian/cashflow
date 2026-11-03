<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Pembaca .xlsx minimal (ZipArchive + SimpleXML bawaan PHP; tanpa pustaka luar).
 * Membaca NILAI TERSIMPAN (hasil hitung terakhir Excel); formula tidak dijalankan.
 * Hanya membaca — tidak pernah menulis/mengeksekusi apa pun dari file.
 */
final class XlsxReader
{
    private ZipArchive $zip;
    private bool $open = false;
    /** @var string[] */
    private array $shared = [];
    /** @var array<string,array{file:string,state:string}> */
    private array $sheets = [];
    private const MAX_ENTRY_BYTES = 40 * 1024 * 1024;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive();
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Berkas bukan arsip .xlsx yang valid.');
        }
        $this->open = true;
        // cegah "zip bomb": batasi jumlah entri dan total ukuran setelah dekompresi
        $total = 0;
        if ($this->zip->numFiles > 2000) {
            throw new RuntimeException('Arsip memiliki terlalu banyak berkas.');
        }
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $st = $this->zip->statIndex($i);
            $total += (int) ($st['size'] ?? 0);
            if ($total > 250 * 1024 * 1024) {
                throw new RuntimeException('Isi arsip terlalu besar setelah dekompresi.');
            }
        }
        foreach (['xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $need) {
            if ($this->zip->locateName($need) === false) {
                throw new RuntimeException('Struktur .xlsx tidak lengkap (' . $need . ').');
            }
        }
        $this->loadShared();
        $this->loadSheets();
    }

    /** Tutup arsip agar berkas sementara dapat dihapus (Windows mengunci berkas yang masih terbuka). */
    public function close(): void
    {
        // idempoten: di PHP 8 menutup arsip dua kali (close() lalu destruktor) melempar ValueError
        if ($this->open) {
            $this->open = false;
            try {
                $this->zip->close();
            } catch (\Throwable $e) {
                // sudah tertutup
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function entry(string $name): string
    {
        $st = $this->zip->statName($name);
        if ($st === false) {
            throw new RuntimeException('Bagian tidak ditemukan: ' . $name);
        }
        if ($st['size'] > self::MAX_ENTRY_BYTES) {
            throw new RuntimeException('Bagian arsip terlalu besar: ' . $name);
        }
        return (string) $this->zip->getFromName($name);
    }

    private function xml(string $data): \SimpleXMLElement
    {
        $prev = libxml_use_internal_errors(true);
        // LIBXML_NONET: tidak mengambil entitas eksternal; tidak memakai LIBXML_NOENT (cegah XXE)
        $x = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($x === false) {
            throw new RuntimeException('XML dalam berkas tidak valid.');
        }
        return $x;
    }

    private function loadShared(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        $x = $this->xml($this->entry('xl/sharedStrings.xml'));
        foreach ($x->si as $si) {
            if (isset($si->t)) {
                $this->shared[] = (string) $si->t;
            } else {
                $t = '';
                foreach ($si->r as $r) {
                    $t .= (string) $r->t;
                }
                $this->shared[] = $t;
            }
        }
    }

    private function loadSheets(): void
    {
        $wb = $this->xml($this->entry('xl/workbook.xml'));
        $rels = $this->xml($this->entry('xl/_rels/workbook.xml.rels'));
        $map = [];
        foreach ($rels->Relationship as $r) {
            $map[(string) $r['Id']] = (string) $r['Target'];
        }
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string) $s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $target = $map[$rid] ?? '';
            $file = ltrim(str_starts_with($target, '/') ? substr($target, 1) : 'xl/' . $target, '/');
            $this->sheets[(string) $s['name']] = ['file' => $file, 'state' => (string) ($s['state'] ?: 'visible')];
        }
    }

    /** @return array<string,string> nama sheet => status (visible/hidden) */
    public function sheetNames(): array
    {
        $o = [];
        foreach ($this->sheets as $n => $m) {
            $o[$n] = $m['state'];
        }
        return $o;
    }

    public function hasSheet(string $name): bool
    {
        return isset($this->sheets[$name]);
    }

    /**
     * @return array<int,array<string,array{v:string,t:string,f:bool}>> baris => kolom => sel
     */
    public function rows(string $sheetName): array
    {
        if (!isset($this->sheets[$sheetName])) {
            throw new RuntimeException('Sheet tidak ditemukan: ' . $sheetName);
        }
        $x = $this->xml($this->entry($this->sheets[$sheetName]['file']));
        $out = [];
        foreach ($x->sheetData->row as $row) {
            foreach ($row->c as $c) {
                $ref = (string) $c['r'];
                if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                    continue;
                }
                $t = (string) $c['t'];
                $v = isset($c->v) ? (string) $c->v : '';
                if ($t === 's') {
                    $v = $this->shared[(int) $v] ?? '';
                } elseif ($t === 'inlineStr') {
                    $v = (string) ($c->is->t ?? '');
                }
                if ($v === '' && !isset($c->f)) {
                    continue;
                }
                $out[(int) $m[2]][$m[1]] = ['v' => $v, 't' => $t, 'f' => isset($c->f)];
            }
        }
        ksort($out);
        return $out;
    }

    /** Serial tanggal Excel -> 'Y-m-d' (null bila bukan angka tanggal yang masuk akal). */
    public static function serialToDate($v): ?string
    {
        if (!is_numeric($v)) {
            return null;
        }
        $n = (float) $v;
        if ($n < 36526 || $n > 73051) { // 2000-01-01 .. 2099-12-31
            return null;
        }
        return gmdate('Y-m-d', (int) round(($n - 25569) * 86400));
    }

    /** Nilai angka Excel (mungkin notasi ilmiah) -> string desimal 2 digit; null bila bukan angka. */
    public static function money($v): ?string
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $r = number_format(round((float) $v, 2), 2, '.', '');
        return $r === '-0.00' ? '0.00' : $r;
    }
}
