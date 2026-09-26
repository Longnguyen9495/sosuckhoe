<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ContentArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArticleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Môi trường thật chỉ trả bài đã duyệt; môi trường phát triển trả cả bản nháp (có cờ is_draft để hiện nhãn).
        $articles = ContentArticle::query()
            ->when(app()->isProduction(), fn ($q) => $q->where('is_draft', false))
            ->when($request->filled('type'), fn ($q) => $q->where('type', 'like', $request->string('type').'%'))
            ->orderBy('type')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $articles->map(fn (ContentArticle $a) => [
                'id' => $a->getKey(),
                'type' => $a->type,
                'title' => $a->title,
                'content' => $a->content,
                'is_draft' => (bool) $a->is_draft,
                'created_at' => $a->created_at,
            ]),
        ]);
    }

    public function show(Request $request, ContentArticle $article): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => $article->getKey(),
                'type' => $article->type,
                'title' => $article->title,
                'content' => $article->content,
                'created_at' => $article->created_at,
            ],
        ]);
    }
}
