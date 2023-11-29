<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Message;
use App\Models\Penanaman;
use App\Models\DataSensor;
use App\Models\RekomendasiPengairan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;


class APIController extends Controller
{
    function gpsToJakarta($gpsTime)
    {
        // GPS time is ahead of UTC by 315964800 seconds
        $gpsOffsetSeconds = 315964800;

        // Convert GPS time to UTC
        $utcTime = Carbon::createFromTimestamp($gpsTime + $gpsOffsetSeconds, 'UTC');

        // Convert UTC time to Asia/Jakarta timezone
        $jakartaTime = $utcTime->setTimezone('Asia/Jakarta')->toDateTimeString();

        return $jakartaTime;
    }
    /**
     * Display a listing of the resource.
     */
    public function handleAntaresWebhook(Request $request)
    {
        // Access the 'con' key from the request payload
        $con = $request->input('m2m:sgn.m2m:nev.m2m:rep.m2m:cin.con');
        $data = '';

        if ($con) {
            // Decode the JSON content of 'con' to an array
            $conArray = json_decode($con, true);

            if ($conArray['type'] == 'uplink') {
                /** Change GPS Time to Jakarta Time */
                $gps_time = $conArray['radio']['gps_time'];
                $jakartaTime = $this->gpsToJakarta($gps_time);

                /** Get 'data' from LoRa */
                // Access the 'data' key from the decoded 'con' array
                $data = $conArray['data'];
                $dataString = explode(',', $data);

                // Initialize an associative array to store key-value pairs
                $result = array();

                foreach ($dataString as $item) {
                    // Split each element into key and value using colon (:) as the delimiter
                    list($key, $value) = explode(':', $item);

                    // Add the key-value pair to the result array
                    $result[trim($key)] = trim($value);
                }

                /** Input the data to 'data_sensor' */
                // Get Penanaman which has alat_terpasang (only 1 penanaman => first penanaman)
                $penanaman = Penanaman::where('alat_terpasang', true)->first();
                DataSensor::create([
                    'id_penanaman' => $penanaman->id_penanaman,
                    'suhu' => $result['Temp'],
                    'kelembapan_udara' => $result['Hum'],
                    'kelembapan_tanah' => $result['Soil'],
                    'ph_tanah' => $result['pH'],
                    'timestamp_pengukuran' => $jakartaTime,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                response()->json([
                    "status" => 200,
                    "data" => $request->all(),
                ], 200)->send();

                /** Predict ML */
                Http::post(route('ml.predict-ml'), [
                    "temp" => $result['Temp'],
                    "hum" => $result['Hum'],
                    "soil" => $result['Soil'],
                ]);
            }
        }

        return response()->json([
            "status" => 200,
            "data" => $request->all(),
        ], 200);
    }

    public function predictML(Request $request)
    {
        // Log::info('---------------------------------------------------------');

        $soil = floatval($request->input('soil'));
        $hum = floatval($request->input('hum'));
        $temp = floatval($request->input('temp'));

        // Log::info('Predict ML', [
        //     'temp' => $temp,
        //     'hum' => $hum,
        //     'soil' => $soil,
        // ]);

        // Send request to irrigation (internal API)
        $URL = route('ml.irrigation');
        $body = [
            'SoilMoisture' => floatval($soil),
            'Humidity' => floatval($hum),
            'temperature' => floatval($temp),
        ];

        // Log::info("Link", [
        //     "URL" => $URL,
        //     "body" => $body,
        // ]);
        try {
            $response = Http::post($URL, $body);

            // Log::info("response", [
            //     "hasil" => $response
            // ]);

            // Check response status
            if ($response->successful()) {
                // Log::info("Nih sukses");

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

                $penanaman = Penanaman::where('alat_terpasang', true)->first()->id_penanaman;

                // save data to database RekomendasiPengairan
                RekomendasiPengairan::create([
                    'id_penanaman' => $penanaman,
                    'nyalakan_alat' => $klusterNyala,
                    'durasi_detik' => $klusterWaktu,
                    'kondisi' => $kondisi,
                    'saran' => $saran,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);

                // Send request to Handle Downlink
                $dataNyala = config('services.device.air_nyala');

                if ($klusterNyala == 0) {
                    $dataNyala = config('services.device.air_mati');
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
                } else {
                    // Handle the error appropriately
                    return response()->json([
                        "status" => $response->status(),
                        "message" => "Terjadi kesalahan: " . $response->body(),
                    ], $response->status());
                }
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

    public function handleAntaresDownlink(Request $request)
    {
        /** Request to
            config('services.antares.url)

            headers:
            'X-M2M-Origin': env('ANTARES_ACCESS_KEY');
            Content-Type: application/json;ty=4;
            Accept: application/json;
         */

        /** Body Example
        {
            "m2m:cin": {
                "con": "{\"type\":\"downlink\", \"data\":\"env('RELAY_AIR_NYALA')\"}"
            }
        }
         */

        $rules = [
            'data' => 'required',
        ];

        $messages = [
            'data.required' => 'Data dibutuhkan!',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);
        if ($validator->fails()) {
            return response()->json([
                "status" => 400,
                "message" => "Need data",
                "errors" => $validator->errors(),
            ], 400);
        }

        $URL = config('services.antares.url');
        $access_key = config('services.antares.access_key');

        $headers = [
            'X-M2M-Origin' => $access_key,
            'Content-Type' => 'application/json;ty=4;',
            'Accept' => 'application/json',
        ];

        $body = [
            'm2m:cin' => [
                // Change json to string from "con" key
                'con' => json_encode([
                    "type" => "percobaan",
                    "data" => $request->input('data'),
                ]),
            ],
        ];

        try {
            // Attempt to make the HTTP request
            $response = Http::withHeaders($headers)->post($URL, $body);

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

    public function fertilizer(Request $request)
    {
        /** Request to
            config('services.ml_service.url') . '/pemupukan';

            Body:
            {
                "tinggi_tanaman": 180,
                "hst": 30
            }
         */
        $rules = [
            'tinggi_tanaman' => 'required|numeric',
            'hst' => 'required|numeric',
        ];

        $messages = [
            'tinggi_tanaman.required' => 'Tinggi tanaman dibutuhkan!',
            'hst.required' => 'HST dibutuhkan!',
            'tinggi_tanaman.numeric' => 'Tinggi tanaman harus berupa angka!',
            'hst.numeric' => 'HST harus berupa angka!',
        ];

        $validator = Validator::make($request->all(), $rules, $messages);
        $validatedData = $validator->validated();

        if ($validator->fails()) {
            return response()->json([
                "status" => 400,
                "message" => "Need tinggi_tanaman and hst",
                "errors" => $validator->errors(),
            ], 400);
        }

        $URL = config('services.ml_service.url') . '/pemupukan';

        try {
            // Attempt to make the HTTP request
            $response = Http::post($URL, $validatedData);

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

        $URL = config('services.ml_service.url') . '/penyiraman';

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
            $response = Http::post($URL, $validatedData);

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
}
