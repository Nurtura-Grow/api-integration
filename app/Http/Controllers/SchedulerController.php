<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\LogAksi;
use App\Models\Message;
use App\Models\Penanaman;
use App\Models\DataSensor;
use App\Models\TipeInstruksi;
use App\Models\IrrigationController;
use App\Models\FertilizerController;
use App\Models\RekomendasiPengairan;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SchedulerController extends Controller
{
    /**
     * Fungsi ini dijalankan setiap 1 jam untuk memasukkan data ke
     * database rekomendasi_pengairan dan irrigation_controller.
     *
     * 1. Ambil data 1 jam terakhir dari DataSensor.
     * 2. Jadikan rata-rata lalu ubah ke json dengan format:
     *    {
     *        temperature: ...,
     *        Humidity: ...,
     *        SoilMoisture: ...
     *    }
     * 3. Kirim data nomor 2 ke route(ml.irrigation).
     * 4. Kirim data nomor 2 ke route(ml.predict):
     * 5. Data nomor 3 dan 4['irrigation'] dikirim ke function saveDatatoRekomendasiPengairan.
     * @return void
     */
    public static function schedule1Hour()
    {
        Log::info('Scheduler 1 hour is running');
        // Ambil data 1 jam terakhir (seluruh datanya)
        $dataTerakhir = DataSensor::where('timestamp_pengukuran', '>=', Carbon::now()->subHour())
            ->where('timestamp_pengukuran', '<=', Carbon::now())
            ->get();

        if ($dataTerakhir->count() < 1) {
            return;
        }

        // Jadikan rata-rata lalu ubah ke json
        $temperature = $dataTerakhir->avg('suhu_udara');
        $humidity = $dataTerakhir->avg('kelembapan_udara');
        $soilMoisture = $dataTerakhir->avg('kelembapan_tanah');

        $data = [
            'temperature' => $temperature,
            'Humidity' => $humidity,
            'SoilMoisture' => $soilMoisture
        ];

        $id_penanaman = DataSensor::orderBy('timestamp_pengukuran', 'desc')->first()->id_penanaman;

        // Kirim data ke route(ml.irrigation)
        $response_irrigation = Http::post(route('ml.irrigation'), $data);

        if ($response_irrigation->successful()) {
            $response_irrigation = $response_irrigation->json();
            // Kasih Tambahan 1 menit supaya scheduler bisa jalan untuk mengirim perintah
            $time_irrigation = Carbon::now()->addMinute()->format('Y-m-d H:i');

            // Simpan data ke database rekomendasi_pengairan
            self::saveDatatoRekomendasiPengairan($id_penanaman, $response_irrigation['data'], $time_irrigation);

            // Kirim data ke route(ml.predict)
            $response_predict = Http::post(route('ml.predict'), $data);
            if ($response_predict->successful()) {
                $response_predict = $response_predict->json();
                $time_prediksi = $response_predict['data']['predict']['Time'];

                // Simpan data ke database rekomendasi_pemupukan
                self::saveDatatoRekomendasiPengairan($id_penanaman, $response_predict['data']['irrigation']['data'], $time_prediksi);
            }
        }

        return;
    }

    /**
     * Fungsi ini dijalankan untuk menyimpan 2 data ke database
     * 1. Simpan data yang diterima ke database rekomendasi_pengairan.
     *    Notes: Cari message apakah sudah ada, jika ada update, jika tidak ada buat baru
     * 2. Jika alatnya hidup, simpan data tersebut ke
     *    database irrigation_controller.
     *
     * Data yang disimpan:
     * - willSend = 1
     * - isSent = 0
     * - mode = auto
     *
     */
    static function saveDatatoRekomendasiPengairan($id_penanaman, $data, $time_irrigation)
    {
        $debit = 7;

        // Cari message apakah sudah ada, jika ada update, jika tidak ada buat baru
        $kondisi = Message::firstOrCreate([
            'message' => $data['Kondisi']
        ])->id;

        $saran = Message::firstOrCreate([
            'message' => $data['Saran']
        ])->id;

        if ($data['Informasi Kluster'] == null || $data == null) return;

        $nyalakanAlat = $data['Informasi Kluster']['nyala'];
        $durasiNyala = $data['Informasi Kluster']['waktu'];

        $volumeLiter = $durasiNyala / 60 * $debit; // Debit : 7 liter per menit

        // Simpan data ke database rekomendasi_pengairan
        $id_rekomendasi_pengairan = RekomendasiPengairan::create([
            'nyalakan_alat' => $nyalakanAlat,
            'durasi_detik' => $durasiNyala,
            'kondisi' => $kondisi,
            'saran' => $saran,
            'tanggal_rekomendasi' => $time_irrigation,
        ])->id_rekomendasi_air;

        if ($nyalakanAlat) {
            // Simpan data ke database irrigation_controller
            IrrigationController::create([
                'id_penanaman' => $id_penanaman,
                'id_rekomendasi_air' => $id_rekomendasi_pengairan,
                'mode' => 'auto',
                'willSend' => 1,
                'isSent' => 0,
                'waktu_mulai' => $time_irrigation,
                'volume_liter' => $volumeLiter,
                'durasi_detik' => $durasiNyala,
                'waktu_selesai' => Carbon::parse($time_irrigation)->addSeconds($durasiNyala),
            ]);
        }

        return;
    }

    /**
     * Fungsi ini dijalankan setiap 1 menit untuk memeriksa apakah
     * ada data pengairan yang harus dikirim ke Antares.
     * 1. cek apakah ada data pengairan/pemupukan yang sedang dikirim ke Antares
     *      a. kalau ada, cek apakah sudah selesai
     *          i. kalau sudah selesai, ubah sedang_berjalan menjadi 0, kirim data close ke Antares
     *          ii. kalau belum selesai, selesaikan fungsi
     * b. kalau tidak ada, lanjut ke langkah 2
     * 2. Ambil data irrigation_controller.
     * 3. Periksa apakah willSend = 1 dan isSent = 0:
     *    a. Jika tidak sesuai, selesaikan fungsi.
     *    b. Jika sesuai, lanjut ke langkah 4.
     * 4. Periksa apakah waktu_mulai sesuai dengan sekarang:
     *    a. Jika tidak sesuai, selesaikan fungsi.
     *    b. Jika sesuai, lanjut ke langkah 5.
     * 5. Kirim data open ke antares
     * 6. Update data isSent = 1.
     * 7. Tambahkan data ke log_aksi.
     *
     * @return void
     */
    public static function scheduleIrrigation()
    {
        Log::info("schedule irrigation started");
        // Cek di log aksi, apakah sudah ada data pengairan yang dikirim ke Antares
        // Jika sudah ada, cek apakah waktu sekarang (di irrigation controller) == waktu selesai
        // kalau tidak, selesaikan fungsi
        // kalau iya, ubah sedang_berjalan menjadi 0
        $waktu_sekarang = Carbon::now()->format('Y-m-d H:i');
        $id_penanaman = Penanaman::where('alat_terpasang', true)->first()->id_penanaman;
        $tipe = TipeInstruksi::where('nama_tipe', 'pengairan')->first()->id_tipe_instruksi;

        $logAksi = LogAksi::where('sedang_berjalan', true);

        // Kalau ada yang sedang berjalan, cek apakah sudah selesai
        if ($logAksi->count() > 0) {
            if ($logAksi->first()->id_tipe_instruksi != $tipe) {
                return;
            }

            $waktu_selesai = Carbon::parse($logAksi->first()->irrigation_controller->waktu_selesai)->format('Y-m-d H:i');

            // Cek apakah alat sudah selesai berjalan
            if ($waktu_sekarang == $waktu_selesai) {
                // Send downlink to Antares to turn off the device
                $kode = Self::convertToAntaresCode('mati', 'air');
                $response = Http::post(route('antares.downlink'), [
                    'data' => $kode
                ]);

                // Ubah sedang_berjalan menjadi 0
                $logAksi->update([
                    'sedang_berjalan' => false,
                    'updated_at' => Carbon::now(),
                ]);
            }

            return;
        }

        // Kalau tidak ada aksi yang sedang berjalan
        // Ambil data irrigation_controller
        $now = Carbon::now();

        $irrigation_controller = IrrigationController::where('willSend', 1)
            ->where('isSent', 0)
            ->whereDate('waktu_mulai', '=', $now->format('Y-m-d'))
            ->whereTime('waktu_mulai', '=', $now->format('H:i'))
            ->get();

        // Jika tidak ada data, selesaikan fungsi
        if ($irrigation_controller->count() <= 0) {
            return;
        }

        $irrigation_controller = $irrigation_controller->first();

        // Cek apakah sudah waktunya untuk mengirimkan data di antares
        $waktu_mulai = Carbon::parse($irrigation_controller->waktu_mulai)->format('Y-m-d H:i');
        if ($waktu_mulai != $waktu_sekarang) {
            return;
        }

        // Ambil data durasi dari irrigation_controller
        $durasi = $irrigation_controller->durasi_detik;
        $kode = self::convertToAntaresCode('nyala', 'air');

        // Kirim data ke Antares route(antares.downlink)
        $response = Http::post(route('antares.downlink'), [
            'data' => $kode
        ]);

        // Jika berhasil, update data isSent = 1
        if ($response->successful()) {
            $irrigation_controller->update([
                'isSent' => 1
            ]);

            // Tambahkan data ke log_aksi
            $id_irrigation_controller = $irrigation_controller->id_irrigation_controller;
            LogAksi::create([
                'id_penanaman' => $id_penanaman,
                'id_tipe_instruksi' => $tipe,
                'id_irrigation_controller' => $id_irrigation_controller,
                'durasi' => $durasi,
                'sedang_berjalan' => true,
                'created_by' => '1',
                'updated_by' => '1',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        return;
    }

    static function convertToAntaresCode($perintah, $tipe)
    {
        switch ($tipe) {
            case 'air':
                $tipe_kode = config('services.device.air');
                break;
            case 'pupuk':
                $tipe_kode = config('services.device.pupuk');
                break;
            default:
                $tipe_kode = config('services.device.air');
                break;
        }

        // Ubah durasi menjadi kode alat
        switch ($perintah) {
            case 'nyala':
                $kode = $tipe_kode . config('services.device.nyala');
                break;
            case 'mati':
                $kode = $tipe_kode . config('services.device.mati');
                break;
            default:
                $kode = $tipe_kode . config('services.device.mati');
                break;
        }

        return $kode;
    }


    /**
     * Fungsi ini dijalankan setiap 1 menit untuk memeriksa apakah
     * ada data pupuk yang harus dikirim ke Antares.
     *
     * 1. cek apakah ada data pemupukan/pengairan yang sedang berjalan di alat
     *      a. kalau ada, cek apakah sudah selesai
     *          i. kalau sudah selesai, ubah sedang_berjalan menjadi 0, kirim perintah close ke Antares
     *          ii. kalau belum selesai, selesaikan fungsi
     * b. kalau tidak ada, lanjut ke langkah 2
     * 2. Ambil data fertilizer_control.
     * 3. Periksa apakah waktu_mulai sesuai dengan sekarang:
     *    a. Jika tidak sesuai, selesaikan fungsi.
     *    b. Jika sesuai, lanjut ke langkah 4.
     * 4. Periksa apakah willSend = 1 dan isSent = 0:
     *    a. Jika tidak sesuai, selesaikan fungsi.
     *    b. Jika sesuai, lanjut ke langkah 5.
     * 5. Kirim data open ke antares
     * 6. Update data isSent = 1.
     * 7. Tambahkan data ke log_aksi.
     *
     * @return void
     */
    public static function scheduleFertilizer()
    {
        Log::info("Schedule Fertilizer");

        // Cek di log aksi, apakah sudah ada data pengairan yang dikirim ke Antares
        // Jika sudah ada, cek apakah waktu sekarang (di irrigation controller) == waktu selesai
        // kalau tidak, selesaikan fungsi
        // kalau iya, ubah sedang_berjalan menjadi 0
        $waktu_sekarang = Carbon::now()->format('Y-m-d H:i');
        $id_penanaman = Penanaman::where('alat_terpasang', true)->first()->id_penanaman;
        $tipe = TipeInstruksi::where('nama_tipe', 'pemupukan')->first()->id_tipe_instruksi;

        $logAksi = LogAksi::where('sedang_berjalan', true);

        // Kalau ada yang sedang berjalan, cek apakah sudah selesai
        if ($logAksi->count() > 0) {
            // if tipe logAksi == $tipe
            if ($logAksi->first()->id_tipe_instruksi != $tipe) {
                return;
            }
            $waktu_selesai = Carbon::parse($logAksi->first()->fertilizer_controller->waktu_selesai)->format('Y-m-d H:i');

            // Cek apakah alat sudah selesai berjalan
            if ($waktu_sekarang == $waktu_selesai) {
                // Send downlink to Antares to turn off the device
                $kode = Self::convertToAntaresCode('mati', 'pupuk');
                $response = Http::post(route('antares.downlink'), [
                    'data' => $kode
                ]);

                // Ubah sedang_berjalan menjadi 0
                $logAksi->update([
                    'sedang_berjalan' => false,
                    'updated_at' => Carbon::now(),
                ]);
            }

            return;
        }

        // Ambil data fertilizer_control
        $now = Carbon::now();
        $fertilizer_control = FertilizerController::where('willSend', 1)
            ->where('isSent', 0)
            ->whereDate('waktu_mulai', '=', $now->format('Y-m-d'))
            ->whereTime('waktu_mulai', '=', $now->format('H:i'))
            ->get();


        // Jika tidak ada data, selesaikan fungsi
        if ($fertilizer_control->count() <= 0) {
            return;
        }

        $fertilizer_control = $fertilizer_control->first();

        // Cek apakah sudah waktunya untuk mengirimkan data di antares
        $waktu_mulai = Carbon::parse($fertilizer_control->waktu_mulai)->format('Y-m-d H:i');
        $waktu_sekarang = Carbon::now()->format('Y-m-d H:i');
        if ($waktu_mulai != $waktu_sekarang) {
            return;
        }

        // Ambil data durasi dari fertilizer_control
        $durasi = $fertilizer_control->durasi_detik;
        $kode = self::convertToAntaresCode('nyala', 'pupuk');

        // Kirim data ke Antares route(antares.downlink)
        $response = Http::post(route('antares.downlink'), [
            'data' => $kode
        ]);

        // Jika berhasil, update data isSent = 1
        if ($response->successful()) {
            $fertilizer_control->update([
                'isSent' => 1
            ]);

            // Tambahkan data ke log_aksi
            $id_fertilizer_control = $fertilizer_control->id_fertilizer_control;
            LogAksi::create([
                'id_penanaman' => $id_penanaman,
                'id_tipe_instruksi' => $tipe,
                'id_fertilizer_controller' => $id_fertilizer_control,
                'durasi' => $durasi,
                'sedang_berjalan' => true,
                'created_by' => '1',
                'updated_by' => '1',
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ]);
        }

        return;
    }
}
