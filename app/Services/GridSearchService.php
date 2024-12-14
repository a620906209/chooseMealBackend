<?php
namespace App\Services;

class GridSearchService
{
    private $baseRadius = 1000; // 每個網格的半徑（米）
    private $earthRadius = 6371000; // 地球半徑（米）

    /**
     * 計算指定範圍內的所有網格中心點
     */
    public function calculateGridPoints($centerLat, $centerLng, $radius)
    {
        // 計算經緯度的偏移量（將米轉換為經緯度）
        $latOffset = rad2deg($this->baseRadius / $this->earthRadius);
        $lngOffset = rad2deg($this->baseRadius / $this->earthRadius / cos(deg2rad($centerLat)));

        // 計算網格數量
        $gridCount = ceil($radius / $this->baseRadius);
        
        $gridPoints = [];
        
        // 生成網格點
        for ($i = -$gridCount; $i <= $gridCount; $i++) {
            for ($j = -$gridCount; $j <= $gridCount; $j++) {
                $lat = $centerLat + ($i * $latOffset * 1.5); // 1.5 為重疊係數
                $lng = $centerLng + ($j * $lngOffset * 1.5);
                
                // 檢查此點是否在指定半徑內
                if ($this->calculateDistance($centerLat, $centerLng, $lat, $lng) <= $radius) {
                    $gridPoints[] = [
                        'lat' => $lat,
                        'lng' => $lng
                    ];
                }
            }
        }
        
        return $gridPoints;
    }

    /**
     * 計算兩點間的距離（米）
     */
    private function calculateDistance($lat1, $lng1, $lat2, $lng2)
    {
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);
        
        $dlat = $lat2 - $lat1;
        $dlng = $lng2 - $lng1;
        
        $a = sin($dlat/2) * sin($dlat/2) + cos($lat1) * cos($lat2) * sin($dlng/2) * sin($dlng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $this->earthRadius * $c;
    }
}