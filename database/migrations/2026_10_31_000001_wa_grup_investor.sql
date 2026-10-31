-- 1 grup WA = 1 investor (opsional).
-- Kalau wa_grup.id_investor terisi: anggota cabang di-derive otomatis dari
-- cabang_investor (relasi aktif saat ini). Admin pusat tidak perlu lagi
-- manual assign cabang satu-satu.
-- Kalau NULL: tetap pakai mode manual lama (cabang.id_wa_grup).

ALTER TABLE wa_grup
    ADD COLUMN IF NOT EXISTS id_investor INT NULL AFTER keterangan;

ALTER TABLE wa_grup
    ADD INDEX IF NOT EXISTS idx_wa_grup_investor (id_investor);
