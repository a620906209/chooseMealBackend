<?php

namespace App\Http\Controllers;

use App\Services\GridSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

class RestaurantController extends Controller
{
    private $gridSearchService;
    private $apiKey;
    
    public function __construct(GridSearchService $gridSearchService)
    {
        $this->gridSearchService = $gridSearchService;
        $this->apiKey = env('GOOGLE_MAPS_API_KEY');
        Log::info('API Key:', ['key' => $this->apiKey]);
    }

    public function searchRestaurantsInArea(Request $request)
    {
        try {
            $request->validate([
                'latitude' => 'required|numeric',
                'longitude' => 'required|numeric',
                'radius' => 'required|numeric|max:5000', // 最大搜尋半徑（米）
            ]);

            $centerLat = $request->latitude;
            $centerLng = $request->longitude;
            $radius = $request->radius;

            Log::info('Search Parameters:', [
                'latitude' => $centerLat,
                'longitude' => $centerLng,
                'radius' => $radius
            ]);

            // 生成快取的 key
            $cacheKey = "restaurants:{$centerLat}:{$centerLng}:{$radius}";
            
            // 搜尋結果快取 7 天
            return Cache::remember($cacheKey, 7 * 24 * 60 * 60, function () use ($centerLat, $centerLng, $radius) {
                // 獲取所有網格點
                $gridPoints = $this->gridSearchService->calculateGridPoints($centerLat, $centerLng, $radius);
                Log::info('Grid Points:', ['count' => count($gridPoints)]);
                
                $allRestaurants = [];
                $seenPlaceIds = []; // 用於去重

                foreach ($gridPoints as $point) {
                    $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
                    $params = [
                        'location' => "{$point['lat']},{$point['lng']}",
                        'radius' => 1000,
                        'type' => 'restaurant',
                        'key' => $this->apiKey,
                        'language' => 'zh-TW'  // 設定回傳語言為繁體中文
                    ];
                    
                    Log::info('Making API request:', [
                        'url' => $url,
                        'params' => $params
                    ]);

                    $response = Http::get($url, $params);

                    if ($response->successful()) {
                        $data = $response->json();
                        Log::info('API Response:', [
                            'status' => $data['status'] ?? 'unknown',
                            'results_count' => count($data['results'] ?? [])
                        ]);
                        
                        if (isset($data['error_message'])) {
                            Log::error('Google API Error:', ['message' => $data['error_message']]);
                            throw new \Exception($data['error_message']);
                        }

                        $results = $data['results'] ?? [];
                        
                        // 處理每個餐廳的資料
                        foreach ($results as $place) {
                            if (!isset($seenPlaceIds[$place['place_id']])) {
                                $seenPlaceIds[$place['place_id']] = true;
                                
                                // 獲取完整地址和營業時間
                                $placeDetails = $this->getPlaceDetails($place['place_id']);
                                Log::info('Place Details:', ['details' => $placeDetails]);
                                
                                $fullAddress = $placeDetails['address'] ?? $place['vicinity'] ?? '';
                                $openingHours = $placeDetails['opening_hours'] ?? null;
                                
                                $allRestaurants[] = [
                                    'place_id' => $place['place_id'],
                                    'name' => $place['name'],
                                    'rating' => $placeDetails['rating'] ?? $place['rating'] ?? null,
                                    'user_ratings_total' => $placeDetails['user_ratings_total'] ?? $place['user_ratings_total'] ?? 0,
                                    'address' => $fullAddress,
                                    'opening_hours' => $openingHours,
                                    'open_now' => $place['opening_hours']['open_now'] ?? null,
                                    'photos' => isset($place['photos']) ? [
                                        [
                                            'urls' => [
                                                'small' => $this->getPhotoUrl($place['photos'][0]['photo_reference'], 400),
                                                'large' => $this->getPhotoUrl($place['photos'][0]['photo_reference'], 800)
                                            ]
                                        ]
                                    ] : null,
                                    'types' => $place['types'] ?? [],
                                    'uber_eats_url' => $placeDetails['uber_eats_url'] ?? null
                                ];
                            }
                        }
                    } else {
                        Log::error('API Request Failed:', [
                            'status' => $response->status(),
                            'body' => $response->body()
                        ]);
                    }
                }

                // 隨機打亂餐廳順序
                $allRestaurants = Arr::shuffle($allRestaurants);

                Log::info('Final Results:', [
                    'total_restaurants' => count($allRestaurants)
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $allRestaurants,
                    'total' => count($allRestaurants),
                    'grid_points_count' => count($gridPoints)
                ]);
            });

        } catch (\Exception $e) {
            Log::error('Error in searchRestaurantsInArea:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function getPlaceDetails($placeId)
    {
        // 生成快取的 key
        $cacheKey = "place_details:{$placeId}";
        
        // 餐廳詳細資訊快取 30 天
        return Cache::remember($cacheKey, 30 * 24 * 60 * 60, function () use ($placeId) {
            try {
                $url = 'https://maps.googleapis.com/maps/api/place/details/json';
                $params = [
                    'place_id' => $placeId,
                    'fields' => 'formatted_address,opening_hours,rating,user_ratings_total,name,geometry',
                    'key' => $this->apiKey,
                    'language' => 'zh-TW'
                ];

                $response = Http::get($url, $params);
                
                Log::info('Place Details API Response:', [
                    'place_id' => $placeId,
                    'status' => $response->json()['status'] ?? 'unknown',
                    'data' => $response->json()['result'] ?? null
                ]);
                
                if ($response->successful()) {
                    $data = $response->json();
                    $result = $data['result'] ?? [];

                    // 構建 Uber Eats 直接連結
                    $uberEatsUrl = null;
                    if (isset($result['geometry']['location'])) {
                        $lat = $result['geometry']['location']['lat'];
                        $lng = $result['geometry']['location']['lng'];
                        $name = urlencode($result['name']);
                        
                        // 使用 Google Places API 獲取更多詳細資訊
                        $detailsUrl = 'https://maps.googleapis.com/maps/api/place/details/json';
                        $detailsParams = [
                            'place_id' => $placeId,
                            'fields' => 'website',
                            'key' => $this->apiKey
                        ];
                        
                        $detailsResponse = Http::get($detailsUrl, $detailsParams);
                        if ($detailsResponse->successful()) {
                            $detailsData = $detailsResponse->json();
                            $website = $detailsData['result']['website'] ?? '';
                            
                            // 檢查是否有 Uber Eats 連結
                            if (strpos($website, 'ubereats.com') !== false) {
                                $uberEatsUrl = $website;
                            } else {
                                // 如果沒有直接連結，則使用搜尋頁面
                                $uberEatsUrl = "https://www.ubereats.com/tw/store/{$name}-{$lat}-{$lng}";
                            }
                        }
                    }
                    
                    return [
                        'address' => $result['formatted_address'] ?? null,
                        'opening_hours' => isset($result['opening_hours']) ? $result['opening_hours']['weekday_text'] : null,
                        'rating' => $result['rating'] ?? null,
                        'user_ratings_total' => $result['user_ratings_total'] ?? null,
                        'uber_eats_url' => $uberEatsUrl
                    ];
                }
                
                return null;

            } catch (\Exception $e) {
                Log::error('獲取地點詳細信息時發生錯誤：' . $e->getMessage());
                return null;
            }
        });
    }

    private function getPhotoUrl($photoReference, $maxWidth = 400)
    {
        return "https://maps.googleapis.com/maps/api/place/photo?maxwidth={$maxWidth}&photo_reference={$photoReference}&key={$this->apiKey}";
    }
}