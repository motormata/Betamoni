<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;


use App\Http\Controllers\Controller;
use App\Models\Region;
use Illuminate\Support\Facades\Validator;

class RegionController extends Controller
{
    public function fetchAll()
    {
        $regions = Region::withCount('markets')->get();

        return response()->json([
            'success' => true,
            'data' => $regions
        ], 200);
    }

    public function fetchById($region_id)
    {
        $region = Region::with('markets')->find($region_id);

        if (!$region) {
            return response()->json(['success' => false,'message' => 'Region not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $region
        ], 200);
    }

    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:regions',
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $region = Region::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Region created successfully',
            'data' => $region
        ], 201);
    }

    public function update(Request $request, $region_id)
    {
        $region = Region::find($region_id);

        if (!$region) {
            return response()->json([
                'success' => false,
                'message' => 'Region not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:regions,code,' . $id,
            'description' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $region->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Region updated successfully',
            'data' => $region
        ], 200);
    }

}

