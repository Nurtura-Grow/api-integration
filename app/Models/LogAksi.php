<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LogAksi extends Model
{
    use HasFactory;
    protected $table = 'log_aksi';
    protected $primaryKey = 'id_log_aksi';
    protected $guarded = [
        'id_log_aksi'
    ];

    public function tipe_instruksi(): BelongsTo
    {
        return $this->belongsTo(TipeInstruksi::class, 'id_tipe_instruksi', 'id_tipe_instruksi');
    }
}
