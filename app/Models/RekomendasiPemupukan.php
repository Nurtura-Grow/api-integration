<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RekomendasiPemupukan extends Model
{
    use HasFactory;
    protected $table = 'rekomendasi_pemupukan';
    protected $primaryKey = 'id_rekomendasi_pemupukan';
    protected $guarded = [
        'id_rekomendasi_pemupukan'
    ];

    public $timestamps = false;

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'pesan', 'id');
    }
}
