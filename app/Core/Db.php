<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use PDOStatement;
use Throwable;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                env('DB_HOST', '127.0.0.1'),
                env('DB_PORT', '3306'),
                env('DB_NAME', 'cashflow_hme')
            );
            try {
                self::$pdo = new PDO($dsn, (string) env('DB_USER'), (string) env('DB_PASS'), [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);
                self::$pdo->exec("SET time_zone = '+07:00', sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
            } catch (PDOException $e) {
                // jangan bocorkan kredensial/DSN ke pengguna
                app_log('DB connect failed: ' . $e->getCode());
                throw new \RuntimeException('Koneksi database gagal.', 0);
            }
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $params = [])
    {
        $r = self::q($sql, $params)->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = 'INSERT INTO `' . $table . '` (`' . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')';
        self::q($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, int $id, array $data, string $idCol = 'id'): void
    {
        $set = implode(',', array_map(static fn($c) => '`' . $c . '`=?', array_keys($data)));
        self::q('UPDATE `' . $table . '` SET ' . $set . ' WHERE `' . $idCol . '`=?', [...array_values($data), $id]);
    }

    /** Jalankan callback dalam transaksi DB; rollback bila ada exception. */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if ($pdo->inTransaction()) {
            return $fn();
        }
        $pdo->beginTransaction();
        try {
            $r = $fn();
            $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
