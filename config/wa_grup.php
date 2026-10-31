<?php
/**
 * config/wa_grup.php — helper untuk fitur Share WA Group.
 *
 * Dipakai oleh:
 *   admin_pusat/data_wa_grup.php      (CRUD + mapping cabang)
 *   admin_pusat/share_wa_grup.php     (menu share utama)
 *   admin_pic/share_wa_grup.php       (menu share scoped ke cabang PIC)
 */

if (!function_exists('wa_grup_list')) {
    /**
     * Daftar grup WA + jumlah cabang + nama investor (kalau terkait investor).
     *
     * Mode anggota per grup:
     *   - Kalau g.id_investor terisi: anggota = cabang dari cabang_investor
     *     yang masih AKTIF (tgl_selesai NULL atau >= today).
     *   - Kalau NULL: anggota = cabang yang id_wa_grup = g.id (manual mapping).
     *
     * Kalau $cabang_ids diberi (dari PIC yang hanya pegang sebagian cabang),
     * jml_cabang dihitung HANYA dari cabang-cabang itu, dan grup yang tidak
     * punya cabang dari daftar itu disembunyikan.
     *
     * Return: array of [ 'id', 'nama_grup', 'link_invite', 'keterangan',
     *                    'id_investor', 'nama_investor', 'jml_cabang' ]
     */
    function wa_grup_list(mysqli $conn, ?array $cabang_ids = null): array
    {
        // Subquery untuk hitung jml cabang per grup, menggabungkan 2 mode:
        //   - Mode investor (g.id_investor terisi)
        //   - Mode manual   (cabang.id_wa_grup = g.id)
        // Kalau $cabang_ids diberi, batasi di dalam subquery.
        $filter_in = '';
        $types = '';
        $params = [];
        if ($cabang_ids !== null) {
            $cabang_ids = array_values(array_filter(array_map('intval', $cabang_ids)));
            if (!$cabang_ids) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($cabang_ids), '?'));
            $filter_in = "AND c.id_cabang IN ($placeholders)";
            // $filter_in hanya sekali di query (di subquery), jadi bind sekali.
            $types  = str_repeat('i', count($cabang_ids));
            $params = $cabang_ids;
        }

        $sql = "
            SELECT g.id, g.nama_grup, g.link_invite, g.keterangan,
                   g.id_investor, i.nama_investor,
                   (
                     SELECT COUNT(DISTINCT c.id_cabang) FROM cabang c
                     WHERE (
                       (g.id_investor IS NOT NULL AND EXISTS (
                           SELECT 1 FROM cabang_investor ci
                           WHERE ci.id_cabang = c.id_cabang
                             AND ci.id_investor = g.id_investor
                             AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= CURDATE())
                       ))
                       OR
                       (g.id_investor IS NULL AND c.id_wa_grup = g.id)
                     )
                     $filter_in
                   ) AS jml_cabang
            FROM wa_grup g
            LEFT JOIN investor i ON i.id_investor = g.id_investor
            ORDER BY g.nama_grup ASC
        ";
        $stmt = $conn->prepare($sql);
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Filter grup yg jml_cabang=0 kalau user adalah PIC (scope sempit).
        if ($cabang_ids !== null) {
            $rows = array_values(array_filter($rows, fn($r) => (int)$r['jml_cabang'] > 0));
        }
        return $rows;
    }
}

if (!function_exists('wa_grup_cabang')) {
    /**
     * Daftar cabang yang masuk ke 1 grup WA.
     *
     * Dua mode (bergantung wa_grup.id_investor):
     *   - id_investor terisi → ambil cabang dari cabang_investor yang masih AKTIF.
     *   - id_investor NULL   → ambil cabang dari cabang.id_wa_grup (manual).
     *
     * Kalau $filter_cabang_ids diberi (PIC), hanya return cabang yang ada di
     * daftar itu.
     *
     * Return: array of [ 'id_cabang', 'nama_cabang' ]
     */
    function wa_grup_cabang(mysqli $conn, int $id_grup, ?array $filter_cabang_ids = null): array
    {
        // 1. Cek apakah grup punya id_investor
        $st = $conn->prepare("SELECT id_investor FROM wa_grup WHERE id = ?");
        $st->bind_param('i', $id_grup);
        $st->execute();
        $g = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$g) return [];
        $id_investor = $g['id_investor'] !== null ? (int) $g['id_investor'] : null;

        if ($id_investor !== null) {
            // Mode investor: ambil cabang dari cabang_investor
            if ($filter_cabang_ids !== null) {
                $filter_cabang_ids = array_values(array_filter(array_map('intval', $filter_cabang_ids)));
                if (!$filter_cabang_ids) return [];
                $placeholders = implode(',', array_fill(0, count($filter_cabang_ids), '?'));
                $types = 'i' . str_repeat('i', count($filter_cabang_ids));
                $sql = "SELECT DISTINCT c.id_cabang, c.nama_cabang
                        FROM cabang c
                        INNER JOIN cabang_investor ci ON ci.id_cabang = c.id_cabang
                        WHERE ci.id_investor = ?
                          AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= CURDATE())
                          AND c.id_cabang IN ($placeholders)
                        ORDER BY c.nama_cabang ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, $id_investor, ...$filter_cabang_ids);
            } else {
                $sql = "SELECT DISTINCT c.id_cabang, c.nama_cabang
                        FROM cabang c
                        INNER JOIN cabang_investor ci ON ci.id_cabang = c.id_cabang
                        WHERE ci.id_investor = ?
                          AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= CURDATE())
                        ORDER BY c.nama_cabang ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $id_investor);
            }
        } else {
            // Mode manual: ambil cabang dari cabang.id_wa_grup
            if ($filter_cabang_ids !== null) {
                $filter_cabang_ids = array_values(array_filter(array_map('intval', $filter_cabang_ids)));
                if (!$filter_cabang_ids) return [];
                $placeholders = implode(',', array_fill(0, count($filter_cabang_ids), '?'));
                $types = 'i' . str_repeat('i', count($filter_cabang_ids));
                $sql = "SELECT id_cabang, nama_cabang FROM cabang
                        WHERE id_wa_grup = ? AND id_cabang IN ($placeholders)
                        ORDER BY nama_cabang ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, $id_grup, ...$filter_cabang_ids);
            } else {
                $sql = "SELECT id_cabang, nama_cabang FROM cabang
                        WHERE id_wa_grup = ?
                        ORDER BY nama_cabang ASC";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('i', $id_grup);
            }
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('wa_grup_get')) {
    /** Satu grup WA (termasuk id_investor). Return null kalau tidak ada. */
    function wa_grup_get(mysqli $conn, int $id_grup): ?array
    {
        $stmt = $conn->prepare("SELECT id, nama_grup, link_invite, keterangan, id_investor FROM wa_grup WHERE id = ?");
        $stmt->bind_param('i', $id_grup);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('investor_list_aktif')) {
    /** Semua investor aktif + jumlah cabang yang masih di-invest. */
    function investor_list_aktif(mysqli $conn): array
    {
        $res = $conn->query("
            SELECT i.id_investor, i.nama_investor,
                   (SELECT COUNT(DISTINCT ci.id_cabang) FROM cabang_investor ci
                    WHERE ci.id_investor = i.id_investor
                      AND (ci.tgl_selesai IS NULL OR ci.tgl_selesai >= CURDATE())
                   ) AS jml_cabang
            FROM investor i
            WHERE i.status = 'aktif'
            ORDER BY i.nama_investor ASC
        ");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('wa_grup_cabang_tanpa_grup')) {
    /** Daftar cabang yang belum di-mapping ke grup manapun. Dipakai di CRUD. */
    function wa_grup_cabang_tanpa_grup(mysqli $conn): array
    {
        $res = $conn->query("SELECT id_cabang, nama_cabang FROM cabang
                             WHERE id_wa_grup IS NULL ORDER BY nama_cabang ASC");
        return $res->fetch_all(MYSQLI_ASSOC);
    }
}
