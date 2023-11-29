<?php

namespace App\Models;

use App\Models\RekomendasiPemupukan;
use App\Models\RekomendasiPengairan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;
    protected $table = 'message';
    protected $primaryKey = 'id';
    protected $guarded = [
        'id'
    ];

    public function rekomendasi_pengairan_kondisi(): HasMany
    {
        return $this->hasMany(RekomendasiPengairan::class, 'kondisi', 'id');
    }

    public function rekomendasi_pengairan_saran(): HasMany
    {
        return $this->hasMany(RekomendasiPengairan::class, 'saran', 'id');
    }

    public function rekomendasi_pemupukan_pesan(): HasMany
    {
        return $this->hasMany(RekomendasiPemupukan::class, 'pesan', 'id');
    }
}
