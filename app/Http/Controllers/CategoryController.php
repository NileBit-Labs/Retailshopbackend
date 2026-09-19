<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');

        return response()->json(Category::query()
            ->where('shop_id', $shop->id)
            ->withCount('products')
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $category = Category::create($data + ['shop_id' => $shop->id]);

        return response()->json($category, 201);
    }

    public function update(Request $request, int $category): JsonResponse
    {
        $shop = $request->attributes->get('shop');
        $model = Category::query()->where('shop_id', $shop->id)->findOrFail($category);
        $model->update($request->validate(['name' => ['required', 'string', 'max:100']]));

        return response()->json($model);
    }
}
