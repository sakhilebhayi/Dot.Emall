<?php

namespace App\Http\Controllers;

use App\Services\Assistant\ShoppingAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** The shopping assistant. Public, rate-limited per person and per address, never given account data. */
class AssistantController extends Controller
{
    public function __invoke(Request $request, ShoppingAssistant $assistant): JsonResponse
    {
        // Validated by hand: the app renders JSON errors only under /api, and this endpoint is called by script.
        $validator = Validator::make($request->all(), ['text' => ['required', 'string', 'max:'.config('discovery.assistant.max_text_length', 400)]]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $answer = $assistant->ask($validator->validated()['text'])->toArray();

        foreach ($answer['catalogue']['items'] as &$item) {
            $item['url'] = route('products.show', $item['product_id']);
        }

        return response()->json($answer)->header('Cache-Control', 'private, no-store');
    }
}
