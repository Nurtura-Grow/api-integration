<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipeInstruksi extends Model
{
    use HasFactory;
    protected $table = 'tipe_instruksi';
    protected $primaryKey = 'id_tipe_instruksi';
    protected $guarded = [
        'id_tipe_instruksi'
    ];

    public function log_aksi(): HasMany
    {
        return $this->hasMany(LogAksi::class, 'id_tipe_instruksi', 'id_tipe_instruksi');
    }
}
