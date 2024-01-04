<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\Penanaman;
use App\Models\PrediksiSensor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class MachineLearningController extends Controller
{
    private $URL_IRRIGATION;
    private $URL_FERTILIZER;
    private $URL_PREDICT;

    public function __construct()
    {
        $this->URL_IRRIGATION = config('services.ml_service.url') . '/penyiraman';
        $this->URL_FERTILIZER = config('services.ml_service.url') . '/pemupukan';
        $this->URL_PREDICT = config('services.ml_service.url') . '/predict';
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

    /**
     * Handle irrigation request.
     *
     * This method processes an irrigation request by sending data to the
     * machine learning service for irrigation control. It validates the
     * incoming request parameters and handles the HTTP request to the
     * machine learning service.
     *
     * Request to {{ config('services.machine_learning.url') }}:
     *
     * Raw Body:
     * {
     *     "SoilMoisture": 68,
     *     "Humidity": 75,
     *     "temperature": 29
     * }
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function irrigation(Request $request)
    {
        // Define validation rules
        $rules = [
            'SoilMoisture' => 'required|numeric',
            'Humidity' => 'required|numeric',
            'temperature' => 'required|numeric',
        ];

        // Define validation messages
        $messages = [
            'SoilMoisture.required' => 'SoilMoisture dibutuhkan!',
            'Humidity.required' => 'Humidity dibutuhkan!',
            'temperature.required' => 'temperature dibutuhkan!',
            'SoilMoisture.numeric' => 'SoilMoisture harus berupa angka!',
            'Humidity.numeric' => 'Humidity harus berupa angka!',
            'temperature.numeric' => 'temperature harus berupa angka!',
        ];

        // Validate the request data
        $validator = Validator::make($request->all(), $rules, $messages);

        // Check if validation fails
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
                // Handle the error
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
     * Handle prediction request.
     *
     * This method performs the following steps:
     * 1. Sends a request to get prediction results from the machine learning service.
     * 2. Stores the prediction results in the database.
     * 3. Runs irrigation to get the irrigation command.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function predict(Request $request)
    {
        // Get prediction result from ML service
        $response_predict = Http::get($this->URL_PREDICT)->json();

        // Save prediction result to database
        PrediksiSensor::create([
            'id_penanaman' => Penanaman::where('alat_terpasang', true)->first()->id_penanaman,
            'suhu' => $response_predict['temperature'],
            'kelembapan_udara' => $response_predict['Humidity'],
            'kelembapan_tanah' => $response_predict['SoilMoisture'],
            'timestamp_prediksi_sensor' => $response_predict['Time'],
            'created_at' => Carbon::now(),
        ]);

        // Run irrigation to get the irrigation command
        $url_irrigation = route('ml.irrigation');
        $response_irrigation = Http::post($url_irrigation, $response_predict)->json();

        // Return the response with prediction and irrigation data
        return response()->json([
            'status' => 200,
            'data' => [
                'predict' => $response_predict,
                'irrigation' => $response_irrigation,
            ],
        ]);
    }
}
