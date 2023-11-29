<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SumberDataSensor extends Model
{
    use HasFactory;

    protected $table = 'sumber_data_sensor';
    protected $primaryKey = 'id_sumber_data';
    protected $guarded = [
        'id_sumber_data'
    ];

    public function data_sensor(): HasMany
    {
        return $this->hasMany(DataSensor::class, 'id_sumber_data', 'id_sumber_data');
    }
}
