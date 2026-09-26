<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Drug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DrugController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->input('q');

        $query = Drug::query();
        if ($q) {
            $normalized = $this->normalize($q);
            $query->where(function ($b) use ($normalized): void {
                $b->whereRaw('LOWER(brand_name) LIKE ?', ['%'.$normalized.'%'])
                  ->orWhereRaw('LOWER(active_ingredient) LIKE ?', ['%'.$normalized.'%']);
            });
        }

        $drugs = $query->orderBy('brand_name')->limit(50)->get([
            'id', 'brand_name', 'active_ingredient', 'strength', 'form', 'unit', 'general_warning',
        ]);

        return response()->json($drugs);
    }

    public function show(Drug $drug): JsonResponse
    {
        return response()->json($drug);
    }

    private function normalize(string $text): string
    {
        $map = [
            'á' => 'a', 'à' => 'a', 'ả' => 'a', 'ã' => 'a', 'ạ' => 'a',
            'ă' => 'a', 'ắ' => 'a', 'ằ' => 'a', 'ẳ' => 'a', 'ẵ' => 'a', 'ặ' => 'a',
            'â' => 'a', 'ấ' => 'a', 'ầ' => 'a', 'ẩ' => 'a', 'ẫ' => 'a', 'ậ' => 'a',
            'đ' => 'd',
            'é' => 'e', 'è' => 'e', 'ẻ' => 'e', 'ẽ' => 'e', 'ẹ' => 'e',
            'ê' => 'e', 'ế' => 'e', 'ề' => 'e', 'ể' => 'e', 'ễ' => 'e', 'ệ' => 'e',
            'í' => 'i', 'ì' => 'i', 'ỉ' => 'i', 'ĩ' => 'i', 'ị' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ỏ' => 'o', 'õ' => 'o', 'ọ' => 'o',
            'ô' => 'o', 'ố' => 'o', 'ồ' => 'o', 'ổ' => 'o', 'ỗ' => 'o', 'ộ' => 'o',
            'ơ' => 'o', 'ớ' => 'o', 'ờ' => 'o', 'ở' => 'o', 'ỡ' => 'o', 'ợ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ủ' => 'u', 'ũ' => 'u', 'ụ' => 'u',
            'ư' => 'u', 'ứ' => 'u', 'ừ' => 'u', 'ử' => 'u', 'ữ' => 'u', 'ự' => 'u',
            'ý' => 'y', 'ỳ' => 'y', 'ỷ' => 'y', 'ỹ' => 'y', 'ỵ' => 'y',
        ];
        return strtr(strtolower($text), $map);
    }
}
