<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CabangInvestor extends Model
{
    protected $table = 'cabang_investor';

    protected $primaryKey = 'id';

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = [
        'id_cabang',
        'id_investor',
        'tgl_mulai',
        'tgl_selesai',
    ];

    protected $casts = [
        'tgl_mulai' => 'date',
        'tgl_selesai' => 'date',
    ];

    /**
     * Relasi ke Cabang
     */
    public function cabang(): BelongsTo
    {
        return $this->belongsTo(
            Cabang::class,
            'id_cabang',
            'id_cabang'
        );
    }

    /**
     * Relasi ke Investor
     */
    public function investor(): BelongsTo
    {
        return $this->belongsTo(
            Investor::class,
            'id_investor',
            'id_investor'
        );
    }
}
