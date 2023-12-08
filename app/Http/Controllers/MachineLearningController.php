<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Message;
use App\Models\Penanaman;
use App\Models\DataSensor;
use App\Models\LogAksi;
use App\Models\PrediksiSensor;
use App\Models\RekomendasiPengairan;
use App\Models\TipeInstruksi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Log;

class MachineLearningController extends Controller
{
    private $REKOMENDASI_AIR;
    private $URL_IRRIGATION;
    private $URL_FERTILIZER;
    private $URL_PREDICT;

    public function __construct()
    {
        $this->URL_IRRIGATION = config('services.ml_service.url') . '/penyiraman';
        $this->URL_FERTILIZER = config('services.ml_service.url') . '/pemupukan';
        $this->URL_PREDICT = config('services.ml_service.url') . '/predict';
    }

    public function handleData(Request $request)
    {
        // Define validation rules
        $rules = [
            'id_sensor' => 'required',
            'SoilMoisture' => 'required|numeric',
            'Humidity' => 'required|numeric',
            'temperature' => 'required|numeric',
        ];

        $messages = [
            'id_sensor.required' => 'ID Sensor dibutuhkan!',
            'SoilMoisture.required' => 'SoilMoisture dibutuhkan!',
            'Humidity.required' => 'Humidity dibutuhkan!',
            'temperature.required' => 'temperature dibutuhkan!',
            'SoilMoisture.numeric' => 'SoilMoisture harus berupa angka!',
            'Humidity.numeric' => 'Humidity harus berupa angka!',
            'temperature.numeric' => 'temperature harus berupa angka!',
        ];

        // Validate request
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                "status" => 400,
                "message" => "Need ID Sensor, SoilMoisture, Humidity, and temperature",
                "errors" => $validator->errors(),
            ], 400);
        }

        $URL = route('ml.irrigation');
        $body = $validator->validated();

        $data_sensor = DataSensor::find($body['id_sensor']);

        if (!$data_sensor) {
            return response()->json([
                "status" => 404,
                "message" => "ID Sensor not found",
            ], 404);
        }

        try {
            $response = Http::post($URL, $body);

            // If response failed
            if ($response->failed()) {
                return response()->json([
                    "status" => $response->status(),
                    "message" => "Terjadi kesalahan: " . $response->body(),
                ], $response->status());
            }

            // If Response Successful
            $responseBody = $response->json();
            $data = $responseBody['data'];

            $informasiKluster = $data['Informasi Kluster'];
            $klusterNyala = $informasiKluster['nyala'];
            $klusterWaktu = $informasiKluster['waktu'];

            $kondisi = Message::firstOrCreate([
                'message' =>  $data['Kondisi'],
            ])->id;

            $saran = Message::firstOrCreate([
                'message' => $data['Saran'],
            ])->id;

            // save data to database RekomendasiPengairan
            $this->REKOMENDASI_AIR = RekomendasiPengairan::create([
                'id_sensor' => $data_sensor->id_sensor,
                'nyalakan_alat' => $klusterNyala,
                'durasi_detik' => $klusterWaktu,
                'kondisi' => $kondisi,
                'saran' => $saran,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ])->id_rekomendasi_air;

            // If the klusterNyala is false (mati), no need to send to antares
            if ($klusterNyala == false || $klusterNyala == 0) {
                return response()->json([
                    "status" => $response->status(),
                    "data" => $data,
                ], 200);
            }

            // Send request to Handle Downlink
            switch ($klusterWaktu) {
                case 600:
                    $dataNyala = config('services.antares.device.air_nyala_10_menit');
                    break;
                case 1200:
                    $dataNyala = config('services.antares.device.air_nyala_20_menit');
                    break;
                case 3000:
                    $dataNyala = config('services.antares.device.air_nyala_50_menit');
                    break;
                default:
                    $dataNyala = config('services.antares.device.air_nyala_10_menit');
            }


            $URL_downlink = route('antares.downlink');
            $body_downlink = [
                'data' => $dataNyala,
            ];

            $response = Http::post($URL_downlink, $body_downlink);

            // Check response status
            if ($response->successful()) {
                $responseBody = $response->json();
                $data = $responseBody['data'];

                // Save to log aksi
                $id_tipe_instruksi = TipeInstruksi::where('nama_tipe', 'pengairan')->first()->id_tipe_instruksi;

                $log = LogAksi::create([
                    'id_tipe_instruksi' => $id_tipe_instruksi,
                    'id_penanaman' => $data_sensor->penanaman->id_penanaman,
                    'id_rekomendasi_pemupukan' => null,
                    'id_rekomendasi_air' => $this->REKOMENDASI_AIR,
                    'durasi' => $klusterWaktu,
                ]);

                return response()->json([
                    "status" => $response->status(),
                    "data" => $data,
                ]);
            } else {
                // Handle the error appropriately
                return response()->json([
                    "status" => $response->status(),
                    "message" => "Terjadi kesalahan: " . $response->body(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            // Catch any exceptions and return an error
            return response()->json([
                "status" => 500,
                "message" => "Terjadi kesalahan: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle fertilizer request.
     *
     * This method processes a fertilizer request by sending data to the
     * ML service endpoint for fertilizer calculation. It validates the
     * incoming request parameters and handles the HTTP request to the ML service.
     *
     * Request to {{ config('services.ml_service.url') . '/pemupukan' }}:
     *
     * Body:
     * {
     *     "tinggi_tanaman": 180,
     *     "hst": 30
     * }
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function fertilizer(Request $request)
    {
        // Validation Rules
        $rules = [
            'tinggi_tanaman' => 'required|numeric',
            'hst' => 'required|numeric',
        ];

        // Validation Messages
        $messages = [
            'tinggi_tanaman.required' => 'Tinggi tanaman dibutuhkan!',
            'hst.required' => 'HST dibutuhkan!',
            'tinggi_tanaman.numeric' => 'Tinggi tanaman harus berupa angka!',
            'hst.numeric' => 'HST harus berupa angka!',
        ];

        // Validate Request Data
        $validator = Validator::make($request->all(), $rules, $messages);
        $validatedData = $validator->validated();

        // If validation fails, return error response
        if ($validator->fails()) {
            return response()->json([
                "status" => 400,
                "message" => "Need tinggi_tanaman and hst",
                "errors" => $validator->errors(),
            ], 400);
        }

        try {
            // Attempt to make the HTTP request
            $response = Http::post($this->URL_FERTILIZER, $validatedData);

            // Check response status
            if ($response->successful()) {
                return response()->json([
                    "status" => $response->status(),
                    "data" => $response->json(),
                ]);
            } else {
                // Handle the error appropriately
                return response()->json([
                    "status" => $response->status(),
                    "message" => "Terjadi kesalahan: " . $response->body(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            // Catch any exceptions and return an error
            return response()->json([
                "status" => 500,
                "message" => "Terjadi kesalahan: " . $e->getMessage(),
            ], 500);
        }
    }

    public function irrigation(Request $request)
    {
        /** Request to
            URL_MACHINE_LEARNING/

            Raw Body:
            {
                "SoilMoisture": 68,
                "Humidity": 75,
                "temperature": 29
            }
         */

        // Log::info("pengairan", [
        //     "request" => $request->all()
        // ]);

        // Define validation rules
        $rules = [
            'SoilMoisture' => 'required|numeric',
            'Humidity' => 'required|numeric',
            'temperature' => 'required|numeric',
        ];

        $messages = [
            'SoilMoisture.required' => 'SoilMoisture dibutuhkan!',
            'Humidity.required' => 'Humidity dibutuhkan!',
            'temperature.required' => 'temperature dibutuhkan!',
            'SoilMoisture.numeric' => 'SoilMoisture harus berupa angka!',
            'Humidity.numeric' => 'Humidity harus berupa angka!',
            'temperature.numeric' => 'temperature harus berupa angka!',
        ];

        // Validate request
        $validator = Validator::make($request->all(), $rules, $messages);

        if ($validator->fails()) {
            return response()->json([
                "status" => 400,
                "message" => "Need SoilMoisture, Humidity, and temperature",
                "errors" => $validator->errors(),
            ], 400);
        }

        // Validation passed, use the validated data
        $validatedData = $validator->validated();

        try {
            // Attempt to make the HTTP request
            $response = Http::post($this->URL_IRRIGATION, $validatedData);

            // Check response status
            if ($response->successful()) {
                return response()->json([
                    "status" => $response->status(),
                    "data" => $response->json(),
                ]);
            } else {
                // Handle the error appropriately
                return response()->json([
                    "status" => $response->status(),
                    "message" => "Terjadi kesalahan: " . $response->body(),
                ], $response->status());
            }
        } catch (\Exception $e) {
            // Catch any exceptions and return an error
            return response()->json([
                "status" => 500,
                "message" => "Terjadi kesalahan: " . $e->getMessage(),
            ], 500);
        }
    }

    public function predict(Request $request)
    {
        // GET PREDICTION RESULT
        $response_predict = Http::post($this->URL_PREDICT)->json();

        PrediksiSensor::create([
            'id_penanaman' => Penanaman::where('alat_terpasang', true)->first()->id_penanaman,
            'suhu' => $response_predict['temperature'],
            'kelembapan_udara' => $response_predict['Humidity'],
            'kelembapan_tanah' => $response_predict['SoilMoisture'],
            'timestamp_prediksi_sensor' => $response_predict['Time'],
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        // RUN IRRIGATION TO GET COMMAND
        $url_irrigation = route('ml.irrigation');
        $response_irrigation = Http::post($url_irrigation, $response_predict)->json();

        return response()->json([
            'status' => 200,
            'data' => [
                'predict' => $response_predict,
                'irrigation' => $response_irrigation,
            ],
        ]);
    }
}
