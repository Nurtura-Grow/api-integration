<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FertilizerController extends Model
{
    use HasFactory;
    protected $table = "fertilizer_controller";
    protected $primaryKey = 'id_fertilizer_controller';
    protected $guarded = [
        'id_fertilizer_controller'
    ];

    public function penanaman()
    {
        return $this->belongsTo(Penanaman::class, 'id_penanaman', 'id_penanaman');
    }

    public function log_aksi()
    {
        return $this->hasOne(LogAksi::class, 'id_fertilizer_controller', 'id_fertilizer_controller');
    }
}
