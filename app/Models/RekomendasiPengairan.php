<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class RekomendasiPengairan extends Model
{
    use HasFactory;
    protected $table = 'rekomendasi_pengairan';
    protected $primaryKey = 'id_rekomendasi_air';
    protected $guarded = [
        'id_rekomendasi_air'
    ];
    public $timestamps = false;

    public function penanaman(): BelongsTo
    {
        return $this->belongsTo(Penanaman::class, 'id_penanaman', 'id_penanaman');
    }
    public function kondisi_message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'kondisi', 'id');
    }

    public function saran_message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'saran', 'id');
    }
}
