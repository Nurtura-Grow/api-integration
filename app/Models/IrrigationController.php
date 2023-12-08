<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IrrigationController extends Model
{
    use HasFactory;
    protected $table = "irrigation_controller";
    protected $primaryKey = 'id_irrigation_controller';
    protected $guarded = [
        'id_irrigation_controller'
    ];

    public function log_aksi()
    {
        return $this->hasOne(LogAksi::class, 'id_irrigation_controller', 'id_irrigation_controller');
    }
}
