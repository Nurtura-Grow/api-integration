<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Penanaman;
use App\Models\DataSensor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

use Illuminate\Support\Facades\Log;

class AntaresController extends Controller
{
    /**
     * Convert GPS time to Jakarta time.
     *
     * @param  int  $gpsTime
     * @return string
     */

    function gpsToJakarta($gpsTime)
    {
        // GPS time is ahead of UTC by 315964800 seconds
        $gpsOffsetSeconds = 315964800;

        // Convert GPS time to UNIX timestamp
        $unixTimestamp = ($gpsTime / 1000) + $gpsOffsetSeconds;

        // Convert GPS time to UTC
        $utcTime = Carbon::createFromTimestamp($unixTimestamp, 'UTC');

        // Convert UTC time to Asia/Jakarta timezone
        $jakartaTime = $utcTime->setTimezone('Asia/Jakarta')->toDateTimeString();

        return $jakartaTime;
    }

    /**
     * Handle incoming webhook from Antares.
     * Saving the data to data_sensor
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handleAntaresWebhook(Request $request)
    {
        try{
            $dataSensor = 1;

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
                    $dataSensor = DataSensor::create([
                        'id_penanaman' => $penanaman->id_penanaman,
                        'suhu' => $result['Temp'],
                        'kelembapan_udara' => $result['Hum'],
                        'kelembapan_tanah' => $result['Soil'],
                        'ph_tanah' => $result['pH'],
                        'timestamp_pengukuran' => $jakartaTime,
                    ])->id_sensor;
                }
            }

            return response()->json([
                "status" => 200,
                "data" => DataSensor::find($dataSensor),
            ], 200);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return response()->json([
                "status" => 500,
                "message" => "Terjadi kesalahan: " . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle downlink request to Antares -> will send the data to Device.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     *
     * Permintaan ke {{ config('services.antares.url') }}:
     *
     * Headers:
     * - 'X-M2M-Origin': {{ env('ANTARES_ACCESS_KEY') }};
     * - 'Content-Type': application/json;ty=4;
     * - 'Accept': application/json;
     *
     * Contoh Body:
     * {
     *     "m2m:cin": {
     *         "con": "{\"type\":\"downlink\", \"data\":\"{{ config('services.device.air_nyala_10_menit ) }}\"}"
     *     }
     * }
     */
    public function handleAntaresDownlink(Request $request)
    {
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
                    "type" => 'downlink',
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
}
