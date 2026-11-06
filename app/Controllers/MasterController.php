<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Db;
use App\Core\Page;
use App\Core\Response;
use App\Models\Lookup;
use App\Services\Audit;
use App\Services\Filters;
use App\Services\LedgerService;
use App\Services\PartyService;
use App\Services\Version;

/** Master data: kategori, proyek, rekening, pemetaan kategori Excel. Hanya Super Admin (diatur di routes). */
final class MasterController extends Controller
{
    private const KINDS = ['pendapatan', 'biaya', 'pajak', 'pembiayaan', 'transfer', 'lainnya'];

    public function index(): Response
    {
        $tab = in_array($this->req->str('tab'), ['categories', 'projects', 'accounts', 'counterparties', 'suppliers', 'parties', 'opening', 'mapping'], true) ? $this->req->str('tab') : 'categories';
        $tabs = [];
        foreach (['categories' => 'Kategori', 'projects' => 'Proyek', 'accounts' => 'Rekening', 'counterparties' => 'Rekening Lawan', 'suppliers' => 'Supplier', 'parties' => 'Customer', 'opening' => 'Saldo Awal', 'mapping' => 'Pemetaan Excel'] as $k => $l) {
            $tabs[] = ['href' => url('/master?tab=' . $k), 'label' => $l, 'active' => $k === $tab];
        }
        Page::set(['title' => 'Master Data', 'subtitle' => 'Kategori, proyek, rekening, saldo awal, dan pemetaan kategori dari Excel', 'tabs' => $tabs]);
        $data = ['tab' => $tab, 'kinds' => Lookup::KIND_LABEL];
        if ($tab === 'categories') {
            $data['rows'] = Db::all('SELECT c.*, (SELECT COUNT(DISTINCT t.id) FROM transactions t LEFT JOIN transaction_items i ON i.transaction_id = t.id WHERE t.voided_at IS NULL AND (t.category_id = c.id OR i.category_id = c.id)) AS n FROM categories c ORDER BY FIELD(c.kind,\'pendapatan\',\'biaya\',\'pajak\',\'pembiayaan\',\'transfer\',\'lainnya\'), c.name');
        } elseif ($tab === 'projects') {
            $data['rows'] = Db::all('SELECT p.*, (SELECT COUNT(DISTINCT t.id) FROM transactions t LEFT JOIN transaction_items i ON i.transaction_id = t.id WHERE t.voided_at IS NULL AND (t.project_id = p.id OR i.project_id = p.id)) AS n FROM projects p ORDER BY p.name');
        } elseif ($tab === 'accounts') {
            $data['rows'] = Db::all('SELECT a.*, (SELECT COUNT(*) FROM transactions t WHERE t.account_id = a.id) AS n FROM accounts a ORDER BY a.sort_order, a.id');
            $editId = $this->req->int('edit', 0);
            $data['edit'] = null;
            foreach ($data['rows'] as $r) {
                if ((int) $r['id'] === $editId) { $data['edit'] = $r; }
            }
        } elseif ($tab === 'counterparties') {
            $data['rows'] = Db::all('SELECT c.*, (SELECT COUNT(*) FROM transactions t WHERE t.counterparty_detail = c.label AND t.voided_at IS NULL) AS n FROM counterparty_accounts c ORDER BY c.label');
        } elseif ($tab === 'suppliers') {
            $data['rows'] = Db::all('SELECT s.*, (SELECT COUNT(*) FROM debts d WHERE d.supplier_id = s.id AND d.voided_at IS NULL) AS n FROM suppliers s ORDER BY s.name');
        } elseif ($tab === 'parties') {
            $data['rows'] = Db::all('SELECT p.*, (SELECT COUNT(*) FROM transactions t WHERE t.counterparty = p.name AND t.voided_at IS NULL) AS n FROM parties p ORDER BY p.name');
        } elseif ($tab === 'opening') {
            $first = [];
            foreach (Db::all('SELECT account_id, MIN(tx_date) AS d FROM transactions GROUP BY account_id') as $r) {
                $first[(int) $r['account_id']] = $r['d'];
            }
            $rows = LedgerService::accounts(null, false);
            foreach ($rows as &$r) {
                $r['first_tx'] = $first[(int) $r['id']] ?? null;
            }
            unset($r);
            $data['rows'] = $rows;
        } else {
            $counts = [];
            foreach (Db::all('SELECT category_raw, COUNT(*) AS n FROM transactions WHERE category_raw IS NOT NULL GROUP BY category_raw') as $r) {
                $counts[$r['category_raw']] = (int) $r['n'];
            }
            $rows = Db::all(
                'SELECT m.id, m.raw_text, m.category_id, m.project_id, c.name AS category_name, c.kind, p.name AS project_name
                 FROM category_map m LEFT JOIN categories c ON c.id = m.category_id LEFT JOIN projects p ON p.id = m.project_id'
            );
            foreach ($rows as &$r) {
                $r['n'] = $counts[$r['raw_text']] ?? 0;
            }
            unset($r);
            usort($rows, static fn($a, $b) => [$b['n'], $a['raw_text']] <=> [$a['n'], $b['raw_text']]);
            $data['rows'] = $rows;
            $data['categories'] = Lookup::categories();
            $data['projects'] = Lookup::projects();
        }
        return $this->view('master/index', $data);
    }

    private function back(string $tab): Response
    {
        return $this->redirect('/master?tab=' . $tab);
    }

    private function name(string $key, int $max): string
    {
        return mb_substr(trim($this->req->str($key)), 0, $max);
    }

    /* ---------- kategori ---------- */
    public function saveCategory(string $id = ''): Response
    {
        $name = $this->name('name', 120);
        $kind = $this->req->str('kind');
        if ($name === '' || !in_array($kind, self::KINDS, true)) {
            $this->flash('error', 'Nama kategori wajib diisi dan jenis harus valid.');
            return $this->back('categories');
        }
        $dup = Db::val('SELECT id FROM categories WHERE name = ? AND id <> ?', [$name, (int) $id]);
        if ($dup) {
            $this->flash('error', 'Nama kategori sudah dipakai.');
            return $this->back('categories');
        }
        Db::tx(function () use ($id, $name, $kind) {
            if ($id === '') {
                $new = Db::insert('categories', ['name' => $name, 'kind' => $kind]);
                Audit::log('category_create', 'category', (string) $new, "Tambah kategori $name ($kind)", null, ['name' => $name, 'kind' => $kind]);
            } else {
                $old = Db::one('SELECT * FROM categories WHERE id = ?', [(int) $id]);
                if (!$old) { throw new \App\Core\HttpException('Kategori tidak ditemukan.', 404); }
                $active = $this->req->bool('is_active') ? 1 : 0;
                Db::update('categories', (int) $id, ['name' => $name, 'kind' => $kind, 'is_active' => $active]);
                Audit::log('category_update', 'category', $id, "Ubah kategori $name", ['name' => $old['name'], 'kind' => $old['kind'], 'is_active' => $old['is_active']], ['name' => $name, 'kind' => $kind, 'is_active' => $active]);
            }
            Version::bump('master');
            Version::bump('transactions'); // jenis kategori memengaruhi dashboard & laporan
        });
        $this->flash('success', 'Kategori tersimpan.');
        return $this->back('categories');
    }

    /* ---------- proyek ---------- */
    public function saveProject(string $id = ''): Response
    {
        $name = $this->name('name', 160);
        if ($name === '') {
            $this->flash('error', 'Nama proyek wajib diisi.');
            return $this->back('projects');
        }
        if (Db::val('SELECT id FROM projects WHERE name = ? AND id <> ?', [$name, (int) $id])) {
            $this->flash('error', 'Nama proyek sudah dipakai.');
            return $this->back('projects');
        }
        Db::tx(function () use ($id, $name) {
            if ($id === '') {
                $new = Db::insert('projects', ['name' => $name]);
                Audit::log('project_create', 'project', (string) $new, "Tambah proyek $name", null, ['name' => $name]);
            } else {
                $old = Db::one('SELECT * FROM projects WHERE id = ?', [(int) $id]);
                if (!$old) { throw new \App\Core\HttpException('Proyek tidak ditemukan.', 404); }
                $active = $this->req->bool('is_active') ? 1 : 0;
                Db::update('projects', (int) $id, ['name' => $name, 'is_active' => $active]);
                Audit::log('project_update', 'project', $id, "Ubah proyek $name", ['name' => $old['name'], 'is_active' => $old['is_active']], ['name' => $name, 'is_active' => $active]);
            }
            Version::bump('master');
            Version::bump('transactions');
        });
        $this->flash('success', 'Proyek tersimpan.');
        return $this->back('projects');
    }

    /** Samarkan nomor rekening untuk jejak audit (hanya 4 digit terakhir). */
    private static function maskNo(?string $no): ?string
    {
        return ($no === null || $no === '') ? null : '****' . substr($no, -4);
    }

    /* ---------- rekening ---------- */
    public function saveAccount(string $id = ''): Response
    {
        $name = $this->name('name', 120);
        $limitRaw = trim($this->req->str('credit_limit'));
        $limit = $limitRaw === '' ? null : parse_amount($limitRaw);
        if ($limit !== null && bccomp((string) $limit, '0', 2) <= 0) {
            $limit = null;   // limit 0 = tanpa limit (rekening bank operasional tidak memakai limit)
        }
        $sort = max(0, min(999, $this->req->int('sort_order', 0)));
        $retry = $id === '' ? 'accounts' : 'accounts&edit=' . (int) $id; // gagal validasi → kembali ke formulir yang sama
        if ($name === '' || ($limitRaw !== '' && $limit === null)) {
            $this->flash('error', 'Nama wajib diisi dan limit harus berupa nominal valid.');
            return $this->back($retry);
        }
        // informasi rekening untuk dashboard: nomor lengkap hanya digit (spasi/titik/strip dibuang), 6–30 digit
        $bank = $this->name('bank_name', 80) ?: null;
        $holder = $this->name('account_holder', 120) ?: null;
        $noRaw = trim($this->req->str('account_no'));
        $no = null;
        if ($noRaw !== '') {
            $digits = preg_replace('/[\s.\-]/', '', $noRaw);
            if (!preg_match('/^\d{6,30}$/', $digits)) {
                $this->flash('error', 'Nomor rekening harus berupa 6–30 digit angka.');
                return $this->back($retry);
            }
            $no = $digits;
        }
        // 4 digit terakhir: otomatis dari nomor lengkap; jika nomor kosong, pakai isian manual (tanpa nomor lengkap)
        $hint = $no !== null ? substr($no, -4) : (preg_replace('/\D/', '', $this->name('acct_hint', 8)) ?: null);
        $show = $this->req->bool('show_on_dashboard') ? 1 : 0;
        $vsee = $this->req->bool('viewer_can_see_no') ? 1 : 0;
        $info = ['bank_name' => $bank, 'account_holder' => $holder, 'account_no' => $no, 'show_on_dashboard' => $show, 'viewer_can_see_no' => $vsee];
        Db::tx(function () use ($id, $name, $limit, $hint, $sort, $info) {
            if ($id === '') {
                $code = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $this->name('code', 20)));
                $kind = $this->req->str('kind') === 'facility' ? 'facility' : 'bank';
                $mode = $this->req->str('balance_mode') === 'liability' ? 'liability' : 'asset';
                $open = parse_amount($this->req->str('opening_balance') ?: '0', true);
                $od = Filters::date($this->req->str('opening_date'));
                if ($code === '' || $open === null || $od === null) {
                    throw new \App\Core\HttpException('Kode, saldo awal, dan tanggal saldo awal wajib valid.', 422);
                }
                if (Db::val('SELECT id FROM accounts WHERE code = ?', [$code])) {
                    throw new \App\Core\HttpException('Kode rekening sudah dipakai.', 422);
                }
                $new = Db::insert('accounts', ['code' => $code, 'name' => $name, 'kind' => $kind, 'balance_mode' => $mode, 'credit_limit' => $limit, 'opening_balance' => $open, 'opening_date' => $od, 'acct_hint' => $hint, 'sort_order' => $sort] + $info);
                Audit::log('account_create', 'account', (string) $new, "Tambah rekening $name ($code)", null, ['code' => $code, 'name' => $name, 'opening_balance' => $open, 'account_no' => self::maskNo($info['account_no'])]);
            } else {
                $old = Db::one('SELECT * FROM accounts WHERE id = ?', [(int) $id]);
                if (!$old) { throw new \App\Core\HttpException('Rekening tidak ditemukan.', 404); }
                $active = $this->req->bool('is_active') ? 1 : 0;
                Db::update('accounts', (int) $id, ['name' => $name, 'credit_limit' => $limit, 'acct_hint' => $hint, 'sort_order' => $sort, 'is_active' => $active] + $info);
                // nomor lengkap tidak ditulis ke audit log; hanya 4 digit terakhir
                Audit::log(
                    'account_update', 'account', $id, "Ubah rekening $name",
                    ['name' => $old['name'], 'credit_limit' => $old['credit_limit'], 'is_active' => $old['is_active'], 'bank_name' => $old['bank_name'], 'account_holder' => $old['account_holder'], 'account_no' => self::maskNo($old['account_no']), 'show_on_dashboard' => $old['show_on_dashboard'], 'viewer_can_see_no' => $old['viewer_can_see_no']],
                    ['name' => $name, 'credit_limit' => $limit, 'is_active' => $active, 'bank_name' => $info['bank_name'], 'account_holder' => $info['account_holder'], 'account_no' => self::maskNo($info['account_no']), 'show_on_dashboard' => $show, 'viewer_can_see_no' => $vsee]
                );
            }
            Version::bump('master');
            Version::bump('transactions');
        });
        $this->flash('success', 'Rekening tersimpan.');
        return $this->back('accounts');
    }

    /** Hapus rekening: hanya bila belum pernah dipakai transaksi (termasuk yang dibatalkan); selain itu cukup dinonaktifkan. */
    public function deleteAccount(string $id): Response
    {
        $old = Db::one('SELECT * FROM accounts WHERE id = ?', [(int) $id]);
        if (!$old) { throw new \App\Core\HttpException('Rekening tidak ditemukan.', 404); }
        if ((int) Db::val('SELECT COUNT(*) FROM transactions WHERE account_id = ?', [(int) $id]) > 0) {
            $this->flash('error', 'Rekening ' . $old['code'] . ' sudah punya transaksi sehingga tidak dapat dihapus. Nonaktifkan lewat Edit bila tidak dipakai lagi.');
            return $this->back('accounts');
        }
        Db::tx(function () use ($id, $old) {
            Db::q('DELETE FROM accounts WHERE id = ?', [(int) $id]);
            Audit::log('account_delete', 'account', $id, 'Hapus rekening ' . $old['name'] . ' (' . $old['code'] . ')', ['code' => $old['code'], 'name' => $old['name'], 'opening_balance' => $old['opening_balance'], 'account_no' => self::maskNo($old['account_no'])], null);
            Version::bump('master');
            Version::bump('transactions');
        });
        $this->flash('success', 'Rekening ' . $old['code'] . ' dihapus.');
        return $this->back('accounts');
    }

    /* ---------- customer / pihak penerima-pembayar (dropdown Pihak di formulir Transaksi) ---------- */
    public function saveParty(string $id = ''): Response
    {
        $name = PartyService::clean($this->req->str('name'));
        if ($name === '') {
            $this->flash('error', 'Nama customer wajib diisi.');
            return $this->back('parties');
        }
        if (Db::val('SELECT id FROM parties WHERE name = ? AND id <> ?', [$name, (int) $id])) {
            $this->flash('error', 'Customer tersebut sudah ada.');
            return $this->back('parties');
        }
        $renamed = 0;
        Db::tx(function () use ($id, $name, &$renamed) {
            if ($id === '') {
                $new = Db::insert('parties', ['name' => $name]);
                Audit::log('party_create', 'party', (string) $new, "Tambah customer $name", null, ['name' => $name]);
            } else {
                $old = Db::one('SELECT * FROM parties WHERE id = ?', [(int) $id]);
                if (!$old) { throw new \App\Core\HttpException('Customer tidak ditemukan.', 404); }
                $active = $this->req->bool('is_active') ? 1 : 0;
                Db::update('parties', (int) $id, ['name' => $name, 'is_active' => $active]);
                if ($old['name'] !== $name) {
                    // transaksi menyimpan salinan nama; ikut diperbarui agar konsisten dengan master
                    $renamed = Db::q('UPDATE transactions SET counterparty = ? WHERE counterparty = ?', [$name, $old['name']])->rowCount();
                }
                Audit::log('party_update', 'party', $id, "Ubah customer $name" . ($renamed ? " ($renamed transaksi diperbarui)" : ''), ['name' => $old['name'], 'is_active' => $old['is_active']], ['name' => $name, 'is_active' => $active]);
            }
            Version::bump('master');
            if ($renamed) { Version::bump('transactions'); }
        });
        $this->flash('success', 'Customer tersimpan.' . ($renamed ? " $renamed transaksi diperbarui." : ''));
        return $this->back('parties');
    }

    /** Tambah pihak dari dropdown formulir Transaksi (JSON). Nama yang sudah ada dikembalikan apa adanya (tidak ganda). */
    public function createPartyApi(): Response
    {
        $name = PartyService::clean($this->req->str('name'));
        if ($name === '' || mb_strlen($name) > 255) {
            return $this->fail('Nama customer wajib diisi (maks. 255 karakter).');
        }
        $existing = PartyService::canonical($name);
        if ($existing !== null) {
            return $this->ok(['name' => $existing, 'created' => false]);
        }
        Db::tx(function () use ($name) {
            $id = Db::insert('parties', ['name' => $name]);
            Audit::log('party_create', 'party', (string) $id, "Tambah customer $name (dari formulir Transaksi)", null, ['name' => $name]);
            Version::bump('master');
        });
        return $this->ok(['name' => $name, 'created' => true]);
    }

    /* ---------- supplier (dropdown Nama supplier di formulir Hutang) ---------- */
    public function saveSupplier(string $id = ''): Response
    {
        $name = preg_replace('/\s+/', ' ', $this->name('name', 160));
        if ($name === '') {
            $this->flash('error', 'Nama supplier wajib diisi.');
            return $this->back('suppliers');
        }
        if (Db::val('SELECT id FROM suppliers WHERE name = ? AND id <> ?', [$name, (int) $id])) {
            $this->flash('error', 'Supplier tersebut sudah ada.');
            return $this->back('suppliers');
        }
        $renamed = 0;
        Db::tx(function () use ($id, $name, &$renamed) {
            if ($id === '') {
                $new = Db::insert('suppliers', ['name' => $name]);
                Audit::log('supplier_create', 'supplier', (string) $new, "Tambah supplier $name", null, ['name' => $name]);
            } else {
                $old = Db::one('SELECT * FROM suppliers WHERE id = ?', [(int) $id]);
                if (!$old) { throw new \App\Core\HttpException('Supplier tidak ditemukan.', 404); }
                $active = $this->req->bool('is_active') ? 1 : 0;
                Db::update('suppliers', (int) $id, ['name' => $name, 'is_active' => $active]);
                if ($old['name'] !== $name) {
                    // hutang menyimpan salinan nama untuk tampilan/ekspor; ikut diperbarui agar konsisten dengan master
                    $renamed = Db::q('UPDATE debts SET supplier = ? WHERE supplier_id = ?', [$name, (int) $id])->rowCount();
                }
                Audit::log('supplier_update', 'supplier', $id, "Ubah supplier $name" . ($renamed ? " ($renamed hutang diperbarui)" : ''), ['name' => $old['name'], 'is_active' => $old['is_active']], ['name' => $name, 'is_active' => $active]);
            }
            Version::bump('master');
            if ($renamed) { Version::bump('debts'); }
        });
        $this->flash('success', 'Supplier tersimpan.' . ($renamed ? " $renamed hutang diperbarui." : ''));
        return $this->back('suppliers');
    }

    /* ---------- rekening lawan (pihak penerima/pembayar) ---------- */
    public function saveCounterparty(string $id = ''): Response
    {
        $label = preg_replace('/\s+/', ' ', $this->name('label', 255));
        if ($label === '') {
            $this->flash('error', 'Bank / rekening wajib diisi.');
            return $this->back('counterparties');
        }
        if (Db::val('SELECT id FROM counterparty_accounts WHERE label = ? AND id <> ?', [$label, (int) $id])) {
            $this->flash('error', 'Rekening lawan tersebut sudah ada.');
            return $this->back('counterparties');
        }
        $renamed = 0;
        Db::tx(function () use ($id, $label, &$renamed) {
            if ($id === '') {
                $new = Db::insert('counterparty_accounts', ['label' => $label]);
                Audit::log('cpa_create', 'counterparty_account', (string) $new, "Tambah rekening lawan $label", null, ['label' => $label]);
            } else {
                $old = Db::one('SELECT * FROM counterparty_accounts WHERE id = ?', [(int) $id]);
                if (!$old) { throw new \App\Core\HttpException('Rekening lawan tidak ditemukan.', 404); }
                $active = $this->req->bool('is_active') ? 1 : 0;
                Db::update('counterparty_accounts', (int) $id, ['label' => $label, 'is_active' => $active]);
                if ($old['label'] !== $label) {
                    // transaksi menyimpan salinan teks; ikut diperbarui agar tetap konsisten dengan master
                    $renamed = Db::q('UPDATE transactions SET counterparty_detail = ? WHERE counterparty_detail = ?', [$label, $old['label']])->rowCount();
                }
                Audit::log('cpa_update', 'counterparty_account', $id, "Ubah rekening lawan $label" . ($renamed ? " ($renamed transaksi diperbarui)" : ''), ['label' => $old['label'], 'is_active' => $old['is_active']], ['label' => $label, 'is_active' => $active]);
            }
            Version::bump('master');
            if ($renamed) { Version::bump('transactions'); }
        });
        $this->flash('success', 'Rekening lawan tersimpan.' . ($renamed ? " $renamed transaksi diperbarui." : ''));
        return $this->back('counterparties');
    }

    /* ---------- saldo awal rekening ---------- */
    public function saveOpening(string $id): Response
    {
        $acc = Db::one('SELECT * FROM accounts WHERE id = ?', [(int) $id]);
        if (!$acc) {
            throw new \App\Core\HttpException('Rekening tidak ditemukan.', 404);
        }
        $open = parse_amount($this->req->str('opening_balance') ?: '0', true);
        $date = Filters::date($this->req->str('opening_date'));
        if ($open === null || $date === null) {
            $this->flash('error', 'Saldo awal harus berupa nominal valid dan tanggal saldo awal wajib diisi.');
            return $this->back('opening');
        }
        $first = Db::val('SELECT MIN(tx_date) FROM transactions WHERE account_id = ?', [(int) $id]);
        if ($first && $date > $first) {
            $this->flash('error', 'Tanggal saldo awal ' . $acc['code'] . ' tidak boleh setelah transaksi pertamanya (' . fmt_date($first) . ').');
            return $this->back('opening');
        }
        if ($open === bcadd((string) $acc['opening_balance'], '0', 2) && $date === $acc['opening_date']) {
            $this->flash('warn', 'Tidak ada perubahan pada saldo awal ' . $acc['code'] . '.');
            return $this->back('opening');
        }
        Db::tx(function () use ($id, $acc, $open, $date) {
            Db::update('accounts', (int) $id, ['opening_balance' => $open, 'opening_date' => $date]);
            Audit::log('account_opening', 'account', $id, 'Ubah saldo awal rekening ' . $acc['code'],
                ['opening_balance' => $acc['opening_balance'], 'opening_date' => $acc['opening_date']],
                ['opening_balance' => $open, 'opening_date' => $date]);
            Version::bump('master');
            Version::bump('transactions');   // saldo, dashboard, laporan, dan proyeksi ikut berubah
        });
        $this->flash('success', 'Saldo awal ' . $acc['code'] . ' tersimpan.');
        return $this->back('opening');
    }

    /* ---------- pemetaan kategori Excel -> kategori + proyek ---------- */
    public function saveMapping(string $id): Response
    {
        $m = Db::one('SELECT * FROM category_map WHERE id = ?', [(int) $id]);
        if (!$m) {
            throw new \App\Core\HttpException('Pemetaan tidak ditemukan.', 404);
        }
        $cat = $this->req->int('category_id');
        $proj = $this->req->int('project_id');
        if ($cat <= 0 || !Db::one('SELECT id FROM categories WHERE id = ?', [$cat]) || ($proj > 0 && !Db::one('SELECT id FROM projects WHERE id = ?', [$proj]))) {
            $this->flash('error', 'Pilih kategori yang valid.');
            return $this->back('mapping');
        }
        $n = (int) Db::tx(function () use ($m, $cat, $proj) {
            Db::update('category_map', (int) $m['id'], ['category_id' => $cat, 'project_id' => $proj > 0 ? $proj : null]);
            $n = Db::q('UPDATE transactions SET category_id = ?, project_id = ? WHERE category_raw = ?', [$cat, $proj > 0 ? $proj : null, $m['raw_text']])->rowCount();
            Audit::log('category_map_update', 'category_map', (string) $m['id'], "Pemetaan '{$m['raw_text']}' diubah ($n transaksi diperbarui)", ['category_id' => $m['category_id'], 'project_id' => $m['project_id']], ['category_id' => $cat, 'project_id' => $proj > 0 ? $proj : null]);
            Version::bump('master');
            Version::bump('transactions');
            return $n;
        });
        $this->flash('success', "Pemetaan disimpan; $n transaksi diperbarui.");
        return $this->back('mapping');
    }
}
