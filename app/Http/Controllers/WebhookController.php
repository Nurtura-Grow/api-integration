<?php

namespace App\Http\Controllers;

use App\Models\DataSensor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
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

            // Access the 'data' key from the decoded 'con' array
            $data = $conArray['data'];
        }

        // Log incoming webhook payload
        Log::info('Webhook Payload Received', [
            'con' => $con,
            'data' => $data,
        ]);

        return response()->json([
            "status" => 200,
            "data" => $request->all(),
        ], 200);
    }
}
