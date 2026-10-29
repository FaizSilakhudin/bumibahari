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
     * Daftar grup WA + jumlah cabang di dalamnya.
     * Kalau $cabang_ids diberi (misal dari PIC yang hanya pegang sebagian cabang),
     * hanya mengembalikan grup yang memiliki minimal 1 cabang dari daftar itu +
     * jumlah cabang per grup juga dihitung HANYA dari daftar itu.
     *
     * Return: array of [ 'id', 'nama_grup', 'link_invite', 'keterangan', 'jml_cabang' ]
     */
    function wa_grup_list(mysqli $conn, ?array $cabang_ids = null): array
    {
        if ($cabang_ids !== null) {
            $cabang_ids = array_values(array_filter(array_map('intval', $cabang_ids)));
            if (!$cabang_ids) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($cabang_ids), '?'));
            $types        = str_repeat('i', count($cabang_ids));
            $sql = "
                SELECT g.id, g.nama_grup, g.link_invite, g.keterangan,
                       COUNT(c.id_cabang) AS jml_cabang
                FROM wa_grup g
                INNER JOIN cabang c ON c.id_wa_grup = g.id AND c.id_cabang IN ($placeholders)
                GROUP BY g.id, g.nama_grup, g.link_invite, g.keterangan
                ORDER BY g.nama_grup ASC
            ";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$cabang_ids);
        } else {
            $sql = "
                SELECT g.id, g.nama_grup, g.link_invite, g.keterangan,
                       (SELECT COUNT(*) FROM cabang c WHERE c.id_wa_grup = g.id) AS jml_cabang
                FROM wa_grup g
                ORDER BY g.nama_grup ASC
            ";
            $stmt = $conn->prepare($sql);
        }
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('wa_grup_cabang')) {
    /**
     * Daftar cabang yang masuk ke 1 grup WA.
     * Kalau $filter_cabang_ids diberi (PIC), hanya kembalikan cabang yang
     * ADA di daftar itu (scope PIC).
     *
     * Return: array of [ 'id_cabang', 'nama_cabang' ]
     */
    function wa_grup_cabang(mysqli $conn, int $id_grup, ?array $filter_cabang_ids = null): array
    {
        if ($filter_cabang_ids !== null) {
            $filter_cabang_ids = array_values(array_filter(array_map('intval', $filter_cabang_ids)));
            if (!$filter_cabang_ids) {
                return [];
            }
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
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('wa_grup_get')) {
    /** Satu grup WA. Return null kalau tidak ada. */
    function wa_grup_get(mysqli $conn, int $id_grup): ?array
    {
        $stmt = $conn->prepare("SELECT id, nama_grup, link_invite, keterangan FROM wa_grup WHERE id = ?");
        $stmt->bind_param('i', $id_grup);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
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
