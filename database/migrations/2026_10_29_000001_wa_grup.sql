-- Grup WhatsApp untuk share laporan harian/mingguan/bulanan/rekap per cabang.
-- 1 cabang hanya masuk 1 grup WA (ditegaskan oleh admin pusat).
-- Admin pusat yang CRUD, PIC hanya read-only (lihat cabang & tombol share).

CREATE TABLE IF NOT EXISTS wa_grup (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    nama_grup   VARCHAR(100) NOT NULL,
    link_invite VARCHAR(255) NULL,
    keterangan  VARCHAR(255) NULL,
    dibuat_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_nama_grup (nama_grup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 1 cabang hanya masuk 1 grup WA. NULL = belum di-mapping.
ALTER TABLE cabang
    ADD COLUMN IF NOT EXISTS id_wa_grup INT NULL AFTER id_cabang;

ALTER TABLE cabang
    ADD INDEX IF NOT EXISTS idx_cabang_wa_grup (id_wa_grup);
