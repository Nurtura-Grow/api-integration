<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function irrigation_controller(): BelongsTo
    {
        return $this->belongsTo(IrrigationController::class, 'id_irrigation_controller', 'id_irrigation_controller');
    }

    public function fertilizer_controller(): BelongsTo
    {
        return $this->belongsTo(FertilizerController::class, 'id_fertilizer_controller', 'id_fertilizer_controller');
    }
}
