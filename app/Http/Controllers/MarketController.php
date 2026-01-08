<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\Market;
use Illuminate\Support\Facades\Validator;

class MarketController extends Controller
{
    public function index()
    {
        $markets = Market::with('region')->get();

        return response()->json([
            'success' => true,
            'data' => $markets
        ], 200);
    }

    public function show($market_id)
    {
        $market = Market::with(['region', 'agents'])->find($market_id);

        if (!$market) {
            return response()->json(['success' => false,'message' => 'Market not found'], 404);
        }

        return response()->json(['success' => true,'data' => $market], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'region_id' => 'required|exists:regions,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:markets',
            'address' => 'nullable|string',
            'lga' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $market = Market::create($request->all());
        $market->load('region');

        return response()->json([
            'success' => true,
            'message' => 'Market created successfully',
            'data' => $market
        ], 201);
    }

    public function update(Request $request, $market_id)
    {
        $market = Market::find($market_id);

        if (!$market) {
            return response()->json(['success' => false,'message' => 'Market not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'region_id' => 'required|exists:regions,id',
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:markets,code,' . $id,
            'address' => 'nullable|string',
            'lga' => 'nullable|string|max:255',
            'state' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $market->update($request->all());
        $market->load('region');

        return response()->json([
            'success' => true,
            'message' => 'Market updated successfully',
            'data' => $market
        ], 200);
    }

}
