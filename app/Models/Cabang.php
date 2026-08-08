<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Capsule\Manager as Capsule;

class Cabang extends Model
{
    protected $table = 'cabang';

    protected $primaryKey = 'id_cabang';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'nama_cabang',
        'alamat',
        'no_telp',
        'nama_pengelola',
        'no_rekening',
        'nama_bank',
    ];

    /**
     * Relasi Cabang ke Investor
     */
    public function investor(): BelongsToMany
    {
        return $this->belongsToMany(
            Investor::class,
            'cabang_investor',
            'id_cabang',
            'id_investor',
            'id_cabang',
            'id_investor'
        )->withPivot([
            'id',
            'tgl_mulai',
            'tgl_selesai',
        ]);
    }

    /**
     * Mengganti investor pada cabang.
     *
     * 1 cabang hanya memiliki 1 investor.
     */
    public function ChangeInvestor(
        int $idInvestor,
        ?string $tglMulai = null,
        ?string $tglSelesai = null
    ): void {
        Capsule::connection()->transaction(function () use (
            $idInvestor,
            $tglMulai,
            $tglSelesai
        ) {

            // Hapus investor yang sedang terhubung
            $this->investor()->detach();

            // Hubungkan investor baru
            $this->investor()->attach($idInvestor, [
                'tgl_mulai' => $tglMulai,
                'tgl_selesai' => $tglSelesai,
            ]);
        });
    }

    public function ActiveInvestor(): ?Investor
    {
        $today = date('Y-m-d');

        return $this->investor()
            ->wherePivot('tgl_mulai', '<=', $today)
            ->where(function ($query) use ($today) {
                $query->whereNull('cabang_investor.tgl_selesai')
                    ->orWhere('cabang_investor.tgl_selesai', '>=', $today);
            })
            ->first();
    }
}
