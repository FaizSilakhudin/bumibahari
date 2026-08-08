<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Investor extends Model
{
    protected $table = 'investor';

    protected $primaryKey = 'id_investor';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'nama_investor',
        'no_hp',
        'email',
        'alamat',
        'no_rekening',
        'nama_bank',
        'atas_nama_rekening',
        'tgl_mulai_investasi',
        'tgl_selesai_investasi',
        'surat_perjanjian',
        'status',
    ];

    protected $casts = [
        'tgl_mulai_investasi' => 'date',
        'tgl_selesai_investasi' => 'date',
    ];

    /**
     * Relasi Investor ke Cabang
     */
    public function cabang(): BelongsToMany
    {
        return $this->belongsToMany(
            Cabang::class,
            'cabang_investor',
            'id_investor',
            'id_cabang',
            'id_investor',
            'id_cabang'
        )->withPivot([
            'id',
            'tgl_mulai',
            'tgl_selesai',
        ]);
    }
}
