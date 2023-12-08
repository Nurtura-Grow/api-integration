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

}
