<?php
declare(strict_types=1);

namespace App\Services\Import;

use App\Services\XlsxReader;
use RuntimeException;
use ZipArchive;

/**
 * Sumber baris untuk Impor Excel: .xlsx (lewat XlsxReader) atau .csv. Format .xls (biner lama) TIDAK didukung:
 * tanpa pustaka luar tidak ada cara aman membacanya; pengguna diminta menyimpan ulang sebagai .xlsx.
 * Keluaran seragam: baris => kolom(huruf) => ['v'=>teks, 't'=>jenis sel ('' angka, 's' teks, 'e' galat), 'f'=>bool formula].
 */
final class SheetSource
{
    public const CSV_SHEET = 'Data CSV';
    private ?XlsxReader $xl = null;
    /** @var array<int,array<string,array>>|null */
    private ?array $csvRows = null;

    private function __construct(private string $path, private string $ext)
    {
    }

    /** Pemeriksaan keamanan isi berkas (bukan sekadar ekstensi). Melempar RuntimeException berpesan Indonesia. */
    public static function validate(string $path, string $ext): void
    {
        $ext = strtolower($ext);
        if ($ext === 'xls') {
            throw new RuntimeException('Format .xls (Excel lama) tidak didukung. Buka berkas di Excel lalu “Simpan Sebagai” .xlsx (atau .csv), kemudian unggah lagi.');
        }
        if (!in_array($ext, ['xlsx', 'csv'], true)) {
            throw new RuntimeException('Hanya berkas .xlsx atau .csv yang diterima.');
        }
        $size = (int) @filesize($path);
        if ($size <= 0) {
            throw new RuntimeException('Berkas kosong.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 8);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        if ($ext === 'xlsx') {
            if (strncmp($head, "PK\x03\x04", 4) !== 0 || !in_array($mime, ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream', 'application/x-zip-compressed'], true)) {
                throw new RuntimeException('Berkas bukan workbook .xlsx yang valid (isi tidak sesuai ekstensi).');
            }
            $z = new ZipArchive();
            if ($z->open($path, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('Berkas .xlsx rusak atau bukan arsip yang valid.');
            }
            $macro = $z->locateName('xl/vbaProject.bin') !== false;
            $z->close();
            if ($macro) {
                throw new RuntimeException('Berkas memuat makro (VBA). Makro tidak pernah dijalankan dan berkas ditolak; simpan ulang tanpa makro (.xlsx biasa).');
            }
            return;
        }
        // CSV: harus teks. Berkas biner (mis. .xlsx/.xls yang diganti nama) ditolak.
        $sample = (string) file_get_contents($path, false, null, 0, 65536);
        if (strncmp($head, "PK\x03\x04", 4) === 0 || strncmp($head, "\xD0\xCF\x11\xE0", 4) === 0 || str_contains($sample, "\0")) {
            throw new RuntimeException('Berkas bukan teks CSV yang valid (tampak biner). Jika ini berkas Excel, ubah ekstensinya menjadi .xlsx.');
        }
        if (!in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/octet-stream', 'inode/x-empty'], true)) {
            throw new RuntimeException('Jenis isi berkas CSV tidak dikenali (' . $mime . ').');
        }
    }

    public static function open(string $path, string $ext): self
    {
        $s = new self($path, strtolower($ext));
        if ($s->ext === 'xlsx') {
            $s->xl = new XlsxReader($path);
        }
        return $s;
    }

    public function close(): void
    {
        $this->xl?->close();
    }

    /** @return array<string,string> nama sheet => visible|hidden */
    public function sheets(): array
    {
        return $this->xl ? $this->xl->sheetNames() : [self::CSV_SHEET => 'visible'];
    }

    /** @return array<int,array<string,array{v:string,t:string,f:bool}>> */
    public function rows(string $sheet): array
    {
        if ($this->xl) {
            return $this->xl->rows($sheet);
        }
        return $this->csvRows ??= $this->readCsv();
    }

    private function readCsv(): array
    {
        $raw = (string) file_get_contents($this->path);
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }
        $first = '';
        foreach (preg_split('/\R/', $raw, 6) ?: [] as $line) {
            $first .= $line . "\n";
        }
        $best = ',';
        $bestN = -1;
        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($first, $d);
            if ($n > $bestN) {
                $best = $d;
                $bestN = $n;
            }
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $raw);
        rewind($h);
        $out = [];
        $r = 0;
        while (($cells = fgetcsv($h, 0, $best, '"', '')) !== false) {
            $r++;
            if ($cells === [null]) {
                continue;
            }
            foreach ($cells as $i => $v) {
                $v = (string) $v;
                if (trim($v) === '') {
                    continue;
                }
                $out[$r][ImportParser::colLetter((int) $i)] = ['v' => $v, 't' => 's', 'f' => false];
            }
        }
        fclose($h);
        return $out;
    }
}
