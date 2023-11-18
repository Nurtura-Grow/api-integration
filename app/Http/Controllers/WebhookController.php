<?php

namespace App\Http\Controllers;

use App\Models\DataSensor;
use App\Models\Penanaman;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
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
            ]);

            // Log incoming webhook payload
            Log::info('Webhook Payload Received', [
                'result' => $result,
                'time' => $jakartaTime,
            ]);
        }

        return response()->json([
            "status" => 200,
            "data" => $request->all(),
        ], 200);
    }
}
