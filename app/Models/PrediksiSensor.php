<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PrediksiSensor extends Model
{
    use HasFactory;
    protected $table = "prediksi_sensor";
    protected $primaryKey = 'id_prediksi_sensor';
    protected $guarded = [
        'id_prediksi_sensor'
    ];

}
