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
     * Handle incoming webhook from Antares.
     * Saving the data to data_sensor
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handleAntaresWebhook(Request $request)
    {
        // Access the 'con' key from the request payload
        $input = $request->input('m2m:sgn.m2m:nev.m2m:rep.m2m:cin');
        $con = $input['con'];
        $ct = Carbon::parse($input['ct'])->format('Y-m-d H:i:00');

        if ($con) {
            // Decode the JSON content of 'con' to an array
            $conArray = json_decode($con, true);

            // Get Penanaman which has alat_terpasang (only 1 penanaman => first penanaman)
            $penanaman = Penanaman::where('alat_terpasang', true)->first();

            // Get DataSensor which has timestamp_pengukuran = ct
            $dataSensor = DataSensor::where('timestamp_pengukuran', $ct)->first();

            if ($dataSensor == null) {
                // Create new DataSensor
                DataSensor::create([
                    'id_penanaman' => $penanaman->id_penanaman,
                    'suhu' => $conArray['type'] == "udara" ? $conArray['temperature'] : 0,
                    'kelembapan_udara' => $conArray['type'] == "udara" ? $conArray['humidity'] : 0,
                    'kelembapan_tanah' => $conArray['type'] == "tanah" ? $conArray['soilMoisture'] : 0,
                    'ph_tanah' => $conArray['type'] == "tanah" ? $conArray['soilPH'] : 0,
                    'timestamp_pengukuran' => $ct,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
            } else {
                $updateData = [
                    'updated_at' => Carbon::now(),
                ];

                if ($conArray['type'] == "tanah") {
                    $updateData['kelembapan_tanah'] = $conArray['soilMoisture'];
                    $updateData['ph_tanah'] = $conArray['soilPH'];
                } else {
                    $updateData['suhu'] = $conArray['temperature'];
                    $updateData['kelembapan_udara'] = $conArray['humidity'];
                }

                $dataSensor->update($updateData);
            }
        }

        return response()->json([
            "status" => 200,
            "data" => "data",
        ], 200);
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
