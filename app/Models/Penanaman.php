<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Penanaman extends Model
{
    use HasFactory;
    protected $table = 'penanaman';
    protected $primaryKey = 'id_penanaman';
    protected $guarded = [
        'id_penanaman'
    ];

    public static $jumlahHST = 60;

    public static function activePenanamanData()
    {
        return self::where('deleted_by', null)->where('deleted_at', null)->get();
    }

    public static function getDataPenanaman($idLahan)
    {
        return self::activePenanamanData()->where('id_lahan', $idLahan);
    }

    public static function calculateHST($penanamanId)
    {
        $penanaman = Penanaman::find($penanamanId);
        $tanggalTanam = Carbon::parse($penanaman->tanggal_tanam);

        $hariTujuan = Carbon::now();

        if ($penanaman->tanggal_panen != null) {
            $hariTujuan = Carbon::parse($penanaman->tanggal_panen);
        }

        $dayDifference = $hariTujuan->diffInDays($tanggalTanam);

        return $dayDifference;
    }

    public static  function calculateHSTPercentage($penanamanId)
    {
        $dayDifference = self::calculateHST($penanamanId);
        $percentage = ($dayDifference / self::$jumlahHST) * 100;

        // Round to integer
        $percentage = intval(round($percentage));

        return $percentage;
    }

    public function data_sensor(): HasMany
    {
        return $this->hasMany(DataSensor::class, 'id_penanaman', 'id_penanaman');
    }

    public function rekomendasi_pengairan(): HasMany
    {
        return $this->hasMany(RekomendasiPengairan::class, 'id_penanaman', 'id_penanaman');
    }

}
