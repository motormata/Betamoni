<?php

namespace App\Http\Controllers\Api;

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

        // --- Role-Based Scoping ---
        $user = auth()->user();

        // 1. Supervisor: Only see borrowers in their assigned market
        if ($user->isSupervisor()) {
            $query->where('market_id', $user->market_id);
        }

        // 2. Agent: Only see their own registered borrowers (default behavior updated)
        if ($user->isAgent()) {
            $query->where('registered_by', $user->id);
        }

        // --- Optional Custom Filters (from request) ---
        // Filter by market (Admins can filter any market, Supervisors can only filter their own)
        if ($request->has('market_id')) {
            $requestedMarket = $request->market_id;
            
            if ($user->isSupervisor() && $requestedMarket !== $user->market_id) {
                // If supervisor tries to look at another market, force back to their own
                $query->where('market_id', $user->market_id);
            } else {
                $query->where('market_id', $requestedMarket);
            }
        }

        // Filter by agent 
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

        $borrowers = $query->latest()->paginate($request->per_page ?? 15);

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

        // Agents may only edit borrowers they personally registered.
        if (auth()->user()->isAgent() && $borrower->registered_by !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update this borrower'
            ], 403);
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

        // Explicitly whitelist every updatable field.
        // registered_by, id, created_at, etc. are intentionally excluded
        // so they can never be overwritten via this endpoint.
        $borrower->update($request->only([
            'first_name',
            'last_name',
            'phone',
            'alternate_phone',
            'email',
            'bvn',
            'gender',
            'date_of_birth',
            'home_address',
            'business_address',
            'lga',
            'state',
            'business_type',
            'business_description',
            'id_type',
            'id_number',
            'next_of_kin_name',
            'next_of_kin_phone',
            'next_of_kin_relationship',
            'next_of_kin_address',
            'market_id',
            'shop_number',
            'is_active',
        ]));
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

        // Agents may only delete borrowers they personally registered.
        if (auth()->user()->isAgent() && $borrower->registered_by !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete this borrower'
            ], 403);
        }

        $borrower->delete();

        return response()->json([
            'success' => true,
            'message' => 'Borrower deleted successfully'
        ], 200);
    }
}