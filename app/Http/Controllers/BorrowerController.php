<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Borrower;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class BorrowerController extends Controller
{
    public function index(Request $request)
    {
        $query = Borrower::with(['market.region', 'registeredBy']);

        // Filter by market
        if ($request->has('market_id')) {
            $query->where('market_id', $request->market_id);
        }

        // Filter by agent (for agents to see their registered borrowers)
        if ($request->has('registered_by')) {
            $query->where('registered_by', $request->registered_by);
        }

        // Search by name or phone
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $borrowers = $query->paginate($request->per_page ?? 15);

        return response()->json([
            'success' => true,
            'data' => $borrowers
        ], 200);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|unique:borrowers',
            'alternate_phone' => 'nullable|string',
            'email' => 'nullable|email',
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'home_address' => 'required|string',
            'business_address' => 'nullable|string',
            'lga' => 'nullable|string',
            'state' => 'nullable|string',
            'business_type' => 'nullable|string',
            'business_description' => 'nullable|string',
            'id_type' => 'nullable|string',
            'id_number' => 'nullable|string',
            'next_of_kin_name' => 'nullable|string',
            'next_of_kin_phone' => 'nullable|string',
            'next_of_kin_relationship' => 'nullable|string',
            'next_of_kin_address' => 'nullable|string',
            'market_id' => 'required|exists:markets,id',
            'shop_number' => 'nullable|string',
            'photo' => 'nullable|image|max:2048',
            'id_card' => 'nullable|image|max:2048',
            'business_photo' => 'nullable|image|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->except(['photo', 'id_card', 'business_photo']);
        $data['registered_by'] = auth()->id();

        // // Handle file uploads - AWS S3 bucket config
        // if ($request->hasFile('photo')) {
        //     $data['photo_path'] = $request->file('photo')->store('borrowers/photos', 'public');
        // }
        // if ($request->hasFile('id_card')) {
        //     $data['id_card_path'] = $request->file('id_card')->store('borrowers/ids', 'public');
        // }
        // if ($request->hasFile('business_photo')) {
        //     $data['business_photo_path'] = $request->file('business_photo')->store('borrowers/business', 'public');
        // }

        $borrower = Borrower::create($data);
        $borrower->load(['market.region', 'registeredBy']);

        return response()->json([
            'success' => true,
            'message' => 'Borrower registered successfully',
            'data' => $borrower
        ], 201);
    }

    public function show($id)
    {
        $borrower = Borrower::with(['market.region', 'registeredBy', 'loans.agent', 'activeLoans'])
            ->find($id);

        if (!$borrower) {
            return response()->json([
                'success' => false,
                'message' => 'Borrower not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $borrower
        ], 200);
    }

    public function update(Request $request, $id)
    {
        $borrower = Borrower::find($id);

        if (!$borrower) {
            return response()->json([
                'success' => false,
                'message' => 'Borrower not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|unique:borrowers,phone,' . $id,
            'alternate_phone' => 'nullable|string',
            'email' => 'nullable|email',
            'bvn' => 'nullable|string|unique:borrowers,bvn,' . $id,
            'gender' => 'nullable|in:male,female',
            'date_of_birth' => 'nullable|date',
            'home_address' => 'required|string',
            'business_address' => 'nullable|string',
            'lga' => 'nullable|string',
            'state' => 'nullable|string',
            'business_type' => 'nullable|string',
            'business_description' => 'nullable|string',
            'id_type' => 'nullable|string',
            'id_number' => 'nullable|string',
            'next_of_kin_name' => 'nullable|string',
            'next_of_kin_phone' => 'nullable|string',
            'next_of_kin_relationship' => 'nullable|string',
            'next_of_kin_address' => 'nullable|string',
            'market_id' => 'required|exists:markets,id',
            'shop_number' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $borrower->update($request->all());
        $borrower->load(['market.region', 'registeredBy']);

        return response()->json([
            'success' => true,
            'message' => 'Borrower updated successfully',
            'data' => $borrower
        ], 200);
    }

    public function destroy($id)
    {
        $borrower = Borrower::find($id);

        if (!$borrower) {
            return response()->json([
                'success' => false,
                'message' => 'Borrower not found'
            ], 404);
        }

        $borrower->delete();

        return response()->json([
            'success' => true,
            'message' => 'Borrower deleted successfully'
        ], 200);
    }
}