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
     * This function runs every 1 hour to insert data into
     * the recommendation_irrigation and irrigation_controller databases.
     *
     * 1. Retrieve data from the last 1 hour from DataSensor.
     * 2. Calculate the average and convert it to JSON format:
     *    {
     *        temperature: ...,
     *        humidity: ...,
     *        soilMoisture: ...
     *    }
     * 3. Send the data from step 2 to the route (ml.irrigation).
     * 4. Send the data from step 2 to the route (ml.predict).
     * 5. Send the data from step 3 and step 4['irrigation'] to the function saveDataToRecommendationIrrigation.
     * @return void
     */

    public static function schedule1Hour()
    {
        Log::info('Scheduler 1 hour is running');
        // Get latest data sensor (1 hour)
        $dataTerakhir = DataSensor::where('timestamp_pengukuran', '>=', Carbon::now()->subHour())
            ->where('timestamp_pengukuran', '<=', Carbon::now())
            ->get();

        // Finish function if no data sensor
        if ($dataTerakhir->count() < 1) {
            return;
        }

        // Calculate average
        $temperature = $dataTerakhir->avg('suhu_udara');
        $humidity = $dataTerakhir->avg('kelembapan_udara');
        $soilMoisture = $dataTerakhir->avg('kelembapan_tanah');

        // Convert to JSON
        $data = [
            'temperature' => $temperature,
            'Humidity' => $humidity,
            'SoilMoisture' => $soilMoisture
        ];

        // Get id_penanaman
        $id_penanaman = DataSensor::orderBy('timestamp_pengukuran', 'desc')->first()->id_penanaman;

        // Send data to route('ml.irrigation') to get irrigation recommendation
        $response_irrigation = Http::post(route('ml.irrigation'), $data);

        // If successful, send data to route('ml.predict') to get prediction
        if ($response_irrigation->successful()) {
            $response_irrigation = $response_irrigation->json();
            // Get time, add 1 minute so it will work for the scheduler
            $time_irrigation = Carbon::now()->addMinute()->format('Y-m-d H:i');

            // Save the data from route('ml.irrigation') to database rekomendasi_pengairan
            self::saveDatatoRekomendasiPengairan($id_penanaman, $response_irrigation['data'], $time_irrigation);

            // Send data to route('ml.predict') to get prediction
            $response_predict = Http::post(route('ml.predict'), $data);
            if ($response_predict->successful()) {
                $response_predict = $response_predict->json();
                $time_prediksi = $response_predict['data']['predict']['Time'];

                // Save the data from route('ml.predict') to database rekomendasi_pengairan
                self::saveDatatoRekomendasiPengairan($id_penanaman, $response_predict['data']['irrigation']['data'], $time_prediksi);
            }
        }

        return;
    }

    /**
     * This function is executed to save 2 pieces of data into the database:
     * 1. Save the received data to the recommendation_irrigation database.
     *    Notes: Check if the message already exists, if it does, update it; if not, create a new one.
     * 2. If the device is active, save the data to the irrigation_controller database.
     *
     * Data to be saved:
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
     * This function runs every 1 minute to check if there is irrigation data
     * that needs to be sent to Antares.
     * 1. Check if there is irrigation data currently being sent to Antares:
     *      a. If yes, check if it has been completed:
     *          i. If completed, set 'sedang_berjalan' to 0, send close data to Antares.
     *          ii. If not completed, exit the function.
     *      b. If no, proceed to step 2.
     * 2. Retrieve data from irrigation_controller.
     * 3. Check if 'willSend' is 1 and 'isSent' is 0:
     *    a. If not, exit the function.
     *    b. If yes, proceed to step 4.
     * 4. Check if 'waktu_mulai' matches the current time:
     *    a. If not, exit the function.
     *    b. If yes, proceed to step 5.
     * 5. Send open data to Antares.
     * 6. Update 'isSent' to 1.
     * 7. Add data to the log_actions.
     *
     * @return void
     */

    public static function scheduleIrrigation()
    {
        Log::info("schedule irrigation started");
        // Check in the log_aksi if there is already irrigation data sent to Antares
        // If yes, check if the current time (in irrigation_controller) == the completion time
        // If not, exit the function
        // If yes, set 'sedang_berjalan' to 0

        $waktu_sekarang = Carbon::now()->format('Y-m-d H:i');
        $id_penanaman = Penanaman::where('alat_terpasang', true)->first()->id_penanaman;
        $tipe = TipeInstruksi::where('nama_tipe', 'pengairan')->first()->id_tipe_instruksi;

        $logAksi = LogAksi::where('sedang_berjalan', true)->where('id_tipe_instruksi', $tipe);

        // If there is an action running, check if it is completed
        if ($logAksi->count() > 0) {
            $waktu_selesai = Carbon::parse($logAksi->first()->irrigation_controller->waktu_selesai)->format('Y-m-d H:i');

            // Check if the device has finished running
            if ($waktu_sekarang == $waktu_selesai) {
                // Send downlink to Antares to turn off the device
                $kode = Self::convertToAntaresCode('mati', 'air');
                $response = Http::post(route('antares.downlink'), [
                    'data' => $kode
                ]);

                // Set sedang_berjalan to 0
                $logAksi->update([
                    'sedang_berjalan' => false,
                    'updated_at' => Carbon::now(),
                ]);
            }

            return;
        }

        // Get irrigation_controller data
        $now = Carbon::now();

        $irrigation_controller = IrrigationController::where('willSend', 1)
            ->where('isSent', 0)
            ->whereDate('waktu_mulai', '=', $now->format('Y-m-d'))
            ->whereTime('waktu_mulai', '=', $now->format('H:i'))
            ->get();

        // If there is no data, exit the function
        if ($irrigation_controller->count() <= 0) {
            return;
        }

        $irrigation_controller = $irrigation_controller->first();

        // Check if it is time to send data to antares
        $waktu_mulai = Carbon::parse($irrigation_controller->waktu_mulai)->format('Y-m-d H:i');
        if ($waktu_mulai != $waktu_sekarang) {
            return;
        }

        // Get durasi from irrigation_controller
        $durasi = $irrigation_controller->durasi_detik;

        // Convert to device code
        $kode = self::convertToAntaresCode('nyala', 'air');

        // Send data to Antares route(antares.downlink)
        $response = Http::post(route('antares.downlink'), [
            'data' => $kode
        ]);

        // If successful, update data isSent = 1
        if ($response->successful()) {
            $irrigation_controller->update([
                'isSent' => 1
            ]);

            // Add data ke log_aksi
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
     * This function runs every 1 minute to check if there is fertilizer data
     * that needs to be sent to Antares.
     *
     * 1. Check if there is ongoing fertilizer data in the device:
     *      a. If yes, check if it has been completed:
     *          i. If completed, set 'sedang_berjalan' to 0, send close command to Antares.
     *          ii. If not completed, exit the function.
     *      b. If no, proceed to step 2.
     * 2. Retrieve data from fertilizer_control.
     * 3. Check if 'waktu_mulai' matches the current time:
     *    a. If not, exit the function.
     *    b. If yes, proceed to step 4.
     * 4. Check if 'willSend' is 1 and 'isSent' is 0:
     *    a. If not, exit the function.
     *    b. If yes, proceed to step 5.
     * 5. Send open data to Antares.
     * 6. Update 'isSent' to 1.
     * 7. Add data to the log_actions.
     *
     * @return void
     */

    public static function scheduleFertilizer()
    {
        Log::info("Schedule Fertilizer");

        // Check in the log_aksi if there is already fertilizer data sent to Antares
        // If yes, check if the current time (in fertilizer_controller) == the completion time
        // If not, exit the function
        // If yes, set 'sedang_berjalan' to 0

        $waktu_sekarang = Carbon::now()->format('Y-m-d H:i');
        $id_penanaman = Penanaman::where('alat_terpasang', true)->first()->id_penanaman;
        $tipe = TipeInstruksi::where('nama_tipe', 'pemupukan')->first()->id_tipe_instruksi;

        $logAksi = LogAksi::where('sedang_berjalan', true)->where('id_tipe_instruksi', $tipe);

        // If there is an action running, check if it is completed
        if ($logAksi->count() > 0) {
            $waktu_selesai = Carbon::parse($logAksi->first()->fertilizer_controller->waktu_selesai)->format('Y-m-d H:i');

            // Check if the device has finished running
            if ($waktu_sekarang == $waktu_selesai) {
                // Send downlink to Antares to turn off the device
                $kode = Self::convertToAntaresCode('mati', 'pupuk');
                $response = Http::post(route('antares.downlink'), [
                    'data' => $kode
                ]);

                // Set sedang_berjalan to 0
                $logAksi->update([
                    'sedang_berjalan' => false,
                    'updated_at' => Carbon::now(),
                ]);
            }

            return;
        }

        // Get fertilizer_controller data
        $now = Carbon::now();
        $fertilizer_control = FertilizerController::where('willSend', 1)
            ->where('isSent', 0)
            ->whereDate('waktu_mulai', '=', $now->format('Y-m-d'))
            ->whereTime('waktu_mulai', '=', $now->format('H:i'))
            ->get();


        // If there is no data, exit the function
        if ($fertilizer_control->count() <= 0) {
            return;
        }

        $fertilizer_control = $fertilizer_control->first();

        // Check if it is time to send data to antares
        $waktu_mulai = Carbon::parse($fertilizer_control->waktu_mulai)->format('Y-m-d H:i');
        $waktu_sekarang = Carbon::now()->format('Y-m-d H:i');
        if ($waktu_mulai != $waktu_sekarang) {
            return;
        }

        // Get durasi from fertilizer_controller
        $durasi = $fertilizer_control->durasi_detik;
        // Convert to device code
        $kode = self::convertToAntaresCode('nyala', 'pupuk');

        // Send data to Antares route(antares.downlink)
        $response = Http::post(route('antares.downlink'), [
            'data' => $kode
        ]);

        // If successful, update data isSent = 1
        if ($response->successful()) {
            $fertilizer_control->update([
                'isSent' => 1
            ]);

            // Add data ke log_aksi
            $id_fertilizer_control = $fertilizer_control->id_fertilizer_controller;
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
