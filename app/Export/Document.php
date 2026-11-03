<?php
declare(strict_types=1);

namespace App\Export;

/** Satu berkas cetak: judul, nama berkas, satu atau lebih lembar. */
final class Document
{
    /** @var Sheet[] */
    public array $sheets = [];

    public function __construct(
        public string $title,
        public string $slug,
        public string $company = 'PT Hexa Multi Energi',
        public string $printedBy = '',
        public string $printedAt = ''
    ) {
        if ($this->printedAt === '') {
            $this->printedAt = date('d/m/Y H:i');
        }
    }

    public function add(Sheet $s): Sheet
    {
        // nama lembar Excel: maks. 31 karakter, tanpa []:*?/\ , unik
        $base = trim((string) preg_replace('/[\[\]:*?\/\\\\]/', ' ', $s->name));
        $base = mb_substr($base === '' ? 'Lembar' : $base, 0, 31);
        $name = $base;
        $n = 2;
        $taken = array_map(static fn(Sheet $x) => mb_strtolower($x->name), $this->sheets);
        while (in_array(mb_strtolower($name), $taken, true)) {
            $name = mb_substr($base, 0, 28) . ' ' . $n++;
        }
        $s->name = $name;
        $this->sheets[] = $s;
        return $s;
    }

    public function filename(string $ext): string
    {
        return $this->slug . '-' . date('Ymd-His') . '.' . $ext;
    }
}
