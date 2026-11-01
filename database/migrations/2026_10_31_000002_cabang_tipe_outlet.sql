-- Tipe outlet per cabang (Small / Medium / Large / Extra Large).
-- Kolom nullable supaya cabang existing tidak error — baru diisi lewat
-- form Edit atau pembuatan cabang baru. ENUM biar nilai konsisten.

ALTER TABLE cabang
    ADD COLUMN IF NOT EXISTS tipe_outlet
        ENUM('Small','Medium','Large','Extra Large') NULL
        AFTER atas_nama_cabang;
