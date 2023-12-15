<?php

namespace App\Models;

use App\Models\Penanaman;
use App\Models\SumberDataSensor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DataSensor extends Model
{
    use HasFactory;
    protected $table = 'data_sensor';
    protected $primaryKey = 'id_sensor';
    protected $guarded = [
        'id_sensor'
    ];
    public $timestamps = true;

    protected $fillable = [
        'id_penanaman', 'suhu', 'kelembapan_udara', 'kelembapan_tanah', 'ph_tanah', 'timestamp_pengukuran'
    ];
    public function penanaman(): BelongsTo
    {
        return $this->belongsTo(Penanaman::class, 'id_penanaman', 'id_penanaman');
    }
    public function sumber_data_sensor(): BelongsTo
    {
        return $this->belongsTo(SumberDataSensor::class, 'id_sumber_data', 'id_sumber_data');
    }
}
